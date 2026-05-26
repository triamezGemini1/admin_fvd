/**
 * Administrador de inscripciones: listado completo de inscritos con foto, carnet, contacto y acciones.
 */
import { initFvdReportPage } from './fvd_report_page.js';
import {
    initDelegadoTorneosBar,
    getAsociacionJornada,
    persistJornadaDesdeAuth,
    getDelegadoTorneoIdSeleccionado,
    permiteSelectorCampeonatoJornada,
} from './delegado_torneos_bar.js';

const API_CTX = 'api/inscripciones_context.php';
const API_INSC = 'api/inscripciones_inscritos.php';
const API_POST = 'api/inscripciones_inscribir.php';
const API_RETIRAR = 'api/inscripciones_retirar.php';
const API_BUSCAR_CEDULA = 'api/inscripciones_buscar_cedula.php';
const API_ACTUALIZAR = 'api/inscripciones_actualizar_atleta.php';
const API_REPORTE_ADMIN = 'api/inscripciones_reporte_admin.php';

/** @type {{ modalidad: string | null, torneoId: number, asociacionId: number, asociacion: object | null } | null} */
let torneoCtx = null;

/** @type {object[]} */
let inscritosRaw = [];

/** @type {{ movId: number, userId: number, nombre: string, celular: string, email: string, carnet: string, fotoUrl: string | null } | null} */
let editCtx = null;

/** @type {{ movId: number, nombre: string } | null} */
let cambiarCtx = null;

/** @type {{ movId: number, nombre: string } | null} */
let retirarCtx = null;

/**
 * @param {'edit'|'cambiar'|'retirar'} act
 * @param {string} symbol
 * @param {string} title
 * @param {string} [extraClass]
 */
function iconActionBtn(act, symbol, title, extraClass = '') {
    const cls = ['ins-admin-icon-btn', 'reporte-btn-ic', 'btn-secondary', 'btn-sm', extraClass]
        .filter(Boolean)
        .join(' ');
    return `<button type="button" class="${cls}" data-act="${act}" title="${esc(title)}" aria-label="${esc(title)}"><span class="reporte-btn-ic-sym" aria-hidden="true">${symbol}</span></button>`;
}

