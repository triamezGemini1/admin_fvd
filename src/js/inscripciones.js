/**
 * Panel inscripciones: modalidad según torneo activo, autocomplete (api/search_atleta.php, ≥3 caracteres),
 * columnas disponibles / inscritos. Migraciones SQL: 004 (grupo_nombre), 005 (grupo_id).
 */

import { mountPortalPerfilHeader } from './portal_perfil_header.js';
import {
    initDelegadoTorneosBar,
    getAsociacionJornada,
    persistJornadaDesdeAuth,
    getDelegadoTorneoIdSeleccionado,
    permiteSelectorCampeonatoJornada,
} from './delegado_torneos_bar.js';

const API_CTX = 'api/inscripciones_context.php';
const API_DISP = 'api/inscripciones_disponibles.php';
const API_INSC = 'api/inscripciones_inscritos.php';
const API_POST = 'api/inscripciones_inscribir.php';
const API_RETIRAR = 'api/inscripciones_retirar.php';
const API_SEARCH = 'api/search_atleta.php';
const API_BUSCAR_CEDULA = 'api/inscripciones_buscar_cedula.php';
const API_REPORTE = 'api/inscripciones_reporte.php';
const API_REPORTE_DISP = 'api/inscripciones_reporte_disponibles.php';
const API_ACTUALIZAR_ATLETA = 'api/inscripciones_actualizar_atleta.php';

/** Orden fijo de secciones del listado de disponibles (debe coincidir con PHP). */
const ORDEN_GRUPOS_DISP = [
    { clave: 'listo', label: 'Listos para inscribir', orden: 1 },
    { clave: 'pendiente_carnet', label: 'Pendiente carnet', orden: 2 },
    { clave: 'pendiente_anualidad', label: 'Pendiente anualidad', orden: 3 },
    { clave: 'pendiente_afiliacion', label: 'Pendiente afiliación', orden: 4 },
    { clave: 'sin_movimiento', label: 'Sin movimiento en este torneo', orden: 5 },
];

/** @type {Record<number, number>} */
const acTimers = {};

/** @type {{ rol: string, asociacion_id: number, asociacion?: object } | null} */
let authCtx = null;

/** @type {{ modalidad: string | null, torneo: object | null, movimiento_torneo_bloqueado?: boolean, movimiento_bloqueo_motivo?: string } | null} */
let torneoCtx = null;

/** @type {{ total: number, hombres: number, mujeres: number } | null} */
let lastStats = null;

