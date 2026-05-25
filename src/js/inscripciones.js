/**
 * Panel inscripciones: modalidad según torneo activo, autocomplete (api/search_atleta.php, ≥3 caracteres),
 * columnas disponibles / inscritos. Migraciones SQL: 004 (grupo_nombre), 005 (grupo_id).
 */

import { mountPortalPerfilHeader } from './portal_perfil_header.js';

const API_CTX = 'api/inscripciones_context.php';
const API_DISP = 'api/inscripciones_disponibles.php';
const API_INSC = 'api/inscripciones_inscritos.php';
const API_POST = 'api/inscripciones_inscribir.php';
const API_SEARCH = 'api/search_atleta.php';

/** @type {Record<number, number>} */
const acTimers = {};

/** @type {{ rol: string } | null} */
let authCtx = null;

/** @type {{ modalidad: string | null, torneo: object | null, movimiento_torneo_bloqueado?: boolean, movimiento_bloqueo_motivo?: string } | null} */
let torneoCtx = null;

function esc(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function asociacionQueryParam() {
    if (!authCtx || authCtx.rol !== 'admingral') return '';
    const inp = document.getElementById('ins-asociacion-id');
    const v = inp && inp.value.trim() ? parseInt(inp.value, 10) : 0;
    return v > 0 ? `&asociacion_id=${encodeURIComponent(String(v))}` : '';
}

function showFormMsg(text, ok) {
    const el = document.getElementById('ins-form-msg');
    if (!el) return;
    el.textContent = text;
    el.style.display = 'block';
    el.className = 'form-msg ' + (ok ? 'ok' : 'err');
}

function hideFormMsg() {
    const el = document.getElementById('ins-form-msg');
    if (el) el.style.display = 'none';
}

function numLineas(modalidad) {
    if (modalidad === 'individual') return 1;
    if (modalidad === 'parejas') return 2;
    if (modalidad === 'equipos') return 4;
    return 0;
}

function mountForm(modalidad) {
    const zone = document.getElementById('ins-form-zone');
    if (!zone) return;
    hideFormMsg();
    const bloqueado = !!(torneoCtx && torneoCtx.movimiento_torneo_bloqueado);
    if (bloqueado) {
        const mot = torneoCtx && torneoCtx.movimiento_bloqueo_motivo ? esc(torneoCtx.movimiento_bloqueo_motivo) : '';
        zone.innerHTML = `<p class="inscripciones-warn"><strong>Nómina cerrada.</strong> ${mot || 'No se pueden nuevas inscripciones ni cambios en movimiento de torneo.'}</p>`;
        return;
    }
    if (!modalidad) {
        zone.innerHTML = '<p class="inscripciones-warn">No hay torneo activo o la modalidad (clase) no está definida.</p>';
        return;
    }
    const n = numLineas(modalidad);
    const modLabel =
        modalidad === 'individual' ? 'Individual' : modalidad === 'parejas' ? 'Parejas' : modalidad === 'equipos' ? 'Equipos' : esc(modalidad);
    let dynDesc = '';
    if (modalidad === 'individual') {
        dynDesc =
            '<p class="ins-dyn-desc">Una fila de búsqueda predictiva para el integrante.</p>';
    } else if (modalidad === 'parejas') {
        dynDesc =
            '<p class="ins-dyn-desc">Dos filas de búsqueda (atleta 1 y 2) y campo <strong>Nombre de pareja</strong>.</p>';
    } else if (modalidad === 'equipos') {
        dynDesc =
            '<p class="ins-dyn-desc">Cuatro filas de búsqueda (integrantes) y campo <strong>Nombre del equipo</strong>.</p>';
    }
    const dynHead =
        '<div class="ins-dyn-head" role="status">' +
        `<p class="ins-dyn-head-title">Modalidad: <strong>${esc(modLabel)}</strong></p>` +
        dynDesc +
        '</div>';

    const lines = [];
    for (let i = 0; i < n; i++) {
        lines.push(
            `<div class="ins-ac-wrap">
                <label class="inscripciones-line">
                    <span>Atleta ${i + 1}</span>
                    <input type="search" class="ins-line-input" data-ins-idx="${i}" id="ins-line-${i}" placeholder="Mín. 3 caracteres; elija de la lista" autocomplete="off" autocorrect="off" spellcheck="false">
                    <input type="hidden" id="ins-line-uid-${i}" value="">
                    <ul class="ins-ac-list" id="ins-ac-${i}" hidden role="listbox" aria-label="Coincidencias"></ul>
                </label>
            </div>`
        );
    }
    let extra = '';
    if (modalidad === 'parejas') {
        extra =
            '<label class="inscripciones-line inscripciones-line--full">' +
            '<span>Nombre de pareja</span>' +
            '<input type="text" id="ins-grupo" maxlength="255" placeholder="Nombre de la pareja (grupo)" autocomplete="off">' +
            '</label>';
    } else if (modalidad === 'equipos') {
        extra =
            '<label class="inscripciones-line inscripciones-line--full">' +
            '<span>Nombre del equipo</span>' +
            '<input type="text" id="ins-grupo" maxlength="255" placeholder="Nombre del equipo" autocomplete="off">' +
            '</label>';
    }
    zone.innerHTML =
        dynHead +
        '<div class="inscripciones-lines">' +
        lines.join('') +
        extra +
        '<div class="inscripciones-actions">' +
        '<button type="button" class="btn-primary" id="ins-btn-inscribir">Inscribir</button>' +
        '</div></div>';

    document.getElementById('ins-btn-inscribir')?.addEventListener('click', onInscribir);
    wireAutocomplete(modalidad);
}

/**
 * @param {string | null} modalidad
 */
function wireAutocomplete(modalidad) {
    const n = numLineas(modalidad || '');
    for (let i = 0; i < n; i++) {
        const input = document.getElementById(`ins-line-${i}`);
        const hid = document.getElementById(`ins-line-uid-${i}`);
        const list = document.getElementById(`ins-ac-${i}`);
        if (!input || !hid || !list) continue;

        list.addEventListener('mousedown', (e) => {
            const li = e.target.closest('li[data-user-id]');
            if (li) e.preventDefault();
        });
        list.addEventListener('click', (e) => {
            const li = e.target.closest('li[data-user-id]');
            if (!li || !input || !hid) return;
            hid.value = li.getAttribute('data-user-id') || '';
            const pr = li.querySelector('.ins-ac-primary');
            input.value = pr ? pr.textContent.trim() : '';
            list.hidden = true;
            list.innerHTML = '';
        });

        input.addEventListener('input', () => {
            hid.value = '';
            const term = input.value.trim();
            window.clearTimeout(acTimers[i]);
            if (term.length < 3) {
                list.hidden = true;
                list.innerHTML = '';
                return;
            }
            acTimers[i] = window.setTimeout(() => {
                void fetchAutocomplete(i, term);
            }, 220);
        });

        input.addEventListener('blur', () => {
            window.setTimeout(() => {
                list.hidden = true;
            }, 180);
        });
    }
}

/**
 * @param {number} lineIdx
 * @param {string} term
 */
async function fetchAutocomplete(lineIdx, term) {
    const q = asociacionQueryParam();
    if (authCtx && authCtx.rol === 'admingral' && !q) return;
    const list = document.getElementById(`ins-ac-${lineIdx}`);
    const input = document.getElementById(`ins-line-${lineIdx}`);
    if (!list || !input) return;
    if (input.value.trim() !== term) return;
    try {
        const res = await fetch(`${API_SEARCH}?q=${encodeURIComponent(term)}${q}`, { credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok || !Array.isArray(data.items)) {
            list.innerHTML = '';
            list.hidden = true;
            return;
        }
        if (input.value.trim() !== term) return;
        if (data.items.length === 0) {
            list.innerHTML = '<li class="ins-ac-empty">Sin coincidencias</li>';
            list.hidden = false;
            return;
        }
        list.innerHTML = data.items
            .map((it) => {
                const id = String(it.id ?? '');
                const nom = esc(it.nombre);
                const meta = `${esc(it.cedula)} · FVD ${esc(it.numfvd)} · ${sexoLabel(it.sexo)}`;
                return `<li class="ins-ac-item" role="option" data-user-id="${id}"><span class="ins-ac-primary">${nom}</span><span class="ins-ac-meta">${meta}</span></li>`;
            })
            .join('');
        list.hidden = false;
    } catch (e) {
        console.error(e);
        list.innerHTML = '';
        list.hidden = true;
    }
}

function firstSearchInput() {
    return document.getElementById('ins-line-0');
}

function collectLineas() {
    const modalidad = torneoCtx && torneoCtx.modalidad;
    const n = numLineas(modalidad || '');
    const lineas = [];
    for (let i = 0; i < n; i++) {
        const inp = document.getElementById(`ins-line-${i}`);
        lineas.push(inp ? inp.value.trim() : '');
    }
    return lineas;
}

/**
 * @returns {{ usuario_ids: number[] } | { lineas: string[] }}
 */
function collectInscripcionPayload() {
    const modalidad = torneoCtx && torneoCtx.modalidad;
    const n = numLineas(modalidad || '');
    const ids = [];
    for (let i = 0; i < n; i++) {
        const hid = document.getElementById(`ins-line-uid-${i}`);
        const v = hid && hid.value.trim();
        if (!v) {
            return { lineas: collectLineas() };
        }
        const id = parseInt(v, 10);
        if (!id) {
            return { lineas: collectLineas() };
        }
        ids.push(id);
    }
    if (ids.length === n && new Set(ids).size === ids.length) {
        return { usuario_ids: ids };
    }
    return { lineas: collectLineas() };
}

function clearForm() {
    const modalidad = torneoCtx && torneoCtx.modalidad;
    const n = numLineas(modalidad || '');
    for (let i = 0; i < n; i++) {
        const inp = document.getElementById(`ins-line-${i}`);
        if (inp) inp.value = '';
        const hid = document.getElementById(`ins-line-uid-${i}`);
        if (hid) hid.value = '';
        const list = document.getElementById(`ins-ac-${i}`);
        if (list) {
            list.innerHTML = '';
            list.hidden = true;
        }
    }
    const g = document.getElementById('ins-grupo');
    if (g) g.value = '';
}

async function onInscribir() {
    const modalidad = torneoCtx && torneoCtx.modalidad;
    if (!modalidad) return;
    const q = asociacionQueryParam();
    if (authCtx && authCtx.rol === 'admingral' && !q) {
        showFormMsg('Indique el ID de asociación y pulse «Cargar listas».', false);
        return;
    }
    const grupoEl = document.getElementById('ins-grupo');
    const grupoNombre =
        modalidad === 'parejas' || modalidad === 'equipos'
            ? grupoEl && grupoEl.value.trim()
                ? grupoEl.value.trim()
                : null
            : null;

    const payload = collectInscripcionPayload();
    const body = 'usuario_ids' in payload ? { usuario_ids: payload.usuario_ids } : { lineas: payload.lineas };
    if (grupoNombre) body.grupo_nombre = grupoNombre;
    if (authCtx && authCtx.rol === 'admingral') {
        const inp = document.getElementById('ins-asociacion-id');
        const aid = inp && inp.value.trim() ? parseInt(inp.value, 10) : 0;
        if (aid > 0) body.asociacion_id = aid;
    }

    hideFormMsg();

    try {
        const res = await fetch(API_POST, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok && data.ok) {
            clearForm();
            showFormMsg(data.message || 'Inscripción registrada.', true);
            const first = firstSearchInput();
            if (first) first.focus();
            await refreshLists();
        } else {
            showFormMsg(data.message || 'No se pudo inscribir.', false);
        }
    } catch (e) {
        console.error(e);
        showFormMsg('Error de conexión.', false);
    }
}

/** @param {object[]} items */
function renderDisponibles(items) {
    const ul = document.getElementById('ins-list-disponibles');
    if (!ul) return;
    ul.dataset.raw = JSON.stringify(items);
    applyListFilter();
}

/** @param {object[]} items */
function renderInscritos(items) {
    const ul = document.getElementById('ins-list-inscritos');
    if (!ul) return;
    ul.dataset.raw = JSON.stringify(items);
    applyListFilter();
}

function sexoLabel(s) {
    const n = parseInt(String(s), 10);
    if (n === 1) return 'M';
    if (n === 2) return 'F';
    return '—';
}

/** Badges AF / AN / CA según movimiento_torneo; TP si traspaso. */
function movimientoBadges(it) {
    const parts = [];
    if (Number(it.afiliacion) >= 1) parts.push('<span class="ins-badge">AF</span>');
    if (Number(it.anualidad) >= 1) parts.push('<span class="ins-badge">AN</span>');
    if (Number(it.carnet) >= 1) parts.push('<span class="ins-badge">CA</span>');
    if (Number(it.traspaso) >= 1) parts.push('<span class="ins-badge ins-badge--tp">TP</span>');
    if (parts.length === 0) return '';
    return `<span class="ins-badges" aria-label="Movimiento">${parts.join('')}</span>`;
}

function applyListFilter() {
    const f = document.getElementById('ins-filter-lists');
    const q = (f && f.value.trim().toLowerCase()) || '';
    ['ins-list-disponibles', 'ins-list-inscritos'].forEach((id) => {
        const ul = document.getElementById(id);
        if (!ul || !ul.dataset.raw) {
            if (ul) ul.innerHTML = '';
            return;
        }
        let items;
        try {
            items = JSON.parse(ul.dataset.raw);
        } catch {
            return;
        }
        if (!Array.isArray(items)) return;
        const filtered = q
            ? items.filter((it) => {
                  const blob = Object.values(it)
                      .map((v) => String(v ?? '').toLowerCase())
                      .join(' ');
                  return blob.includes(q);
              })
            : items;
        if (id === 'ins-list-disponibles') {
            ul.innerHTML = filtered
                .map(
                    (it) =>
                        `<li class="inscripciones-li"><strong>${esc(it.nombre)}</strong>
                        <span class="inscripciones-li-meta">${esc(it.cedula)} · FVD ${esc(it.numfvd)} · ${sexoLabel(it.sexo)}${it.email ? ' · ' + esc(it.email) : ''}</span></li>`
                )
                .join('');
        } else {
            ul.innerHTML = filtered
                .map((it) => {
                    const gid = it.grupo_id != null && String(it.grupo_id) !== '' ? ` · Grupo #${esc(it.grupo_id)}` : '';
                    const gn = it.grupo_nombre ? ` · ${esc(it.grupo_nombre)}` : '';
                    return `<li class="inscripciones-li"><span class="inscripciones-li-title"><strong>${esc(it.nombre_usuario)}</strong>${movimientoBadges(
                        it
                    )}</span>
                        <span class="inscripciones-li-meta">${esc(it.cedula)} · FVD ${esc(it.numfvd)}${gid}${gn}</span></li>`;
                })
                .join('');
        }
    });
}

async function refreshLists() {
    const q = asociacionQueryParam();
    if (authCtx && authCtx.rol === 'admingral' && !q) {
        renderDisponibles([]);
        renderInscritos([]);
        return;
    }
    try {
        const [r1, r2] = await Promise.all([
            fetch(`${API_DISP}?x=1${q}`, { credentials: 'same-origin' }),
            fetch(`${API_INSC}?x=1${q}`, { credentials: 'same-origin' }),
        ]);
        const d1 = await r1.json().catch(() => ({}));
        const d2 = await r2.json().catch(() => ({}));
        if (r1.ok && d1.ok && Array.isArray(d1.items)) renderDisponibles(d1.items);
        else renderDisponibles([]);
        if (r2.ok && d2.ok && Array.isArray(d2.items)) renderInscritos(d2.items);
        else renderInscritos([]);
    } catch (e) {
        console.error(e);
        renderDisponibles([]);
        renderInscritos([]);
    }
}

async function loadTorneoContext() {
    const meta = document.getElementById('ins-meta');
    try {
        const res = await fetch(API_CTX, { credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
            torneoCtx = {
                modalidad: null,
                torneo: null,
                movimiento_torneo_bloqueado: false,
                movimiento_bloqueo_motivo: '',
            };
            if (meta) meta.textContent = 'No se pudo cargar el contexto del torneo.';
            document.getElementById('ins-finance')?.classList.add('is-hidden');
            mountForm(null);
            return;
        }
        torneoCtx = {
            modalidad: data.modalidad || null,
            torneo: data.torneo || null,
            movimiento_torneo_bloqueado: !!data.movimiento_torneo_bloqueado,
            movimiento_bloqueo_motivo: data.movimiento_bloqueo_motivo || '',
        };
        const t = data.torneo;
        if (meta) {
            if (!t) {
                meta.textContent = 'No hay torneo activo (sin fecha de cierre).';
            } else {
                const modLabels = { individual: 'Individual', parejas: 'Parejas', equipos: 'Equipos' };
                const ml = data.modalidad ? modLabels[data.modalidad] || data.modalidad : '—';
                meta.textContent = `${t.nombre || 'Torneo'} · Modalidad: ${ml} · Género torneo: ${data.tipo_torneo_label || '—'}`;
            }
        }
        const finEl = document.getElementById('ins-finance');
        if (finEl) {
            if (authCtx && authCtx.rol === 'delegado' && data.finanzas) {
                finEl.classList.remove('is-hidden');
                const f = data.finanzas;
                const bs = f.total_bs != null ? `${Number(f.total_bs).toFixed(2)} Bs` : '—';
                const eur = f.total_eur != null ? `${Number(f.total_eur).toFixed(2)} €` : '—';
                finEl.innerHTML = `<strong>Total estimado (sus inscritos)</strong> · ${f.inscritos} inscrito(s) · costo torneo: ${
                    f.costo_bs != null ? `${Number(f.costo_bs).toFixed(2)} Bs` : '—'
                } c/u · tasa referencia €: ${Number(f.tasa_eur_bs).toFixed(2)} Bs/€ <span class="ins-fin-sep">|</span> <strong>${bs}</strong> <span class="ins-fin-sep">|</span> <strong>${eur}</strong> <small class="ins-fin-hint">(configure <code>FVD_TASA_EUR_BS</code> en el servidor si aplica)</small>`;
            } else {
                finEl.classList.add('is-hidden');
                finEl.innerHTML = '';
            }
        }
        mountForm(torneoCtx.modalidad);
        await refreshLists();
        const first = firstSearchInput();
        if (first) first.focus();
    } catch (e) {
        console.error(e);
        if (meta) meta.textContent = 'Error de conexión al cargar el torneo.';
        document.getElementById('ins-finance')?.classList.add('is-hidden');
        mountForm(null);
    }
}

async function verificarAcceso() {
    const gate = document.getElementById('ins-gate');
    const root = document.getElementById('ins-root');
    try {
        const res = await fetch('api/auth_context.php', { credentials: 'same-origin' });
        const ctx = await res.json().catch(() => ({}));
        if (!res.ok || !ctx.logged || !ctx.puede_panel_admin) {
            if (gate) {
                gate.classList.remove('is-hidden');
                gate.innerHTML =
                    '<p class="error">Debe iniciar sesión como delegado o administración general para acceder a inscripciones.</p><p><a href="index.php" class="btn-secondary">Inicio</a></p>';
            }
            if (root) root.classList.add('is-hidden');
            return false;
        }
        authCtx = { rol: ctx.rol || '' };
        const adminWrap = document.getElementById('ins-admin-asoc');
        if (adminWrap) adminWrap.classList.toggle('is-hidden', ctx.rol !== 'admingral');
        if (gate) gate.classList.add('is-hidden');
        if (root) root.classList.remove('is-hidden');
        mountPortalPerfilHeader(document.getElementById('ins-main-nav'));
        return true;
    } catch (e) {
        console.error(e);
        if (gate) {
            gate.classList.remove('is-hidden');
            gate.innerHTML = '<p class="error">No se pudo validar el acceso.</p>';
        }
        return false;
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    const ok = await verificarAcceso();
    if (!ok) return;

    document.getElementById('ins-filter-lists')?.addEventListener('input', () => applyListFilter());

    document.getElementById('ins-asoc-apply')?.addEventListener('click', async () => {
        await loadTorneoContext();
    });

    await loadTorneoContext();
});