function esc(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function asociacionIdActiva() {
    return torneoCtx?.asociacionId ? parseInt(String(torneoCtx.asociacionId), 10) : 0;
}

function torneoIdJornada() {
    const ctx = torneoCtx?.torneoId || 0;
    const bar = getDelegadoTorneoIdSeleccionado();
    return ctx > 0 ? ctx : bar;
}

function torneoQueryParam() {
    const tid = torneoIdJornada();
    return tid > 0 ? `&torneo_id=${encodeURIComponent(String(tid))}` : '';
}

function showGate(html) {
    const gate = document.getElementById('ins-admin-gate');
    const root = document.getElementById('ins-admin-root');
    if (gate) {
        gate.innerHTML = html;
        gate.classList.remove('is-hidden');
    }
    if (root) root.classList.add('is-hidden');
}

function showApp() {
    document.getElementById('ins-admin-gate')?.classList.add('is-hidden');
    document.getElementById('ins-admin-root')?.classList.remove('is-hidden');
}

function showToast(text, ok) {
    const meta = document.getElementById('ins-admin-meta');
    if (!meta) return;
    const prev = meta.dataset.defaultText || meta.textContent;
    if (!meta.dataset.defaultText) meta.dataset.defaultText = prev;
    meta.textContent = text;
    meta.classList.toggle('error', !ok);
    if (ok) {
        window.setTimeout(() => {
            meta.textContent = meta.dataset.defaultText || '';
            meta.classList.remove('error');
        }, 3500);
    }
}

function renderStats(stats) {
    const st = stats || { total: 0, hombres: 0, mujeres: 0 };
    const el = (id, v) => {
        const n = document.getElementById(id);
        if (n) n.textContent = String(v);
    };
    el('ins-admin-stat-total', st.total ?? 0);
    el('ins-admin-stat-hombres', st.hombres ?? 0);
    el('ins-admin-stat-mujeres', st.mujeres ?? 0);
}

function thumbImg(url, alt, className) {
    if (!url) {
        return `<span class="ins-admin-thumb-placeholder" aria-hidden="true">—</span>`;
    }
    return `<img class="ins-admin-thumb ${className || ''}" src="${esc(url)}" alt="${esc(alt)}" loading="lazy" width="52" height="52" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'ins-admin-thumb-placeholder',textContent:'—'}))">`;
}

function carnetCell(it) {
    const img = it.cedula_img_url || null;
    if (img) {
        return `<div class="ins-admin-carnet-img">${thumbImg(img, 'Cédula', 'ins-admin-thumb--ced')}</div>`;
    }
    const nf = parseInt(String(it.numfvd ?? 0), 10);
    return nf > 0 ? esc(String(nf)) : '—';
}

function filaHtml(it, idx) {
    const movId = parseInt(String(it.mov_id ?? 0), 10);
    const uid = parseInt(String(it.id_usuario ?? 0), 10);
    const nom = String(it.nombre_usuario ?? '').trim() || '—';
    const ced = String(it.cedula ?? '').trim() || '—';
    const tel = String(it.celular ?? '').trim() || '—';
    const em = String(it.email ?? '').trim() || '—';
    const foto = it.foto_url || null;

    return `<tr data-mov-id="${movId}" data-user-id="${uid}">
        <td class="ins-admin-col-num ag-num">${idx + 1}</td>
        <td class="ins-admin-col-foto">${thumbImg(foto, nom)}</td>
        <td class="ins-admin-col-carnet">${carnetCell(it)}</td>
        <td>${esc(nom)}</td>
        <td>${esc(ced)}</td>
        <td>${esc(tel)}</td>
        <td class="ins-admin-col-email">${esc(em)}</td>
        <td class="ins-admin-col-acc ins-admin-no-print">
            <div class="ins-admin-row-actions" role="group" aria-label="Acciones del inscrito">
                ${iconActionBtn('edit', '✎', 'Editar datos')}
                ${iconActionBtn('cambiar', '⇄', 'Cambiar jugador')}
                ${iconActionBtn('retirar', '🗑', 'Retirar inscripción', 'ins-admin-icon-btn--danger')}
            </div>
        </td>
    </tr>`;
}

function applyFilter() {
    const q = (document.getElementById('ins-admin-filter')?.value || '').trim().toLowerCase();
    let items = inscritosRaw;
    if (q) {
        items = items.filter((it) => {
            const blob = [
                it.nombre_usuario,
                it.cedula,
                it.numfvd,
                it.celular,
                it.email,
            ]
                .map((v) => String(v ?? '').toLowerCase())
                .join(' ');
            return blob.includes(q);
        });
    }
    const tbody = document.getElementById('ins-admin-tbody');
    if (!tbody) return;
    if (items.length === 0) {
        tbody.innerHTML =
            '<tr><td colspan="8" class="ins-sitio-empty">' +
            (q ? 'Sin coincidencias' : 'No hay inscritos en este torneo') +
            '</td></tr>';
        return;
    }
    tbody.innerHTML = items.map((it, i) => filaHtml(it, i)).join('');
    wireRowActions(tbody);
}

async function cargarInscritos() {
    if (asociacionIdActiva() < 1) {
        inscritosRaw = [];
        applyFilter();
        return;
    }
    const tbody = document.getElementById('ins-admin-tbody');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="8" class="ins-sitio-empty">Cargando…</td></tr>';
    }
    try {
        const res = await fetch(`${API_INSC}?x=1${torneoQueryParam()}`, { credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (res.ok && data.ok && Array.isArray(data.items)) {
            inscritosRaw = data.items;
            renderStats(data.estadisticas);
            applyFilter();
        } else {
            inscritosRaw = [];
            applyFilter();
            showToast(data.message || 'No se pudo cargar el listado.', false);
        }
    } catch (e) {
        console.error(e);
        inscritosRaw = [];
        applyFilter();
        showToast('Error de conexión.', false);
    }
}

function wireRowActions(tbody) {
    tbody.querySelectorAll('[data-act]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const tr = btn.closest('tr');
            if (!tr) return;
            const movId = parseInt(tr.getAttribute('data-mov-id') || '0', 10);
            const userId = parseInt(tr.getAttribute('data-user-id') || '0', 10);
            const act = btn.getAttribute('data-act');
            const it = inscritosRaw.find((r) => parseInt(String(r.mov_id), 10) === movId);
            const nom = it ? String(it.nombre_usuario ?? '') : '';
            if (act === 'edit' && it) {
                abrirEditar(it);
            } else if (act === 'cambiar') {
                abrirCambiar(movId, nom);
            } else if (act === 'retirar' && movId > 0) {
                abrirRetirar(movId, nom);
            }
        });
    });
}