function esc(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/** @returns {number} */
function asociacionIdActiva() {
    return torneoCtx && torneoCtx.asociacionId ? parseInt(String(torneoCtx.asociacionId), 10) : 0;
}

/**
 * Título: «Inscripciones · [nombre asociación]» en una sola línea.
 *
 * @param {{ id?: number, nombre?: string } | null} asoc
 */
function renderTituloPagina(asoc) {
    const el = document.getElementById('ins-title-asoc');
    if (!el) return;
    const a = asoc && asoc.nombre ? asoc : getAsociacionJornada();
    if (!a || !a.nombre) {
        el.textContent = '';
        renderAsocCodeBadge(null);
        return;
    }
    el.textContent = '· ' + String(a.nombre);
    renderAsocCodeBadge(a);
}

/**
 * Badge con código id de la asociación (junto a estadísticas de inscritos).
 *
 * @param {{ codigo?: number, id?: number, numreg?: string } | null} asoc
 */
function renderAsocCodeBadge(asoc) {
    const el = document.getElementById('ins-asoc-code-badge');
    if (!el) return;
    const cod =
        asoc && (asoc.codigo != null || asoc.id != null)
            ? parseInt(String(asoc.codigo ?? asoc.id), 10)
            : asociacionIdActiva();
    if (!cod || cod < 1) {
        el.classList.add('is-hidden');
        return;
    }
    const nr = asoc && asoc.numreg ? String(asoc.numreg).trim() : '';
    el.textContent = nr !== '' ? `Cód. ${cod} · ${nr}` : `Asoc. ${cod}`;
    el.classList.remove('is-hidden');
}

/** Torneo de jornada (activo o variante de campeonato); no otros torneos abiertos. */
function torneoIdJornada() {
    const ctx = torneoIdActivo();
    const bar = getDelegadoTorneoIdSeleccionado();
    return ctx > 0 ? ctx : bar;
}

function torneoQueryParam() {
    const tid = torneoIdJornada();
    return tid > 0 ? `&torneo_id=${encodeURIComponent(String(tid))}` : '';
}

/** Las inscripciones operan solo sobre el torneo activo; ?torneo_id en URL no aplica salvo recarga interna. */
function normalizarUrlInscripciones() {
    try {
        const u = new URL(window.location.href);
        if (!u.searchParams.has('torneo_id')) return;
        u.searchParams.delete('torneo_id');
        history.replaceState(null, '', u.toString());
    } catch (_) {
        /* ignore */
    }
}

function setBusquedaInscribirEnabled(enabled) {
    const btn = document.getElementById('ins-btn-inscribir-busqueda');
    if (btn) btn.disabled = !enabled;
}

function setBusquedaNombreVisible(visible, nombre) {
    const wrap = document.getElementById('wrap_nombre_busqueda');
    const nom = document.getElementById('ins-input-nombre-res');
    const reset = document.getElementById('ins-btn-otra-busqueda');
    if (wrap) wrap.classList.toggle('is-hidden', !visible);
    if (nom && visible) nom.value = nombre != null ? String(nombre) : '';
    if (reset) reset.classList.toggle('is-hidden', !visible);
}

function torneoIdActivo() {
    return torneoCtx && torneoCtx.torneoId ? parseInt(String(torneoCtx.torneoId), 10) : 0;
}

function isNominaBloqueada() {
    return !!(torneoCtx && torneoCtx.movimiento_torneo_bloqueado);
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
    const jr =
        torneoCtx && torneoCtx.jugadores_requeridos != null
            ? parseInt(String(torneoCtx.jugadores_requeridos), 10)
            : 0;
    if (jr > 0) return jr;
    const pc =
        torneoCtx && torneoCtx.torneo && torneoCtx.torneo.pareclub != null
            ? parseInt(String(torneoCtx.torneo.pareclub), 10)
            : 0;
    if (modalidad === 'individual') return 1;
    if (modalidad === 'parejas') return Math.max(2, pc > 0 ? pc : 2);
    if (modalidad === 'equipos') return Math.max(2, pc > 0 ? pc : 4);
    return 0;
}

function mountForm(modalidad) {
    const zone = document.getElementById('ins-form-zone');
    const wrap = document.getElementById('ins-form-zone-wrap');
    if (!zone) return;
    hideFormMsg();
    if (wrap) {
        wrap.classList.toggle('is-hidden', modalidad === 'individual' || !modalidad);
    }
    if (!modalidad || modalidad === 'individual') {
        zone.innerHTML = '';
        return;
    }
    const n = numLineas(modalidad);
    const modLabel =
        modalidad === 'individual' ? 'Individual' : modalidad === 'parejas' ? 'Parejas' : modalidad === 'equipos' ? 'Equipos' : esc(modalidad);
    let dynDesc = '';
    if (modalidad === 'parejas') {
        dynDesc =
            `<p class="ins-dyn-desc">${n} filas de búsqueda y campo <strong>Nombre de pareja</strong> (opcional; si vacío se asigna automático).</p>`;
    } else if (modalidad === 'equipos') {
        dynDesc =
            `<p class="ins-dyn-desc">${n} filas de búsqueda (integrantes) y <strong>Nombre del equipo</strong> (obligatorio).</p>`;
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
            '<span>Nombre del equipo <em class="ins-req">*</em></span>' +
            '<input type="text" id="ins-grupo" maxlength="255" placeholder="Nombre del equipo" required autocomplete="off">' +
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

function resetBusquedaSitio() {
    const hid = document.getElementById('ins-busqueda-user-id');
    const ced = document.getElementById('ins-input-cedula');
    if (hid) hid.value = '';
    setBusquedaNombreVisible(false, '');
    setBusquedaInscribirEnabled(false);
    if (ced) ced.focus();
}

function wireSitioBusqueda() {
    const ced = document.getElementById('ins-input-cedula');
    document.getElementById('ins-btn-inscribir-busqueda')?.addEventListener('click', () => {
        const uid = parseInt(document.getElementById('ins-busqueda-user-id')?.value || '0', 10);
        if (uid > 0) void inscribirUsuarioRapido(uid);
    });
    document.getElementById('ins-btn-otra-busqueda')?.addEventListener('click', resetBusquedaSitio);
    ced?.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            void buscarPorCedula();
        }
    });
    ced?.addEventListener('blur', () => {
        const v = ced.value.trim();
        if (v.length >= 3) void buscarPorCedula();
    });
}

async function buscarPorCedula() {
    if (asociacionIdActiva() < 1) {
        showFormMsg('No hay asociación activa en sesión.', false);
        return;
    }
    const numEl = document.getElementById('ins-input-cedula');
    const nacEl = document.getElementById('ins-nacionalidad');
    const term = numEl ? numEl.value.trim() : '';
    if (term.length < 3) {
        return;
    }
    let cedula = term.replace(/^[VEJP]/i, '');
    const soloDigitos = /^\d+$/.test(cedula);
    if (!soloDigitos && term.length >= 3) {
        const res = await fetch(`${API_SEARCH}?q=${encodeURIComponent(term)}${torneoQueryParam()}`, {
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok && data.ok && Array.isArray(data.items) && data.items.length === 1) {
            cedula = String(data.items[0].cedula || '').replace(/^[VEJP]/i, '');
        } else if (res.ok && data.ok && data.items && data.items.length > 1) {
            showFormMsg('Varias coincidencias: elija el atleta en la tabla de disponibles.', false);
            return;
        }
    }
    if (!cedula || !/^\d+$/.test(cedula)) {
        showFormMsg('Use cédula numérica o un nombre con coincidencia única.', false);
        return;
    }
    const nac = nacEl ? nacEl.value.trim().toUpperCase() : 'V';
    showFormMsg('Buscando ' + nac + cedula + '…', true);
    try {
        const res = await fetch(`${API_BUSCAR_CEDULA}?cedula=${encodeURIComponent(cedula)}${torneoQueryParam()}`, {
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        if (data.ya_inscrito) {
            showFormMsg(data.error || 'Ya está inscrito.', false);
            resetBusquedaSitio();
            return;
        }
        if (!data.encontrado || !data.usuario) {
            showFormMsg(data.error || 'No encontrado.', false);
            resetBusquedaSitio();
            return;
        }
        const u = data.usuario;
        const hid = document.getElementById('ins-busqueda-user-id');
        if (hid) hid.value = String(u.id);
        setBusquedaNombreVisible(true, u.nombre || '');
        setBusquedaInscribirEnabled(true);
        showFormMsg('Encontrado: ' + (u.nombre || '') + (data.categ?.nombre ? ' · ' + data.categ.nombre : ''), true);
    } catch (e) {
        console.error(e);
        showFormMsg('Error de conexión.', false);
    }
}

function renderEstadisticas(stats, nDisponibles) {
    lastStats = stats;
    const cd = document.getElementById('count_disponibles');
    const ci = document.getElementById('count_inscritos');
    if (cd && nDisponibles != null) cd.textContent = String(nDisponibles);
    const total = stats && stats.total != null ? parseInt(String(stats.total), 10) : 0;
    const hombres = stats && stats.hombres != null ? parseInt(String(stats.hombres), 10) : 0;
    const mujeres = stats && stats.mujeres != null ? parseInt(String(stats.mujeres), 10) : 0;
    if (ci) ci.textContent = String(total);
    const st = document.getElementById('ins-stat-total');
    const sh = document.getElementById('ins-stat-hombres');
    const sm = document.getElementById('ins-stat-mujeres');
    if (st) st.textContent = String(total);
    if (sh) sh.textContent = String(hombres);
    if (sm) sm.textContent = String(mujeres);
    const rep = document.getElementById('ins-btn-reporte');
    const repDisp = document.getElementById('ins-btn-reporte-disp');
    const disabled = asociacionIdActiva() < 1;
    if (rep) rep.disabled = disabled;
    if (repDisp) repDisp.disabled = disabled;
}

function abrirReporteInscritos() {
    if (asociacionIdActiva() < 1) {
        showFormMsg('No hay asociación activa.', false);
        return;
    }
    window.open(`${API_REPORTE}?auto_print=1${torneoQueryParam()}`, '_blank', 'noopener');
}

function abrirReporteDisponibles() {
    if (asociacionIdActiva() < 1) {
        showFormMsg('No hay asociación activa.', false);
        return;
    }
    window.open(`${API_REPORTE_DISP}?auto_print=1${torneoQueryParam()}`, '_blank', 'noopener');
}

/**
 * Inscribe un usuario desde la lista de disponibles (solo individual).
 *
 * @param {number} userId
 */
async function inscribirUsuarioRapido(userId) {
    if (torneoCtx?.modalidad && torneoCtx.modalidad !== 'individual') {
        showFormMsg('Use el formulario de parejas o equipos para inscribir varios atletas.', false);
        return;
    }
    if (asociacionIdActiva() < 1) {
        showFormMsg('No hay asociación activa.', false);
        return;
    }
    const body = { usuario_ids: [userId], torneo_id: torneoIdActivo() || getDelegadoTorneoIdSeleccionado() };
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
            showFormMsg(data.message || 'Inscrito.', true);
            await refreshLists();
        } else {
            const msg =
                data.message ||
                (res.status === 403
                    ? 'Sin permiso para inscribir.'
                    : res.status === 400
                      ? 'No se pudo inscribir (revise torneo activo y modalidad).'
                      : 'No se pudo inscribir.');
            showFormMsg(msg, false);
        }
    } catch (e) {
        console.error(e);
        showFormMsg('Error de conexión.', false);
    }
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
    if (asociacionIdActiva() < 1) return;
    const list = document.getElementById(`ins-ac-${lineIdx}`);
    const input = document.getElementById(`ins-line-${lineIdx}`);
    if (!list || !input) return;
    if (input.value.trim() !== term) return;
    try {
        const res = await fetch(`${API_SEARCH}?q=${encodeURIComponent(term)}${torneoQueryParam()}`, {
            credentials: 'same-origin',
        });
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
    return document.getElementById('ins-input-cedula');
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
    if (asociacionIdActiva() < 1) {
        showFormMsg('No hay asociación activa.', false);
        return;
    }
    const nReq = numLineas(modalidad);
    const grupoEl = document.getElementById('ins-grupo');
    const grupoRaw =
        modalidad === 'parejas' || modalidad === 'equipos'
            ? grupoEl && grupoEl.value.trim()
                ? grupoEl.value.trim()
                : ''
            : '';
    if (modalidad === 'equipos' && grupoRaw === '') {
        showFormMsg('Indique el nombre del equipo.', false);
        return;
    }
    const grupoNombre =
        modalidad === 'parejas' || modalidad === 'equipos' ? (grupoRaw !== '' ? grupoRaw : null) : null;

    const payload = collectInscripcionPayload();
    if (!('usuario_ids' in payload) || payload.usuario_ids.length !== nReq) {
        showFormMsg(
            `Seleccione los ${nReq} atleta(s) en las líneas del formulario (autocompletado).`,
            false
        );
        return;
    }
    const body = { usuario_ids: payload.usuario_ids };
    if (grupoNombre) body.grupo_nombre = grupoNombre;
    const tid = torneoIdActivo() || getDelegadoTorneoIdSeleccionado();
    if (tid > 0) body.torneo_id = tid;

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

/**
 * @param {object[] | { items?: object[], grupos?: object[] }} payload
 */
function renderDisponibles(payload) {
    const tbody = document.getElementById('tbody_disponibles');
    if (!tbody) return;
    const data =
        payload && typeof payload === 'object' && !Array.isArray(payload) && Array.isArray(payload.items)
            ? payload
            : { items: Array.isArray(payload) ? payload : [], grupos: [] };
    const grupos =
        Array.isArray(data.grupos) && data.grupos.length > 0
            ? data.grupos
            : ORDEN_GRUPOS_DISP.map((g) => ({ ...g, items: [] }));
    tbody.dataset.raw = JSON.stringify(data.items);
    tbody.dataset.grupos = JSON.stringify(grupos);
    applyListFilter();
}

/** @param {object[]} items */
function renderInscritos(items, stats) {
    const tbody = document.getElementById('tbody_inscritos');
    if (!tbody) return;
    tbody.dataset.raw = JSON.stringify(items);
    applyListFilter();
}

function sexoLabel(s) {
    const n = parseInt(String(s), 10);
    if (n === 1) return 'M';
    if (n === 2) return 'F';
    return '—';
}

function filtraItems(items, q) {
    if (!q) return items;
    return items.filter((it) => {
        const blob = Object.values(it)
            .map((v) => String(v ?? '').toLowerCase())
            .join(' ');
        return blob.includes(q);
    });
}

/**
 * Metadatos de grupos por estatus (API o plantilla local).
 *
 * @param {HTMLTableSectionElement | null} tbodyDisp
 * @returns {{ clave: string, label: string, orden: number }[]}
 */
function metaGruposDisponibles(tbodyDisp) {
    try {
        const g = JSON.parse(tbodyDisp?.dataset.grupos || '[]');
        if (Array.isArray(g) && g.length > 0) {
            return g
                .map((x) => ({
                    clave: String(x.clave || ''),
                    label: String(x.label || x.clave || ''),
                    orden: parseInt(String(x.orden ?? 99), 10),
                }))
                .sort((a, b) => a.orden - b.orden);
        }
    } catch {
        /* ignore */
    }
    return ORDEN_GRUPOS_DISP;
}

/** @param {object} it */
function celdaCedula(it) {
    const c = String(it.cedula ?? '').trim();
    return c !== '' ? esc(c) : '—';
}

/** @param {object} it */
function celdaCarnet(it) {
    const nf = parseInt(String(it.numfvd ?? 0), 10);
    return nf > 0 ? esc(String(nf)) : '—';
}

/** @param {object} it */
function celdaTelefono(it) {
    const t = String(it.celular ?? '').trim();
    return t !== '' ? esc(t) : '—';
}

function filaDisponibleHtml(it) {
    const uid = parseInt(String(it.id), 10);
    return `<tr class="ins-sitio-row ins-sitio-row--disp"
        data-user-id="${uid}" tabindex="0" role="button" title="Clic para inscribir">
        <td class="ins-sitio-td-nombre">${esc(it.nombre)}</td>
        <td class="ins-sitio-td-cedula">${celdaCedula(it)}</td>
        <td class="ins-sitio-td-carnet">${celdaCarnet(it)}</td>
        <td class="ins-sitio-td-tel">${celdaTelefono(it)}</td>
    </tr>`;
}

function filaInscritoHtml(it) {
    const movId = parseInt(String(it.mov_id ?? 0), 10);
    const uid = parseInt(String(it.id_usuario ?? 0), 10);
    const cel = String(it.celular ?? '').trim();
    const nom = String(it.nombre_usuario ?? '').trim();
    return `<tr class="ins-sitio-row ins-sitio-row--insc"
        data-mov-id="${movId}" data-user-id="${uid}"
        data-nombre="${esc(nom)}" data-celular="${esc(cel)}"
        tabindex="0" role="button"
        title="Clic para editar teléfono o retirar">
        <td class="ins-sitio-td-nombre">${esc(nom || '—')}</td>
        <td class="ins-sitio-td-cedula">${celdaCedula(it)}</td>
        <td class="ins-sitio-td-carnet">${celdaCarnet(it)}</td>
        <td class="ins-sitio-td-tel">${celdaTelefono(it)}</td>
    </tr>`;
}

/** @param {object[]} items */
function ordenarDisponiblesPorNombre(items) {
    return [...items].sort((a, b) =>
        String(a.nombre ?? '').localeCompare(String(b.nombre ?? ''), 'es', { sensitivity: 'base' })
    );
}

/** @type {{ movId: number, userId: number, nombre: string, celular: string } | null} */
let insEditCtx = null;

function abrirEditarInscrito(movId, userId, nombre, celular) {
    const dlg = document.getElementById('ins-edit-dialog');
    const nomEl = document.getElementById('ins-edit-nombre');
    const celIn = document.getElementById('ins-edit-celular');
    const msgEl = document.getElementById('ins-edit-msg');
    if (!dlg || !nomEl || !celIn) return;
    insEditCtx = { movId, userId, nombre, celular };
    nomEl.textContent = nombre;
    celIn.value = celular;
    if (msgEl) {
        msgEl.style.display = 'none';
        msgEl.textContent = '';
    }
    if (typeof dlg.showModal === 'function') {
        dlg.showModal();
    }
}

async function guardarTelefonoInscrito() {
    if (!insEditCtx || insEditCtx.userId < 1) return;
    const celIn = document.getElementById('ins-edit-celular');
    const msgEl = document.getElementById('ins-edit-msg');
    const celular = celIn ? celIn.value.trim() : '';
    try {
        const res = await fetch(API_ACTUALIZAR_ATLETA, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ usuario_id: insEditCtx.userId, celular }),
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok && data.ok) {
            document.getElementById('ins-edit-dialog')?.close();
            showFormMsg(data.message || 'Teléfono guardado.', true);
            await refreshLists();
        } else if (msgEl) {
            msgEl.textContent = data.message || 'No se pudo guardar.';
            msgEl.style.display = 'block';
            msgEl.classList.remove('success');
        }
    } catch (e) {
        console.error(e);
        if (msgEl) {
            msgEl.textContent = 'Error de conexión.';
            msgEl.style.display = 'block';
        }
    }
}

function wireFilasSitio(tbody) {
    if (!tbody) return;
    tbody.querySelectorAll('.ins-sitio-row--disp').forEach((tr) => {
        const go = () => {
            if (torneoCtx?.modalidad && torneoCtx.modalidad !== 'individual') {
                showFormMsg('Use el formulario superior (parejas o equipos) para este torneo.', false);
                return;
            }
            const uid = parseInt(tr.getAttribute('data-user-id') || '0', 10);
            if (uid < 1) return;
            void inscribirUsuarioRapido(uid);
        };
        tr.addEventListener('click', go);
        tr.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                go();
            }
        });
    });
    tbody.querySelectorAll('.ins-sitio-row--insc').forEach((tr) => {
        const go = () => {
            const movId = parseInt(tr.getAttribute('data-mov-id') || '0', 10);
            const userId = parseInt(tr.getAttribute('data-user-id') || '0', 10);
            const nombre = tr.getAttribute('data-nombre') || '';
            const celular = tr.getAttribute('data-celular') || '';
            if (userId < 1) return;
            abrirEditarInscrito(movId, userId, nombre, celular);
        };
        tr.addEventListener('click', go);
        tr.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                go();
            }
        });
    });
}