function abrirEditar(it) {
    const dlg = document.getElementById('ins-admin-edit-dlg');
    const foto = document.getElementById('ins-admin-edit-foto');
    editCtx = {
        movId: parseInt(String(it.mov_id), 10),
        userId: parseInt(String(it.id_usuario), 10),
        nombre: String(it.nombre_usuario ?? ''),
        celular: String(it.celular ?? ''),
        email: String(it.email ?? ''),
        carnet: parseInt(String(it.numfvd ?? 0), 10) > 0 ? String(it.numfvd) : '—',
        fotoUrl: it.foto_url || null,
    };
    document.getElementById('ins-admin-edit-nombre').textContent = editCtx.nombre;
    document.getElementById('ins-admin-edit-carnet').textContent = `Carnet / Nº FVD: ${editCtx.carnet}`;
    document.getElementById('ins-admin-edit-celular').value = editCtx.celular;
    document.getElementById('ins-admin-edit-email').value = editCtx.email;
    const msg = document.getElementById('ins-admin-edit-msg');
    if (msg) msg.style.display = 'none';
    if (foto) {
        if (editCtx.fotoUrl) {
            foto.src = editCtx.fotoUrl;
            foto.hidden = false;
        } else {
            foto.hidden = true;
            foto.removeAttribute('src');
        }
    }
    dlg?.showModal();
}

async function guardarEdicion(e) {
    e.preventDefault();
    if (!editCtx || editCtx.userId < 1) return;
    const cel = document.getElementById('ins-admin-edit-celular')?.value.trim() ?? '';
    const em = document.getElementById('ins-admin-edit-email')?.value.trim() ?? '';
    const msg = document.getElementById('ins-admin-edit-msg');
    try {
        const res = await fetch(API_ACTUALIZAR, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ usuario_id: editCtx.userId, celular: cel, email: em }),
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok && data.ok) {
            document.getElementById('ins-admin-edit-dlg')?.close();
            showToast(data.message || 'Datos guardados.', true);
            await cargarInscritos();
        } else if (msg) {
            msg.textContent = data.message || 'No se pudo guardar.';
            msg.style.display = 'block';
            msg.className = 'form-msg err ins-sitio-msg';
        }
    } catch (err) {
        console.error(err);
        if (msg) {
            msg.textContent = 'Error de conexión.';
            msg.style.display = 'block';
        }
    }
}

function abrirRetirar(movId, nombre) {
    retirarCtx = { movId, nombre: nombre || '—' };
    const dlg = document.getElementById('ins-admin-retirar-dlg');
    const nomEl = document.getElementById('ins-admin-retirar-nombre');
    const msg = document.getElementById('ins-admin-retirar-msg');
    if (nomEl) nomEl.textContent = nombre || '—';
    if (msg) msg.style.display = 'none';
    dlg?.showModal();
}

async function retirar(movId) {
    if (movId < 1) return;
    try {
        const res = await fetch(API_RETIRAR, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ id_inscripcion: movId }),
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok && (data.success || data.ok)) {
            showToast(data.message || 'Atleta retirado.', true);
            await cargarInscritos();
        } else {
            showToast(data.message || 'No se pudo retirar.', false);
        }
    } catch (e) {
        console.error(e);
        showToast('Error de conexión.', false);
    }
}

function abrirCambiar(movId, nombre) {
    cambiarCtx = { movId, nombre };
    const dlg = document.getElementById('ins-admin-cambiar-dlg');
    document.getElementById('ins-admin-cambiar-actual').textContent = `Participante actual: ${nombre}`;
    document.getElementById('ins-admin-cambiar-cedula').value = '';
    document.getElementById('ins-admin-cambiar-nombre').textContent = '';
    document.getElementById('ins-admin-cambiar-user-id').value = '';
    document.getElementById('ins-admin-cambiar-confirm').disabled = true;
    const msg = document.getElementById('ins-admin-cambiar-msg');
    if (msg) msg.style.display = 'none';
    dlg?.showModal();
}