function applyListFilter() {
    const f = document.getElementById('ins-filter-lists');
    const q = (f && f.value.trim().toLowerCase()) || '';

    const tbodyDisp = document.getElementById('tbody_disponibles');
    if (tbodyDisp) {
        let items = [];
        try {
            items = JSON.parse(tbodyDisp.dataset.raw || '[]');
        } catch {
            items = [];
        }
        const filtered = ordenarDisponiblesPorNombre(filtraItems(items, q));
        let html = filtered.map(filaDisponibleHtml).join('');
        if (!html) {
            html = `<tr><td colspan="4" class="ins-sitio-empty">${
                q ? 'Sin coincidencias' : 'Sin atletas disponibles'
            }</td></tr>`;
        }
        tbodyDisp.innerHTML = html;
        wireFilasSitio(tbodyDisp);
        const cd = document.getElementById('count_disponibles');
        if (cd) cd.textContent = String(filtered.length);
    }

    const tbodyInsc = document.getElementById('tbody_inscritos');
    if (tbodyInsc) {
        let items = [];
        try {
            items = JSON.parse(tbodyInsc.dataset.raw || '[]');
        } catch {
            items = [];
        }
        const filtered = filtraItems(items, q);
        let html = filtered.map(filaInscritoHtml).join('');
        if (!html) {
            html =
                '<tr><td colspan="4" class="ins-sitio-empty">No hay inscritos (movimiento_torneo.inscripcion=1 en este torneo y asociación)</td></tr>';
        }
        tbodyInsc.innerHTML = html;
        wireFilasSitio(tbodyInsc);
        const ci = document.getElementById('count_inscritos');
        if (ci) ci.textContent = String(filtered.length);
    }
}

/**
 * Recarga solo la columna de inscritos (alias usado por retirarAtleta).
 */
async function cargarListaInscritos() {
    if (asociacionIdActiva() < 1) {
        renderInscritos([]);
        return;
    }
    try {
        const res = await fetch(`${API_INSC}?x=1${torneoQueryParam()}`, { credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (res.ok && data.ok && Array.isArray(data.items)) {
            renderInscritos(data.items, data.estadisticas || null);
            const tbodyDisp = document.getElementById('tbody_disponibles');
            let nDisp = 0;
            try {
                nDisp = JSON.parse(tbodyDisp?.dataset.raw || '[]').length;
            } catch {
                nDisp = 0;
            }
            renderEstadisticas(data.estadisticas || null, nDisp);
        } else {
            renderInscritos([]);
        }
    } catch (e) {
        console.error(e);
        renderInscritos([]);
    }
}

/**
 * Retira un atleta del torneo (movimiento_torneo.inscripcion = 0).
 * Expuesto en window para onclick en filas generadas dinámicamente.
 *
 * @param {number} id id_inscripcion (movimiento_torneo.id / mov_id)
 */
async function retirarAtleta(id) {
    const idInscripcion = parseInt(String(id), 10);
    if (idInscripcion < 1) {
        showFormMsg('No se pudo identificar la inscripción.', false);
        return;
    }
    if (!window.confirm('¿Seguro que deseas retirar a este atleta?')) {
        return;
    }

    hideFormMsg();

    try {
        const res = await fetch(API_RETIRAR, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ id_inscripcion: idInscripcion }),
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok && (data.success === true || data.ok === true)) {
            showFormMsg(data.message || 'Atleta retirado correctamente', true);
            await cargarListaInscritos();
            const resDisp = await fetch(`${API_DISP}?x=1${torneoQueryParam()}`, { credentials: 'same-origin' });
            const dDisp = await resDisp.json().catch(() => ({}));
            if (resDisp.ok && dDisp.ok) {
                renderDisponibles({
                    items: Array.isArray(dDisp.items) ? dDisp.items : [],
                    grupos: dDisp.grupos,
                });
            }
        } else {
            showFormMsg(data.message || 'No se pudo retirar la inscripción.', false);
        }
    } catch (e) {
        console.error(e);
        showFormMsg('Error de conexión.', false);
    }
}