async function buscarCedulaCambio() {
    const ced = document.getElementById('ins-admin-cambiar-cedula')?.value.trim() ?? '';
    const nomEl = document.getElementById('ins-admin-cambiar-nombre');
    const hid = document.getElementById('ins-admin-cambiar-user-id');
    const btn = document.getElementById('ins-admin-cambiar-confirm');
    const msg = document.getElementById('ins-admin-cambiar-msg');
    if (ced.length < 4) {
        if (nomEl) nomEl.textContent = '';
        if (hid) hid.value = '';
        if (btn) btn.disabled = true;
        return;
    }
    try {
        const res = await fetch(
            `${API_BUSCAR_CEDULA}?cedula=${encodeURIComponent(ced)}${torneoQueryParam()}`,
            { credentials: 'same-origin' }
        );
        const data = await res.json().catch(() => ({}));
        if (data.ya_inscrito) {
            if (nomEl) nomEl.textContent = 'Este atleta ya está inscrito en el torneo.';
            if (hid) hid.value = '';
            if (btn) btn.disabled = true;
            return;
        }
        if (data.encontrado && data.usuario) {
            const u = data.usuario;
            const uid = parseInt(String(u.id ?? 0), 10);
            const nom = String(u.nombre ?? '').trim();
            if (nomEl) nomEl.textContent = nom ? `Nuevo: ${nom}` : 'Atleta encontrado';
            if (hid) hid.value = uid > 0 ? String(uid) : '';
            if (btn) btn.disabled = uid < 1;
            if (msg) msg.style.display = 'none';
        } else {
            if (nomEl) nomEl.textContent = data.error || 'No encontrado';
            if (hid) hid.value = '';
            if (btn) btn.disabled = true;
        }
    } catch (e) {
        console.error(e);
        if (nomEl) nomEl.textContent = 'Error de búsqueda';
        if (btn) btn.disabled = true;
    }
}

async function confirmarCambio(e) {
    e.preventDefault();
    if (!cambiarCtx || cambiarCtx.movId < 1) return;
    const uid = parseInt(document.getElementById('ins-admin-cambiar-user-id')?.value || '0', 10);
    if (uid < 1) return;
    if (torneoCtx?.modalidad && torneoCtx.modalidad !== 'individual') {
        showToast('Cambio rápido solo en torneos individuales. Use inscripción en sitio.', false);
        return;
    }
    if (!window.confirm('Se retirará el jugador actual y se inscribirá el nuevo. ¿Continuar?')) return;

    const msg = document.getElementById('ins-admin-cambiar-msg');
    try {
        const resRet = await fetch(API_RETIRAR, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ id_inscripcion: cambiarCtx.movId }),
        });
        const dRet = await resRet.json().catch(() => ({}));
        if (!resRet.ok || !(dRet.success || dRet.ok)) {
            if (msg) {
                msg.textContent = dRet.message || 'No se pudo retirar al jugador actual.';
                msg.style.display = 'block';
            }
            return;
        }
        const resIns = await fetch(API_POST, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                usuario_ids: [uid],
                torneo_id: torneoIdJornada(),
            }),
        });
        const dIns = await resIns.json().catch(() => ({}));
        if (resIns.ok && dIns.ok) {
            document.getElementById('ins-admin-cambiar-dlg')?.close();
            showToast(dIns.message || 'Jugador cambiado correctamente.', true);
            await cargarInscritos();
        } else if (msg) {
            msg.textContent =
                (dIns.message || 'No se pudo inscribir al nuevo atleta.') +
                ' El anterior ya fue retirado; use «Nuevo ingreso» si hace falta.';
            msg.style.display = 'block';
        }
    } catch (err) {
        console.error(err);
        if (msg) {
            msg.textContent = 'Error de conexión.';
            msg.style.display = 'block';
        }
    }
}