async function refreshLists() {
    if (asociacionIdActiva() < 1) {
        renderDisponibles({
            items: [],
            grupos: ORDEN_GRUPOS_DISP.map((g) => ({ ...g, items: [] })),
        });
        renderInscritos([]);
        return;
    }
    try {
        const q = torneoQueryParam();
        const [r1, r2] = await Promise.all([
            fetch(`${API_DISP}?x=1${q}`, { credentials: 'same-origin' }),
            fetch(`${API_INSC}?x=1${q}`, { credentials: 'same-origin' }),
        ]);
        const d1 = await r1.json().catch(() => ({}));
        const d2 = await r2.json().catch(() => ({}));
        let nDisp = 0;
        if (r1.ok && d1.ok) {
            const grupos =
                Array.isArray(d1.grupos) && d1.grupos.length > 0
                    ? d1.grupos
                    : ORDEN_GRUPOS_DISP.map((g) => ({ ...g, items: [] }));
            renderDisponibles({
                items: Array.isArray(d1.items) ? d1.items : [],
                grupos,
            });
            nDisp = Array.isArray(d1.items) ? d1.items.length : 0;
        } else {
            renderDisponibles({
                items: [],
                grupos: ORDEN_GRUPOS_DISP.map((g) => ({ ...g, items: [] })),
            });
        }
        if (r2.ok && d2.ok !== false && Array.isArray(d2.items)) {
            renderInscritos(d2.items, d2.estadisticas || null);
            renderEstadisticas(d2.estadisticas || null, nDisp);
            if (torneoCtx && d2.torneo_id) {
                torneoCtx.torneoId = parseInt(String(d2.torneo_id), 10) || torneoCtx.torneoId;
            }
            if (d2.items.length < 1) {
                showFormMsg(
                    `0 inscritos: movimiento_torneo con inscripcion=1, torneo ${d2.torneo_id ?? '—'}, asociación ${d2.asociacion_id ?? '—'}.`,
                    false
                );
            } else {
                hideFormMsg();
            }
        } else {
            const msg =
                d2.message ||
                (!r2.ok ? `HTTP ${r2.status}` : '') ||
                'No se pudo leer inscritos desde movimiento_torneo.';
            showFormMsg(msg, false);
            renderInscritos([]);
            renderEstadisticas(null, nDisp);
        }
    } catch (e) {
        console.error(e);
        renderDisponibles({
            items: [],
            grupos: ORDEN_GRUPOS_DISP.map((g) => ({ ...g, items: [] })),
        });
        renderInscritos([]);
    }
}