async function loadTorneoContext() {
    const meta = document.getElementById('ins-admin-meta');
    try {
        const tidReq = getDelegadoTorneoIdSeleccionado();
        const urlCtx =
            tidReq > 0 && permiteSelectorCampeonatoJornada()
                ? `${API_CTX}?torneo_id=${encodeURIComponent(String(tidReq))}`
                : API_CTX;
        const res = await fetch(urlCtx, { credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        const j = getAsociacionJornada();
        const aidFallback = data.asociacion_id != null ? parseInt(String(data.asociacion_id), 10) : j?.id || 0;

        if (!res.ok || (!data.ok && aidFallback < 1)) {
            window.location.replace('index.php');
            return;
        }

        const tidCtx =
            data.torneo_id != null
                ? parseInt(String(data.torneo_id), 10)
                : data.torneo?.torneo != null
                  ? parseInt(String(data.torneo.torneo), 10)
                  : getDelegadoTorneoIdSeleccionado();

        torneoCtx = {
            modalidad: data.modalidad || null,
            torneoId: tidCtx > 0 ? tidCtx : getDelegadoTorneoIdSeleccionado(),
            asociacionId: aidFallback,
            asociacion: data.asociacion || j || null,
        };

        const t = data.torneo;
        const asocNom = torneoCtx.asociacion?.nombre ? String(torneoCtx.asociacion.nombre) : '';
        if (meta) {
            if (!t) {
                meta.textContent = 'No hay torneo activo.';
            } else {
                const modLabels = { individual: 'Individual', parejas: 'Parejas', equipos: 'Equipos' };
                const ml = data.modalidad ? modLabels[data.modalidad] || data.modalidad : '—';
                meta.textContent = `${asocNom ? asocNom + ' · ' : ''}${t.nombre || torneoCtx.torneoId} · ${ml}`;
                meta.dataset.defaultText = meta.textContent;
            }
        }

        const ingreso = document.getElementById('ins-admin-btn-ingreso');
        if (ingreso) {
            const tid = torneoIdJornada();
            ingreso.href = tid > 0 ? `inscripciones.html?torneo_id=${tid}` : 'inscripciones.html';
        }

        await cargarInscritos();
    } catch (e) {
        console.error(e);
        if (meta) meta.textContent = 'Error al cargar el torneo.';
    }
}

async function verificarAcceso() {
    try {
        const res = await fetch('api/auth_context.php', { credentials: 'same-origin' });
        const ctx = await res.json().catch(() => ({}));
        if (!res.ok || !ctx.logged || !ctx.puede_panel_admin) {
            window.location.replace('index.php');
            return false;
        }
        const asocId = ctx.asociacion_id != null ? parseInt(String(ctx.asociacion_id), 10) : 0;
        if (asocId < 1) {
            window.location.replace('index.php');
            return false;
        }
        persistJornadaDesdeAuth(ctx);
        return true;
    } catch (e) {
        console.error(e);
        window.location.replace('index.php');
        return false;
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    initFvdReportPage({ nav: '#ins-admin-nav' });

    const ok = await verificarAcceso();
    if (!ok) return;

    await initDelegadoTorneosBar({
        onTorneoChange: async () => {
            await loadTorneoContext();
        },
    });

    showApp();

    document.getElementById('ins-admin-filter')?.addEventListener('input', () => applyFilter());
    document.getElementById('ins-admin-btn-print')?.addEventListener('click', () => window.print());
    document.getElementById('ins-admin-btn-pdf')?.addEventListener('click', () => {
        if (asociacionIdActiva() < 1) return;
        window.open(`${API_REPORTE_ADMIN}?auto_print=1${torneoQueryParam()}`, '_blank', 'noopener');
    });

    document.getElementById('ins-admin-edit-form')?.addEventListener('submit', (e) => void guardarEdicion(e));
    document.getElementById('ins-admin-edit-cancel')?.addEventListener('click', () =>
        document.getElementById('ins-admin-edit-dlg')?.close()
    );
    document.getElementById('ins-admin-edit-retirar')?.addEventListener('click', () => {
        if (!editCtx) return;
        const { movId, nombre } = editCtx;
        document.getElementById('ins-admin-edit-dlg')?.close();
        abrirRetirar(movId, nombre);
    });

    document.getElementById('ins-admin-cambiar-cedula')?.addEventListener('input', () => void buscarCedulaCambio());
    document.getElementById('ins-admin-cambiar-form')?.addEventListener('submit', (e) => void confirmarCambio(e));
    document.getElementById('ins-admin-cambiar-cancel')?.addEventListener('click', () =>
        document.getElementById('ins-admin-cambiar-dlg')?.close()
    );

    document.getElementById('ins-admin-retirar-cancel')?.addEventListener('click', () =>
        document.getElementById('ins-admin-retirar-dlg')?.close()
    );
    document.getElementById('ins-admin-retirar-confirm')?.addEventListener('click', () => {
        if (!retirarCtx || retirarCtx.movId < 1) return;
        const mid = retirarCtx.movId;
        document.getElementById('ins-admin-retirar-dlg')?.close();
        void retirar(mid);
    });

    await loadTorneoContext();
});