async function onCambioVarianteCampeonato() {
    await loadTorneoContext();
}

async function loadTorneoContext() {
    const meta = document.getElementById('ins-meta');
    try {
        const tidReq = getDelegadoTorneoIdSeleccionado();
        const urlCtx =
            tidReq > 0 && permiteSelectorCampeonatoJornada()
                ? `${API_CTX}?torneo_id=${encodeURIComponent(String(tidReq))}`
                : API_CTX;
        const res = await fetch(urlCtx, { credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        const j = getAsociacionJornada();
        const aidFallback =
            (authCtx && authCtx.asociacion_id) || (j && j.id) || 0;
        const asocFallback =
            (data.asociacion && data.asociacion.nombre ? data.asociacion : null) ||
            j ||
            (authCtx && authCtx.asociacion) ||
            null;

        if (!res.ok || (!data.ok && aidFallback < 1)) {
            redirectLogin();
            return;
        }

        const tidCtx =
            data.torneo_id != null
                ? parseInt(String(data.torneo_id), 10)
                : data.torneo && data.torneo.torneo != null
                  ? parseInt(String(data.torneo.torneo), 10)
                  : getDelegadoTorneoIdSeleccionado();

        torneoCtx = {
            modalidad: data.modalidad || null,
            jugadores_requeridos:
                data.jugadores_requeridos != null ? parseInt(String(data.jugadores_requeridos), 10) : 0,
            torneo: data.torneo || null,
            torneoId: tidCtx > 0 ? tidCtx : 0,
            asociacionId:
                data.asociacion_id != null
                    ? parseInt(String(data.asociacion_id), 10)
                    : aidFallback,
            asociacion: data.asociacion || asocFallback,
            movimiento_torneo_bloqueado: !!data.movimiento_torneo_bloqueado,
            movimiento_bloqueo_motivo: data.movimiento_bloqueo_motivo || '',
            permite_selector_campeonato: !!data.permite_selector_campeonato,
            campeonato_variantes: Array.isArray(data.campeonato_variantes) ? data.campeonato_variantes : [],
        };
        renderTituloPagina(torneoCtx.asociacion);
        renderAsocCodeBadge(torneoCtx.asociacion);
        if (torneoCtx.torneoId < 1) {
            torneoCtx.torneoId = getDelegadoTorneoIdSeleccionado();
        }
        const t = data.torneo;
        if (meta) {
            if (!t) {
                meta.textContent = 'No hay torneo activo (sin fecha de cierre).';
            } else {
                const modLabels = { individual: 'Individual', parejas: 'Parejas', equipos: 'Equipos' };
                const ml = data.modalidad ? modLabels[data.modalidad] || data.modalidad : '—';
                let linea = `Torneo activo · ${t.nombre || torneoCtx.torneoId} · Modalidad: ${ml} · Género: ${data.tipo_torneo_label || '—'}`;
                if (torneoCtx.permite_selector_campeonato) {
                    linea += ' · Use el selector superior para cambiar variante del campeonato';
                }
                meta.textContent = linea;
            }
        }
        mountForm(torneoCtx.modalidad);
        await refreshLists();
        document.getElementById('ins-input-cedula')?.focus();
    } catch (e) {
        console.error(e);
        if (meta) meta.textContent = 'Error de conexión al cargar el torneo.';
        mountForm(null);
    }
}

function redirectLogin() {
    window.location.replace('index.php');
}

async function verificarAcceso() {
    try {
        const res = await fetch('api/auth_context.php', { credentials: 'same-origin' });
        const ctx = await res.json().catch(() => ({}));
        if (!res.ok || !ctx.logged || !ctx.puede_panel_admin) {
            redirectLogin();
            return false;
        }
        const asocId = ctx.asociacion_id != null ? parseInt(String(ctx.asociacion_id), 10) : 0;
        if (asocId < 1) {
            redirectLogin();
            return false;
        }
        authCtx = {
            rol: ctx.rol || '',
            asociacion_id: asocId,
            asociacion: ctx.asociacion_activa || null,
        };
        persistJornadaDesdeAuth(ctx);
        mountPortalPerfilHeader(document.getElementById('ins-main-nav'));
        renderTituloPagina(ctx.asociacion_activa || getAsociacionJornada());
        return true;
    } catch (e) {
        console.error(e);
        redirectLogin();
        return false;
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    normalizarUrlInscripciones();
    const ok = await verificarAcceso();
    if (!ok) return;

    await initDelegadoTorneosBar({ onTorneoChange: () => void onCambioVarianteCampeonato() });

    const root = document.getElementById('ins-root');
    if (root) root.classList.remove('is-hidden');

    document.getElementById('ins-filter-lists')?.addEventListener('input', () => applyListFilter());

    document.getElementById('ins-btn-reporte')?.addEventListener('click', () => abrirReporteInscritos());
    document.getElementById('ins-btn-reporte-disp')?.addEventListener('click', () => abrirReporteDisponibles());
    document.getElementById('ins-btn-admin')?.addEventListener('click', (e) => {
        const tid = torneoIdJornada();
        if (tid > 0) {
            e.currentTarget.href = `inscripciones_admin.html?torneo_id=${encodeURIComponent(String(tid))}`;
        }
    });

    const editForm = document.getElementById('ins-edit-form');
    const editDlg = document.getElementById('ins-edit-dialog');
    editForm?.addEventListener('submit', (e) => {
        e.preventDefault();
        void guardarTelefonoInscrito();
    });
    document.getElementById('ins-edit-cerrar')?.addEventListener('click', () => editDlg?.close());
    document.getElementById('ins-edit-retirar')?.addEventListener('click', () => {
        if (!insEditCtx || insEditCtx.movId < 1) return;
        const mid = insEditCtx.movId;
        editDlg?.close();
        void retirarAtleta(mid);
    });

    wireSitioBusqueda();
    setBusquedaInscribirEnabled(false);
    renderEstadisticas({ total: 0, hombres: 0, mujeres: 0 }, 0);

    window.retirarAtleta = retirarAtleta;
    window.cargarListaInscritos = cargarListaInscritos;

    await loadTorneoContext();
});
