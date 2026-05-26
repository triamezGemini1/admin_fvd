/**
 * Panel CRUD: organización FVD, torneos, asociaciones, usuarios (permisos vía auth_context).
 */

import {
    REPORTE_FILAS_POR_PAGINA,
    reporteHtmlPaginador,
    reporteLigarPaginador,
    reporteHtmlAcciones,
    reporteDelegarAcciones,
    reporteToggleEstatusBinario,
    reporteToggleEstatusActivoInactivoFvd,
    reporteMetaPagina,
    reporteSliceCliente,
    reporteThumbImg,
    htmlFvdTableShell,
    htmlCeldaEstadisticaRenglon,
    htmlResumenRenglonesCards,
    htmlThEstadisticaRenglon,
    RENGLONES_ESTADISTICA,
} from './reporte_tabla.js';
import { mountPortalPerfilHeader } from './portal_perfil_header.js';
import {
    initDelegadoTorneosBar,
    getDelegadoTorneoIdSeleccionado,
    getDelegadoTorneosCache,
    persistJornadaDesdeAuth,
} from './delegado_torneos_bar.js';
import {
    guardarTorneoFinanzas,
    htmlBannerTorneoCuentas,
    htmlBarraTorneoFinanzas,
    leerTorneoFinanzasGuardado,
    metaTorneoFinanzas,
    postActualizarDeudas,
    torneoFinanzasQs,
    wireBarraTorneoFinanzas,
} from './torneo_finanzas_ui.js';
import {
    hrefFinanzasAsociacion,
    hrefInformeAsociacion,
    hrefInformeConsolidado,
    hrefReporteAsociacionesTorneo,
    hrefReporteParticipacion,
} from './finanzas_reporte_nav.js';
import {
    fetchFinanzaDetalleAsociacion,
    htmlGruposInformeAcordeon,
    resetPaginasDetalleFinanza,
    wirePaginacionDetalleGrupos,
} from './finanzas_detalle_ui.js';
import { loadNominaDesdeAtletasPanel } from './panel_nomina_atletas.js';

/** Identidad visual FVD (activos en /img) */
const FVD_BRAND = {
    loginPng: 'img/fvd-portal-logo.png',
    favicon: 'img/logonvofvd.ico',
};

/** Opciones de modal al 60% del viewport (todos los formularios del panel) */
function modalForm60(extra = {}) {
    return { dialogClass: 'admin-modal admin-modal--form-60', ...extra };
}

function modalTorneoCrud(extra = {}) {
    return { dialogClass: 'admin-modal admin-modal--form-60 admin-modal--torneo-crud', ...extra };
}

/**
 * Campos escalares del formulario torneo (checkboxes con data-tor-bool → 0/1).
 * @param {HTMLElement} inner
 * @returns {Record<string, string|number>}
 */
function collectTorneoScalarFields(inner) {
    const body = {};
    inner.querySelectorAll('input:not([type=file]):not([type=checkbox]), select, textarea').forEach((inp) => {
        const n = inp.getAttribute('name');
        if (!n || n === 'torneo') return;
        body[n] = inp.value;
    });
    inner.querySelectorAll('input[type=checkbox][data-tor-bool]').forEach((inp) => {
        const n = inp.getAttribute('name');
        if (n) body[n] = inp.checked ? 1 : 0;
    });
    return body;
}

/**
 * @param {FormData} fd
 * @param {HTMLElement} inner
 */
function appendTorneoScalarsToFormData(fd, inner) {
    const sc = collectTorneoScalarFields(inner);
    Object.keys(sc).forEach((k) => {
        fd.append(k, String(sc[k]));
    });
}

async function uploadPanelAsset(formData) {
    const res = await fetch('api/upload_panel_asset.php', { method: 'POST', body: formData, credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    return { res, data };
}

function esc(v) {
    if (v === null || v === undefined) return '';
    return String(v)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Franja identidad asociación (delegado): logo, nombre, delegado contacto.
 * @returns {string}
 */
function htmlDelegadoAsocStrip() {
    if (!ctx || ctx.rol !== 'delegado') return '';
    const a = ctx.asociacion_activa;
    const aid = ctx.asociacion_id != null ? Number(ctx.asociacion_id) : 0;
    let nom = '';
    let logo = '';
    let del = '';
    if (a && typeof a === 'object') {
        nom = String(a.nombre || '').trim();
        logo = String(a.logo || '').trim();
        del = String(a.delegado || '').trim();
    }
    const nameHtml = nom !== '' ? esc(nom) : esc(`Asociación #${aid}`);
    const delHtml =
        del !== ''
            ? `<p class="delegado-asoc-strip__deleg"><span class="delegado-asoc-strip__deleg-k">Delegado</span> ${esc(del)}</p>`
            : '<p class="delegado-asoc-strip__deleg delegado-asoc-strip__deleg--na"><span class="delegado-asoc-strip__deleg-k">Delegado</span> Sin dato en ficha</p>';
    const logoHtml =
        logo !== ''
            ? `<img class="delegado-asoc-strip__logo" src="${esc(logo)}" alt="" width="64" height="64" loading="lazy">`
            : '<div class="delegado-asoc-strip__logo-ph" aria-hidden="true"><span>FVD</span></div>';
    return `<aside class="delegado-asoc-strip" aria-label="Asociación activa">
        <div class="delegado-asoc-strip__row">
            ${logoHtml}
            <div class="delegado-asoc-strip__body">
                <p class="delegado-asoc-strip__tag">Asociación activa</p>
                <p class="delegado-asoc-strip__name">${nameHtml}</p>
                ${delHtml}
            </div>
        </div>
    </aside>`;
}

function getDelegadoTorneoId() {
    const t = listState.delegadoTorneoId;
    return typeof t === 'number' && t > 0 ? t : 0;
}

/** @type {any[]} */
let delegadoTorneosItemsCache = [];

/** Listado «Carnet» delegado: datos última carga (filtrar sin refetch). */
let delegadoCarnetCache = /** @type {{ items: any[], tid: number } | null} */ (null);
let delCarDebTimer = 0;
/** Debounce búsqueda reporte traspaso (delegado). */
let delTrRepDebTimer = 0;

function paintDelegadoTorneoBadges() {
    const tid = getDelegadoTorneoId();
    document.querySelectorAll('.delegado-tor-badge').forEach((b) => {
        const id = parseInt(String(b.getAttribute('data-torneo-id') || '0'), 10) || 0;
        b.classList.toggle('is-active', id > 0 && id === tid);
    });
}

function refreshDelegadoTorneoDependentPanels() {
    const ws = document.getElementById('del-workspace');
    if (!ws || ws.classList.contains('is-hidden')) return;
    const visible = document.querySelector('.ag-ws-panel-del:not(.is-hidden)');
    const panel = visible ? visible.getAttribute('data-del-panel') : null;
    if (panel === 'del-afiliaciones') void loadDelegadoAfiliacionesPanel();
    else if (panel === 'del-carnet') void loadDelegadoCarnetPanel();
    else if (panel === 'del-traspasos') void loadDelegadoTraspasosPanel(true);
}

function onDelegadoTorneoSelect(torneoId) {
    const id = typeof torneoId === 'number' ? torneoId : parseInt(String(torneoId), 10) || 0;
    if (id < 1) return;
    listState.delegadoTorneoId = id;
    try {
        sessionStorage.setItem('fvd_delegado_torneo_id', String(id));
    } catch (_) {}
    paintDelegadoTorneoBadges();
    refreshDelegadoTorneoDependentPanels();
}

async function initPanelJornadaBar() {
    if (!ctx || !ctx.puede_panel_admin) return;
    await initDelegadoTorneosBar({
        showWhen: (a) => a.logged && (a.rol === 'admingral' || a.rol === 'delegado'),
    });
    if (ctx.rol === 'delegado') {
        const tid = getDelegadoTorneoIdSeleccionado();
        if (tid > 0) onDelegadoTorneoSelect(tid);
        delegadoTorneosItemsCache = getDelegadoTorneosCache();
    }
}

/**
 * Guarda la tasa Bs/EUR al modificar el campo (debounce). Sin botón «Actualizar».
 *
 * @param {HTMLElement} root
 * @param {string} inputId
 */
function wireFinanzaTasaAutoSave(root, inputId) {
    const inp = /** @type {HTMLInputElement | null} */ (root.querySelector(`#${inputId}`));
    if (!inp) return;
    let seq = 0;
    let timer = 0;
    const persist = async () => {
        const my = ++seq;
        const tv = parseFloat(String(inp.value || '').replace(',', '.')) || 0;
        if (tv < 0.0001) {
            return;
        }
        const { res, data } = await fetchJson('api/finanza_tasa.php', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tasa_eur_bs: tv }),
        });
        if (my !== seq) {
            return;
        }
        if (res.ok && data.ok) {
            inp.value = String(Number(data.tasa_eur_bs));
        } else {
            showGlobalMsg(data.message || 'No se pudo guardar la tasa', false);
        }
    };
    const schedule = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(persist, 400);
    };
    inp.addEventListener('input', schedule);
    inp.addEventListener('change', schedule);
}

/** Contenedor con tipografía reducida (~30 %) para tablas de reporte / listados paginados */
function wrapReporte(html) {
    return `<div class="reporte-vista">${html}</div>`;
}

async function fetchJson(url, options = {}) {
    const res = await fetch(url, { credentials: 'same-origin', ...options });
    const data = await res.json().catch(() => ({}));
    return { res, data };
}

function showGlobalMsg(text, ok) {
    const el = document.getElementById('admin-global-msg');
    if (!el) return;
    el.textContent = text;
    el.style.display = 'block';
    el.className = 'form-msg ' + (ok ? 'ok' : 'err');
}

/** @type {any} */
let ctx = null;
let modalSaveHandler = null;

/**
 * @param {() => void | Promise<void>} onSave
 * @param {{ dialogClass?: string, afterRender?: (inner: HTMLElement) => void, hideSave?: boolean, readOnly?: boolean }} [options]
 */
function openModal(title, innerHtml, onSave, options = {}) {
    const dlg = document.getElementById('admin-modal');
    const titleEl = document.getElementById('admin-modal-title');
    const inner = document.getElementById('admin-modal-inner');
    if (!dlg || !inner || !titleEl) return;
    titleEl.textContent = title;
    dlg.className = options.dialogClass || 'admin-modal';
    inner.innerHTML = innerHtml;
    if (typeof options.afterRender === 'function') {
        options.afterRender(inner);
    }
    if (options.readOnly) {
        inner.querySelectorAll('input, select, textarea, button').forEach((inp) => {
            if (inp.closest('.admin-modal-actions')) return;
            inp.disabled = true;
        });
    }
    modalSaveHandler = typeof onSave === 'function' ? onSave : null;
    const saveBtn = document.getElementById('admin-modal-save');
    if (saveBtn) {
        const hide = options.hideSave === true || typeof onSave !== 'function';
        saveBtn.style.display = hide ? 'none' : '';
        saveBtn.disabled = hide;
    }
    dlg.showModal();
}

function paintSupervisionBadges() {
    const p = ctx?.supervision_pendientes;
    if (!p) return;
    const map = {
        todas: p.todas,
        afiliacion: p.afiliacion,
        carnet: p.carnet,
        traspaso: p.traspaso,
    };
    Object.keys(map).forEach((k) => {
        const el = document.querySelector(`[data-sup-badge="${k}"]`);
        if (!el) return;
        const n = Math.max(0, Number(map[k]) || 0);
        el.textContent = String(n);
        el.classList.toggle('agdash-badge--empty', n < 1);
    });
}

async function refreshCtxSupervisionPendientes() {
    if (!ctx || ctx.rol !== 'admingral') return;
    const { res, data } = await fetchJson('api/supervision_fvd.php?resumen=1');
    if (res.ok && data.ok && data.pendientes) {
        ctx.supervision_pendientes = data.pendientes;
        paintSupervisionBadges();
    }
}

function closeModal() {
    const dlg = document.getElementById('admin-modal');
    if (dlg) {
        dlg.close();
        dlg.className = 'admin-modal';
    }
    modalSaveHandler = null;
    const saveBtn = document.getElementById('admin-modal-save');
    if (saveBtn) {
        saveBtn.style.display = '';
        saveBtn.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    const gate = document.getElementById('admin-gate-msg');
    const app = document.getElementById('admin-app');
    const { res, data } = await fetchJson('api/auth_context.php');
    if (!res.ok || !data.logged || !data.puede_panel_admin) {
        if (gate) {
            gate.style.display = 'block';
            gate.innerHTML =
                '<p class="error">Debe iniciar sesión como administración general o delegado para acceder al panel.</p>' +
                '<p><a href="index.php" class="btn-secondary">Volver al inicio</a></p>';
        }
        return;
    }
    ctx = data;
    persistJornadaDesdeAuth(data);
    if (gate) gate.style.display = 'none';
    if (app) app.style.display = 'block';
    const pTr = parseInt(String(data.pendientes_traspaso_inscripcion || '0'), 10) || 0;
    document.getElementById('admin-modal-close')?.addEventListener('click', () => closeModal());
    document.getElementById('admin-modal-save')?.addEventListener('click', async () => {
        if (typeof modalSaveHandler === 'function') {
            await modalSaveHandler();
        }
    });
    await initPanelJornadaBar();
    if (data.rol === 'delegado') {
        const hi = document.getElementById('hdr-link-inicio');
        if (hi) hi.setAttribute('href', 'panel.html');
    }
    if (data.rol === 'admingral') {
        document.body.classList.remove('admin-fvd-panel--del');
        document.body.classList.add('admin-fvd-panel--ag');
        mountAdmingralPanel(app, pTr);
    } else {
        document.body.classList.remove('admin-fvd-panel--ag');
        document.body.classList.add('admin-fvd-panel--del');
        const hero = document.getElementById('admin-panel-hero');
        if (hero) {
            hero.classList.add('is-hidden');
            hero.setAttribute('aria-hidden', 'true');
        }
        mountDelegadoPanel(app, pTr);
    }
    mountPortalPerfilHeader(document.getElementById('main-nav'));
});

function showDelegadoDashboard() {
    document.getElementById('del-dash')?.classList.remove('is-hidden');
    document.getElementById('del-workspace')?.classList.add('is-hidden');
    document.querySelectorAll('.ag-ws-panel-del').forEach((p) => p.classList.add('is-hidden'));
}

/**
 * @param {'del-afiliaciones'|'del-traspasos'|'del-inscripcion-traspaso'|'del-carnet'} slice
 */
function navigateDelegadoWorkspace(slice, title) {
    if (!ctx || ctx.rol !== 'delegado') return;
    document.getElementById('del-dash')?.classList.add('is-hidden');
    const ws = document.getElementById('del-workspace');
    if (ws) ws.classList.remove('is-hidden');
    const tEl = document.getElementById('del-ws-title');
    if (tEl) tEl.textContent = title;
    document.querySelectorAll('.ag-ws-panel-del').forEach((p) => {
        p.classList.toggle('is-hidden', p.getAttribute('data-del-panel') !== slice);
    });
    if (slice === 'del-afiliaciones') {
        listState.delAtl = 1;
        void loadDelegadoAfiliacionesPanel();
    } else if (slice === 'del-traspasos') {
        listState.delTrRep = 1;
        listState._delTrRepQ = '';
        listState._delTrRepFiltroSol = 'todos';
        void loadDelegadoTraspasosPanel(true);
    } else if (slice === 'del-inscripcion-traspaso') void loadDelegadoInscripcionTraspasoPanel();
    else if (slice === 'del-carnet') {
        listState.delCar = 1;
        listState._delCarQ = '';
        listState._delCarFiltroSol = 'todos';
        void loadDelegadoCarnetPanel();
    }
}

/**
 * Panel delegado: dashboard tipo admin. gral. + áreas de trabajo.
 * @param {HTMLElement|null} app
 * @param {number} pTr
 */
function mountDelegadoPanel(app, pTr) {
    if (!app) return;
    listState.delAtl = 1;
    listState.delTrRep = 1;
    listState._delTrRepQ = '';
    listState._delTrRepFiltroSol = 'todos';
    listState.delCar = 1;
    listState._delCarQ = '';
    listState._delCarFiltroSol = 'todos';
    delegadoTraspasoCache = null;
    delegadoCarnetCache = null;
    const trBanner =
        pTr > 0
            ? `<div id="admin-traspaso-banner" class="admin-traspaso-banner" role="status"><strong>Alerta traspaso:</strong> ${esc(String(pTr))} inscripción(es) con <code>traspaso=1</code> pendientes de la FVD. Revise <strong>Traspasos</strong> o <a href="inscripciones.html" class="ag-inline-link">Inscripciones</a>.</div>`
            : '';
    const aid = ctx && ctx.asociacion_id != null ? Number(ctx.asociacion_id) : 0;
    const tidFab = getDelegadoTorneoId();
    const insQs = tidFab > 0 ? `?torneo_id=${encodeURIComponent(String(tidFab))}` : '';
    const fabHref = ctx.puede_afiliar_atleta
        ? `afiliar_atleta.html?modo=nuevo${tidFab > 0 ? `&torneo_id=${encodeURIComponent(String(tidFab))}` : ''}`
        : `inscripciones.html${insQs}`;
    const finTor = leerTorneoFinanzasGuardado();
    const finTq = finTor > 0 ? torneoFinanzasQs(finTor) : '';
    const finBase = aid > 0 ? `finanzas_asociacion.html?id=${encodeURIComponent(String(aid))}${finTq}` : '#';
    const infAsoc =
        aid > 0 ? `informes.html?asoc=${encodeURIComponent(String(aid))}${finTq}` : 'informes.html';
    const finOtros = aid > 0 ? `${finBase}#fin-aso-torneo-bar` : '#';
    app.innerHTML = `
    <div id="admin-panel-hero" class="admin-panel-hero is-hidden" aria-hidden="true">
        <img src="img/panel-admin-gral.png" width="1200" height="400" alt="" loading="lazy">
    </div>
    <header class="ag-panel-page-head ag-panel-page-head--deleg">
        <h1 class="ag-panel-page-title">Administración de asociación</h1>
        <p class="ag-panel-page-sub">Accesos operativos y finanzas de su jurisdicción provincial.</p>
    </header>
    ${htmlDelegadoAsocStrip()}
    <p class="admin-intro admin-intro--deleg" id="admin-role-hint">Edite logo y datos de contacto en <strong>Mi organización</strong>. Los botones abren cada módulo sin salir de su asociación.</p>
    <div class="admin-app-delegado">
    <div id="del-dash" class="del-dash-shell">
        <div class="del-dash-top">
            <input type="search" class="del-dash-quick" id="del-dash-quick" placeholder="Acción rápida (Ctrl+K)" autocomplete="off" title="Pulse Ctrl+K; Enter abre Inscripciones al torneo">
            <span class="del-dash-online" title="Sesión activa"><span class="del-dash-online-dot" aria-hidden="true"></span> En línea</span>
        </div>
        <div id="del-traspaso-slot">${trBanner}</div>
        <div class="ag-panel agdash-board agdash-board--deleg3 agdash-board--ref">
            <div class="agdash-grid agdash-grid--deleg">
            <section class="agdash-col agdash-col--del-op agdash-col--servicios" aria-labelledby="agdash-h-del-op">
                <h2 id="agdash-h-del-op" class="agdash-col-h">Operaciones</h2>
                <div class="agdash-items">
                    <button type="button" class="agdash-item agdash-item--svc" data-del-ws="del-mi-org" data-del-title="Mi organización"><span class="agdash-item-label">Mi organización</span><span class="agdash-item-ic" aria-hidden="true">⌂</span></button>
                    <button type="button" class="agdash-item agdash-item--sup2" data-del-ws="del-afiliaciones" data-del-title="Afiliaciones"><span class="agdash-item-label">Afiliación</span><span class="agdash-item-ic" aria-hidden="true">✚</span></button>
                    <button type="button" class="agdash-item agdash-item--sup3" data-del-ws="del-carnet" data-del-title="Carnet — reporte"><span class="agdash-item-label">Carnet</span><span class="agdash-item-ic" aria-hidden="true">▭</span></button>
                    <button type="button" class="agdash-item agdash-item--sup4" data-del-ws="del-traspasos" data-del-title="Traspasos — reporte"><span class="agdash-item-label">Traspaso</span><span class="agdash-item-ic" aria-hidden="true">⇄</span></button>
                </div>
            </section>
            <section class="agdash-col agdash-col--del-ins agdash-col--operaciones" aria-labelledby="agdash-h-del-ins">
                <h2 id="agdash-h-del-ins" class="agdash-col-h">Inscripciones</h2>
                <div class="agdash-items">
                    <a href="inscripciones.html${insQs}" class="agdash-item agdash-item--op1 agdash-item--link"><span class="agdash-item-label">Inscripciones</span><span class="agdash-item-ic" aria-hidden="true">📋</span></a>
                    <a href="inscripciones_admin.html${insQs}" class="agdash-item agdash-item--op2 agdash-item--link"><span class="agdash-item-label">Administrador de inscripciones</span><span class="agdash-item-ic" aria-hidden="true">⚙</span></a>
                </div>
            </section>
            <section class="agdash-col agdash-col--del-fin agdash-col--finanzas" aria-labelledby="agdash-h-del-fin">
                <h2 id="agdash-h-del-fin" class="agdash-col-h">Finanzas</h2>
                <div class="agdash-items">
                    <a href="${finBase}" class="agdash-item agdash-item--fn1 agdash-item--link"><span class="agdash-item-label">Estado de cuentas</span><span class="agdash-item-ic" aria-hidden="true">€</span></a>
                    <a href="${infAsoc}" class="agdash-item agdash-item--fn2 agdash-item--link"><span class="agdash-item-label">Informe / conceptos</span><span class="agdash-item-ic" aria-hidden="true">📊</span></a>
                </div>
            </section>
            </div>
        </div>
    </div>
    <a class="del-dash-fab" href="${fabHref}" title="Nueva afiliación o inscripciones" aria-label="Acceso rápido">+</a>
    <div id="del-workspace" class="ag-workspace ag-workspace--deleg is-hidden">
        <div class="ag-ws-bar">
            <button type="button" class="btn-secondary" id="del-btn-home">← Panel asociación</button>
            <span id="del-ws-title" class="ag-ws-title"></span>
        </div>
        <div id="del-ws-panels">
            <section class="ag-ws-panel ag-ws-panel-del is-hidden" data-del-panel="del-afiliaciones" id="panel-del-afiliaciones"></section>
            <section class="ag-ws-panel ag-ws-panel-del is-hidden" data-del-panel="del-traspasos" id="panel-del-traspasos"></section>
            <section class="ag-ws-panel ag-ws-panel-del is-hidden" data-del-panel="del-carnet" id="panel-del-carnet"></section>
            <section class="ag-ws-panel ag-ws-panel-del is-hidden" data-del-panel="del-inscripcion-traspaso" id="panel-del-inscripcion-traspaso"></section>
        </div>
    </div>
    </div>
    <p id="admin-global-msg" class="form-msg" style="display: none;"></p>`;
    app.querySelectorAll('[data-del-ws]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const ws = btn.getAttribute('data-del-ws') || '';
            const title = btn.getAttribute('data-del-title') || '';
            if (ws === 'del-mi-org') {
                void openMiOrganizacionDesdePanel();
                return;
            }
            navigateDelegadoWorkspace(ws, title);
        });
    });
    document.getElementById('del-btn-home')?.addEventListener('click', () => showDelegadoDashboard());
    wireDelegadoQuickSearch();
    applyDelegadoPanelHash();
}

function wireDelegadoQuickSearch() {
    const inp = document.getElementById('del-dash-quick');
    if (!inp) return;
    inp.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            const t = getDelegadoTorneoId();
            window.location.href = t > 0 ? `inscripciones.html?torneo_id=${encodeURIComponent(String(t))}` : 'inscripciones.html';
        }
    });
    document.addEventListener(
        'keydown',
        (ev) => {
            if (!document.getElementById('del-dash')) return;
            if (ev.ctrlKey && (ev.key === 'k' || ev.key === 'K')) {
                ev.preventDefault();
                document.getElementById('del-dash-quick')?.focus();
            }
        },
        { capture: true }
    );
}

function applyDelegadoPanelHash() {
    const raw = (window.location.hash || '').replace(/^#/, '');
    const map = {
        'del-traspasos': ['del-traspasos', 'Traspasos — reporte'],
        'del-afiliaciones': ['del-afiliaciones', 'Afiliaciones'],
        'del-inscripcion-traspaso': ['del-inscripcion-traspaso', 'Inscripción y traspaso'],
        'del-carnet': ['del-carnet', 'Carnet — reporte'],
    };
    const m = map[raw];
    if (!m) return;
    navigateDelegadoWorkspace(m[0], m[1]);
    try {
        history.replaceState(null, '', window.location.pathname + window.location.search);
    } catch (_) {}
}

/** Portal: 9 = pendiente de pago / anualidad (no indica trámite de Nº FVD). */
function badgePortalAnualidadDelegado(usuarioStatus) {
    const st = usuarioStatus === null || usuarioStatus === undefined || usuarioStatus === '' ? null : Number(usuarioStatus);
    if (st === null || Number.isNaN(st)) {
        return '<span class="ag-del-badge ag-del-badge--na">Sin cuenta portal</span>';
    }
    if (st === 0) {
        return '<span class="ag-del-badge ag-del-badge--ok">Portal activo</span>';
    }
    if (st === 9) {
        return '<span class="ag-del-badge ag-del-badge--pend">Portal: pendiente anualidad / pago (9)</span>';
    }
    return `<span class="ag-del-badge ag-del-badge--misc">Portal estatus ${esc(String(st))}</span>`;
}

/** Alta en federación: Nº FVD 0 = pendiente de ingreso; &gt; 0 = ya asignado. */
function badgeAltaFederacionDelegado(a) {
    const nf = Number(a.numfvd) || 0;
    if (nf < 1) {
        return '<span class="ag-del-badge ag-del-badge--pend">Sin Nº FVD (alta federación pendiente)</span>';
    }
    return '<span class="ag-del-badge ag-del-badge--ok">Con Nº FVD (aceptado en federación)</span>';
}

function escFechaMovTorneoCorta(a) {
    const raw = a.movimiento_updated_at || a.movimiento_created_at || '';
    if (!raw) return '';
    return esc(String(raw).replace('T', ' ').slice(0, 16));
}

async function loadDelegadoAfiliacionesPanel() {
    const el = document.getElementById('panel-del-afiliaciones');
    if (!el || !ctx) return;
    const tid = getDelegadoTorneoId();
    if (!tid) {
        el.innerHTML =
            '<p class="error">Seleccione un torneo activo en la franja superior para registrar movimientos de afiliación.</p>';
        return;
    }
    el.innerHTML = '<p class="ag-muted">Cargando afiliaciones…</p>';
    const { res, data } = await fetchJson(`api/delegado_reporte_afiliaciones_torneo.php?torneo_id=${encodeURIComponent(String(tid))}`);
    if (!res.ok || !data.ok) {
        el.innerHTML = `<p class="error">${esc(data.message || 'Error al cargar')}</p>`;
        return;
    }
    let rows = Array.isArray(data.items) ? data.items : [];
    const per = REPORTE_FILAS_POR_PAGINA;
    const total = rows.length;
    const slice = reporteSliceCliente(rows, listState.delAtl, per);
    const pager = reporteHtmlPaginador('del-atl', listState.delAtl, total, per);
    const iframeSrc = `afiliar_atleta.html?modo=nuevo&torneo_id=${encodeURIComponent(String(tid))}&embed=1`;
    const nuevo = ctx.puede_afiliar_atleta
        ? `<div class="del-afiliar-frame-wrap"><iframe class="del-afiliar-frame" title="Nueva afiliación" src="${esc(iframeSrc)}"></iframe></div>`
        : '<p class="ag-muted">Sin permiso para crear afiliaciones.</p>';
    const tr = slice
        .map((a) => {
            const c = encodeURIComponent(String(a.cedula || '').trim());
            const verHref = `afiliar_atleta.html?modo=ver&cedula=${c}&torneo_id=${encodeURIComponent(String(tid))}`;
            const edHref = `afiliar_atleta.html?modo=editar&cedula=${c}&torneo_id=${encodeURIComponent(String(tid))}`;
            const sx = a.sexo === 1 ? 'M' : a.sexo === 2 ? 'F' : '—';
            const fmov = escFechaMovTorneoCorta(a);
            const movLine =
                fmov !== ''
                    ? `<div class="ag-muted ag-del-mov-ts" title="Última actualización de movimiento_torneo en este torneo">${fmov}</div>`
                    : '<div class="ag-muted ag-del-mov-ts">Sin fila de movimiento en este torneo</div>';
            return `<tr>
        <td class="ag-num">${esc(String(a.numfvd ?? ''))}</td>
        <td>${esc(a.cedula)}</td>
        <td>${esc(a.nombre)}</td>
        <td>${esc(sx)}</td>
        <td class="ag-del-afili-est-cell"><div class="ag-del-afili-est-stack">${badgeAltaFederacionDelegado(a)}<br>${badgePortalAnualidadDelegado(a.usuario_status)}</div>${movLine}</td>
        <td><a class="btn-secondary btn-sm" href="${verHref}">Ver</a> <a class="btn-secondary btn-sm" href="${edHref}">Editar</a></td>
      </tr>`;
        })
        .join('');
    el.innerHTML = `
    <div class="ag-del-afiliaciones-toolbar">
      ${nuevo}
      <div class="ag-del-afiliaciones-hint ag-muted">Torneo <strong>#${esc(String(tid))}</strong>. Listado desde <code>movimiento_torneo</code> con <strong>afiliación = 1</strong> en su asociación y este torneo: <strong>Nº FVD 0</strong> = pendiente de aceptación en FVD; <strong>Nº FVD &gt; 0</strong> = aceptado. Datos de persona desde <code>usuarios</code>. Orden: pendientes primero, luego fecha de movimiento, nombre.</div>
    </div>
    <div class="reporte-vista">${pager}
    <div class="admin-fin-pane"><table class="fvd-table admin-crud-table admin-crud-table--compact text-sm">
      <thead><tr><th class="ag-num">Nº FVD</th><th>Cédula</th><th>Nombre</th><th>Sexo</th><th>Alta federación / portal / movimiento</th><th>Acciones</th></tr></thead>
      <tbody>${tr || '<tr><td colspan="6" class="ag-muted">Ninguna fila en <code>movimiento_torneo</code> con <strong>afiliación = 1</strong> para este torneo y su asociación. Use el formulario de arriba para registrar un nuevo afiliado.</td></tr>'}</tbody>
    </table></div></div>`;
    reporteLigarPaginador('del-atl', listState.delAtl, total, (np) => {
        listState.delAtl = np;
        void loadDelegadoAfiliacionesPanel();
    }, per);
}

function movimientoBadgesHtml(row) {
    const af = Number(row.afiliacion) >= 1 ? '<span class="ins-badge">AF</span>' : '';
    const an = Number(row.anualidad) >= 1 ? '<span class="ins-badge">AN</span>' : '';
    const ca = Number(row.carnet) >= 1 ? '<span class="ins-badge">CA</span>' : '';
    const tp = Number(row.traspaso) >= 1 ? '<span class="ins-badge ins-badge--tp">TP</span>' : '';
    const bits = (af + an + ca + tp).trim();
    return bits !== '' ? bits : '<span class="ag-muted">—</span>';
}

/**
 * Fila del listado delegado «Carnet»: filtro solicitudes (criterio supervisión) + texto cédula / Nº FVD / nombre.
 * @param {any} a
 */
function delegadoCarnetFilaPasaFiltros(a) {
    if (listState._delCarFiltroSol === 'solicitados') {
        const ca = Number(a.carnet) || 0;
        const af = Number(a.afiliacion) || 0;
        const tp = Number(a.traspaso) || 0;
        const nfM = Number(a.numfvd) || 0;
        const afiliPendiente = af === 1 && nfM < 1;
        if (!(ca >= 1 && !afiliPendiente && tp !== 1)) {
            return false;
        }
    }
    const rawQ = String(listState._delCarQ || '').trim();
    const q = rawQ.toLowerCase().replace(/\s+/g, '');
    if (q === '') {
        return true;
    }
    const ced = String(a.cedula || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, '');
    const nom = String(a.nombre || '')
        .trim()
        .toLowerCase();
    const nf = String(a.numfvd ?? '').trim();
    if (ced.includes(q) || nf.toLowerCase().includes(q)) {
        return true;
    }
    const qn = q.replace(/\D/g, '');
    if (qn !== '' && nf.replace(/\D/g, '').includes(qn)) {
        return true;
    }
    return nom.includes(rawQ.toLowerCase());
}

/**
 * Fila del reporte delegado «Traspaso»: filtro solicitudes (traspaso ≥ 1) + búsqueda cédula / Nº FVD / nombre.
 * @param {any} a
 */
function delegadoTraspasoReporteFilaPasaFiltros(a) {
    if (listState._delTrRepFiltroSol === 'solicitados') {
        const tp = Number(a.traspaso) || 0;
        if (!(tp >= 1)) {
            return false;
        }
    }
    const rawQ = String(listState._delTrRepQ || '').trim();
    const q = rawQ.toLowerCase().replace(/\s+/g, '');
    if (q === '') {
        return true;
    }
    const ced = String(a.cedula || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, '');
    const nom = String(a.nombre || '')
        .trim()
        .toLowerCase();
    const nf = String(a.numfvd ?? '').trim();
    if (ced.includes(q) || nf.toLowerCase().includes(q)) {
        return true;
    }
    const qn = q.replace(/\D/g, '');
    if (qn !== '' && nf.replace(/\D/g, '').includes(qn)) {
        return true;
    }
    return nom.includes(rawQ.toLowerCase());
}

/**
 * @param {HTMLElement} el
 * @param {any[]} items
 * @param {number} tid
 */
function renderDelegadoCarnetPanelContent(el, items, tid) {
    const per = REPORTE_FILAS_POR_PAGINA;
    const base = Array.isArray(items) ? items : [];
    const metaPag = reporteMetaPagina(base.length, listState.delCar, per);
    listState.delCar = metaPag.pagina;
    const slice = reporteSliceCliente(base, listState.delCar, per);
    const visibles = slice.filter(delegadoCarnetFilaPasaFiltros);
    const totalCoinc = base.filter(delegadoCarnetFilaPasaFiltros).length;
    const qEsc = esc(String(listState._delCarQ || ''));
    const solTodos = listState._delCarFiltroSol !== 'solicitados';
    const pagerTop = reporteHtmlPaginador('del-car', listState.delCar, base.length, per);
    const pagerBot = reporteHtmlPaginador('del-car-b', listState.delCar, base.length, per);
    const metaTxt =
        base.length < 1
            ? 'Sin afiliados en su asociación para este torneo.'
            : `Paginación sobre los ${base.length} afiliado(s) (pág. ${metaPag.pagina}/${metaPag.totalPaginas}). Filtro: ${totalCoinc} coincidencia(s) en total; en esta página, ${visibles.length} visible(s).`;
    const rows = visibles
        .map((a) => {
            const uid = Number(a.user_id) || 0;
            const c = encodeURIComponent(String(a.cedula || '').trim());
            const verHref = `afiliar_atleta.html?modo=ver&cedula=${c}&torneo_id=${encodeURIComponent(String(tid))}`;
            const edHref = `afiliar_atleta.html?modo=editar&cedula=${c}&torneo_id=${encodeURIComponent(String(tid))}`;
            const sx = a.sexo === 1 ? 'M' : a.sexo === 2 ? 'F' : '—';
            return `<tr>
        <td class="ag-num">${esc(String(a.numfvd ?? ''))}</td>
        <td>${esc(a.cedula)}</td>
        <td>${esc(a.nombre)}</td>
        <td>${esc(sx)}</td>
        <td>${movimientoBadgesHtml(a)}</td>
        <td>
          <button type="button" class="del-carnet-ic" data-del-sol-carnet="${esc(String(uid))}" title="Solicitar carnet (notifica a administración general)" aria-label="Solicitar carnet">📇</button>
          <a class="btn-secondary btn-sm" href="${verHref}">Ver</a>
          <a class="btn-secondary btn-sm" href="${edHref}">Editar</a>
        </td>
      </tr>`;
        })
        .join('');
    el.innerHTML = `
    <p class="ag-muted">Torneo <strong>#${esc(String(tid))}</strong>. Aquí registra la solicitud de carnet en <code>movimiento_torneo</code> y se genera el aviso al <strong>administrador general</strong> (quien aprueba o rechaza en <strong>Panel → Supervisión FVD → Carnets</strong>).</p>
    <div class="ag-inf-carnet-toolbar-sticky">
    <div class="ag-inf-carnet-toolbar fvd-form fvd-form--inline">
      <label class="ag-inf-carnet-fld"><span class="ag-inf-carnet-lbl">Buscar</span>
        <input type="search" id="del-car-q" class="admin-search" autocomplete="off" placeholder="Cédula, Nº FVD o nombre" value="${qEsc}">
      </label>
      <button type="button" class="btn-secondary btn-sm" id="del-car-buscar">Buscar</button>
      <label class="ag-inf-carnet-fld"><span class="ag-inf-carnet-lbl">Listado</span>
        <select id="del-car-filtro-sol" class="admin-search">
          <option value="todos"${solTodos ? ' selected' : ''}>Todos los afiliados</option>
          <option value="solicitados"${!solTodos ? ' selected' : ''}>Solo solicitudes de carnet</option>
        </select>
      </label>
      <span class="ag-muted ag-inf-carnet-meta" id="del-car-meta">${esc(metaTxt)}</span>
    </div>
    </div>
    <div class="reporte-vista">${pagerTop}
    <div class="admin-fin-pane"><table class="fvd-table admin-crud-table admin-crud-table--compact text-sm">
      <thead><tr><th class="ag-num">Nº FVD</th><th>Cédula</th><th>Nombre</th><th>Sexo</th><th>Movimiento</th><th>Acciones</th></tr></thead>
      <tbody>${
          rows ||
          (base.length < 1
              ? '<tr><td colspan="6" class="ag-muted">Sin afiliados.</td></tr>'
              : '<tr><td colspan="6" class="ag-muted">Ninguna fila de esta página cumple el filtro. Cambie de página o ajuste búsqueda / listado.</td></tr>')
      }</tbody>
    </table></div>${pagerBot}</div>`;
    const onPag = (np) => {
        listState.delCar = np;
        if (delegadoCarnetCache && delegadoCarnetCache.tid === tid) {
            renderDelegadoCarnetPanelContent(el, delegadoCarnetCache.items, tid);
        }
    };
    reporteLigarPaginador('del-car', listState.delCar, base.length, onPag, per);
    reporteLigarPaginador('del-car-b', listState.delCar, base.length, onPag, per);
    wireDelegadoCarnetToolbar(el, tid);
    el.querySelectorAll('[data-del-sol-carnet]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const uid = parseInt(String(btn.getAttribute('data-del-sol-carnet') || '0'), 10) || 0;
            if (uid < 1) return;
            btn.setAttribute('disabled', 'disabled');
            try {
                const r = await fetch('api/delegado_movimiento_solicitar_carnet.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: uid, torneo_id: tid }),
                    credentials: 'same-origin',
                });
                const d = await r.json().catch(() => ({}));
                if (r.ok && d.ok) {
                    window.alert(d.message || 'Solicitud registrada.');
                    void loadDelegadoCarnetPanel({ preserveFilters: true });
                } else {
                    window.alert(d.message || 'No se pudo registrar la solicitud.');
                    btn.removeAttribute('disabled');
                }
            } catch (_) {
                window.alert('Error de conexión.');
                btn.removeAttribute('disabled');
            }
        });
    });
}

/**
 * @param {HTMLElement} el
 * @param {number} tid
 */
function wireDelegadoCarnetToolbar(el, tid) {
    const inp = /** @type {HTMLInputElement | null} */ (el.querySelector('#del-car-q'));
    const btn = el.querySelector('#del-car-buscar');
    const sel = el.querySelector('#del-car-filtro-sol');
    const apply = () => {
        if (!delegadoCarnetCache || delegadoCarnetCache.tid !== tid) {
            return;
        }
        listState._delCarQ = String(document.getElementById('del-car-q')?.value || '');
        const s = document.getElementById('del-car-filtro-sol');
        listState._delCarFiltroSol = String(s?.value || 'todos') === 'solicitados' ? 'solicitados' : 'todos';
        listState.delCar = 1;
        renderDelegadoCarnetPanelContent(el, delegadoCarnetCache.items, tid);
    };
    inp?.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            window.clearTimeout(delCarDebTimer);
            delCarDebTimer = 0;
            apply();
        }
    });
    inp?.addEventListener('input', () => {
        window.clearTimeout(delCarDebTimer);
        delCarDebTimer = window.setTimeout(() => {
            delCarDebTimer = 0;
            listState._delCarQ = String(document.getElementById('del-car-q')?.value || '');
            listState.delCar = 1;
            if (delegadoCarnetCache && delegadoCarnetCache.tid === tid) {
                renderDelegadoCarnetPanelContent(el, delegadoCarnetCache.items, tid);
            }
        }, 320);
    });
    btn?.addEventListener('click', () => {
        window.clearTimeout(delCarDebTimer);
        delCarDebTimer = 0;
        apply();
    });
    sel?.addEventListener('change', () => {
        window.clearTimeout(delCarDebTimer);
        delCarDebTimer = 0;
        apply();
    });
}

/**
 * @param {HTMLElement} el
 * @param {number} tid
 * @param {HTMLSelectElement} selectEl
 */
async function delegadoTraspasoSubmitPorSelect(el, tid, selectEl) {
    const uid = parseInt(String(selectEl.getAttribute('data-del-tr-uid') || '0'), 10) || 0;
    const dest = parseInt(String(selectEl.value || '0'), 10) || 0;
    const msg = el.querySelector('#del-tr-sol-msg');
    if (uid < 1 || dest < 1) {
        return;
    }
    selectEl.setAttribute('disabled', 'disabled');
    try {
        const r = await fetch('api/delegado_movimiento_solicitar_traspaso.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: uid, torneo_id: tid, asociacion_destino_id: dest }),
            credentials: 'same-origin',
        });
        const d = await r.json().catch(() => ({}));
        if (msg) {
            msg.style.display = 'block';
            msg.className = 'form-msg ' + (r.ok && d.ok ? 'ok' : 'err');
            msg.textContent = d.message || (r.ok ? 'Registrado.' : 'Error.');
        }
        if (r.ok && d.ok) {
            void loadDelegadoTraspasosPanel(true, { preserveTrRepFilters: true });
        } else {
            selectEl.removeAttribute('disabled');
            selectEl.value = '';
        }
    } catch (_) {
        if (msg) {
            msg.style.display = 'block';
            msg.className = 'form-msg err';
            msg.textContent = 'Error de conexión.';
        }
        selectEl.removeAttribute('disabled');
        selectEl.value = '';
    }
}

/**
 * @param {HTMLElement} el
 * @param {any[]} repAll
 * @param {number} tid
 * @param {any[]} asocs
 */
function renderDelegadoTraspasoReporteContent(el, repAll, tid, asocs) {
    const per = REPORTE_FILAS_POR_PAGINA;
    const base = Array.isArray(repAll) ? repAll : [];
    const destOptsInner = (Array.isArray(asocs) ? asocs : [])
        .map((a) => `<option value="${esc(String(a.id))}">${esc(a.nombre)}</option>`)
        .join('');
    const metaPag = reporteMetaPagina(base.length, listState.delTrRep, per);
    listState.delTrRep = metaPag.pagina;
    const slice = reporteSliceCliente(base, listState.delTrRep, per);
    const visibles = slice.filter(delegadoTraspasoReporteFilaPasaFiltros);
    const totalCoinc = base.filter(delegadoTraspasoReporteFilaPasaFiltros).length;
    const qEsc = esc(String(listState._delTrRepQ || ''));
    const solTodos = listState._delTrRepFiltroSol !== 'solicitados';
    const pagerTop = reporteHtmlPaginador('del-tr-rep', listState.delTrRep, base.length, per);
    const pagerBot = reporteHtmlPaginador('del-tr-rep-b', listState.delTrRep, base.length, per);
    const metaTxt =
        base.length < 1
            ? 'Sin afiliados en su asociación para este torneo.'
            : `Paginación sobre los ${base.length} afiliado(s) (pág. ${metaPag.pagina}/${metaPag.totalPaginas}). Filtro: ${totalCoinc} coincidencia(s) en total; en esta página, ${visibles.length} visible(s).`;
    const blankOpt = '<option value="">— Elija destino —</option>';
    const rows = visibles
        .map((a) => {
            const uid = Number(a.user_id) || 0;
            const c = encodeURIComponent(String(a.cedula || '').trim());
            const verHref = `afiliar_atleta.html?modo=ver&cedula=${c}&torneo_id=${encodeURIComponent(String(tid))}`;
            const edHref = `afiliar_atleta.html?modo=editar&cedula=${c}&torneo_id=${encodeURIComponent(String(tid))}`;
            const sx = a.sexo === 1 ? 'M' : a.sexo === 2 ? 'F' : '—';
            const sid = esc(String(uid));
            return `<tr>
        <td class="ag-num">${esc(String(a.numfvd ?? ''))}</td>
        <td>${esc(a.cedula)}</td>
        <td>${esc(a.nombre)}</td>
        <td>${esc(sx)}</td>
        <td>${movimientoBadgesHtml(a)}</td>
        <td>
          <select class="admin-search del-tr-rep-dest" data-del-tr-uid="${sid}" title="Elegir asociación destino registra la solicitud de traspaso" aria-label="Asociación destino del traspaso (al elegir se envía la solicitud)">
            ${blankOpt}${destOptsInner}
          </select>
        </td>
        <td>
          <a class="btn-secondary btn-sm" href="${verHref}">Ver</a>
          <a class="btn-secondary btn-sm" href="${edHref}">Editar</a>
        </td>
      </tr>`;
        })
        .join('');
    const slot = el.querySelector('#del-tr-rep-slot');
    if (!slot) return;
    slot.innerHTML = `
    <h3 class="ag-subtitle">Afiliados y traspaso (torneo #${esc(String(tid))})</h3>
    <p class="ag-muted">En cada fila, elija la asociación destino en el desplegable: la solicitud de traspaso se envía <strong>automáticamente</strong>. Se registra en <code>movimiento_torneo</code> y se notifica a administración general.</p>
    <div class="ag-inf-carnet-toolbar-sticky">
    <div class="ag-inf-carnet-toolbar fvd-form fvd-form--inline">
      <label class="ag-inf-carnet-fld"><span class="ag-inf-carnet-lbl">Buscar</span>
        <input type="search" id="del-tr-rep-q" class="admin-search" autocomplete="off" placeholder="Cédula, Nº FVD o nombre" value="${qEsc}">
      </label>
      <button type="button" class="btn-secondary btn-sm" id="del-tr-rep-buscar">Buscar</button>
      <label class="ag-inf-carnet-fld"><span class="ag-inf-carnet-lbl">Listado</span>
        <select id="del-tr-rep-filtro-sol" class="admin-search">
          <option value="todos"${solTodos ? ' selected' : ''}>Todos los afiliados</option>
          <option value="solicitados"${!solTodos ? ' selected' : ''}>Solo solicitudes de traspaso</option>
        </select>
      </label>
      <span class="ag-muted ag-inf-carnet-meta" id="del-tr-rep-meta">${esc(metaTxt)}</span>
    </div>
    </div>
    <p id="del-tr-sol-msg" class="form-msg" style="display:none;"></p>
    <div class="reporte-vista">${pagerTop}
    <div class="admin-fin-pane"><table class="fvd-table admin-crud-table admin-crud-table--compact text-sm">
      <thead><tr><th class="ag-num">Nº FVD</th><th>Cédula</th><th>Nombre</th><th>Sexo</th><th>Movimiento</th><th>Traspaso a</th><th>Acciones</th></tr></thead>
      <tbody>${
          rows ||
          (base.length < 1
              ? '<tr><td colspan="7" class="ag-muted">Sin afiliados.</td></tr>'
              : '<tr><td colspan="7" class="ag-muted">Ninguna fila de esta página cumple el filtro. Cambie de página o ajuste búsqueda / listado.</td></tr>')
      }</tbody>
    </table></div>${pagerBot}</div>`;
    const onPag = (np) => {
        listState.delTrRep = np;
        const c = delegadoTraspasoCache;
        if (c && c.torneoSeleccionadoId === tid) {
            renderDelegadoTraspasoReporteContent(el, c.reporteAfiliados || [], tid, c.otrasAsocs || []);
        }
    };
    reporteLigarPaginador('del-tr-rep', listState.delTrRep, base.length, onPag, per);
    reporteLigarPaginador('del-tr-rep-b', listState.delTrRep, base.length, onPag, per);
    wireDelegadoTraspasoReporteToolbar(el, tid);
    el.querySelectorAll('select.del-tr-rep-dest').forEach((sel) => {
        sel.addEventListener('change', () => {
            void delegadoTraspasoSubmitPorSelect(el, tid, /** @type {HTMLSelectElement} */ (sel));
        });
    });
}

/**
 * @param {HTMLElement} el
 * @param {number} tid
 */
function wireDelegadoTraspasoReporteToolbar(el, tid) {
    const inp = /** @type {HTMLInputElement | null} */ (el.querySelector('#del-tr-rep-q'));
    const btn = el.querySelector('#del-tr-rep-buscar');
    const sel = el.querySelector('#del-tr-rep-filtro-sol');
    const apply = () => {
        const c = delegadoTraspasoCache;
        if (!c || c.torneoSeleccionadoId !== tid) {
            return;
        }
        listState._delTrRepQ = String(el.querySelector('#del-tr-rep-q')?.value || '');
        const s = el.querySelector('#del-tr-rep-filtro-sol');
        listState._delTrRepFiltroSol = String(s?.value || 'todos') === 'solicitados' ? 'solicitados' : 'todos';
        listState.delTrRep = 1;
        renderDelegadoTraspasoReporteContent(el, c.reporteAfiliados || [], tid, c.otrasAsocs || []);
    };
    inp?.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            window.clearTimeout(delTrRepDebTimer);
            delTrRepDebTimer = 0;
            apply();
        }
    });
    inp?.addEventListener('input', () => {
        window.clearTimeout(delTrRepDebTimer);
        delTrRepDebTimer = window.setTimeout(() => {
            delTrRepDebTimer = 0;
            listState._delTrRepQ = String(el.querySelector('#del-tr-rep-q')?.value || '');
            listState.delTrRep = 1;
            const c = delegadoTraspasoCache;
            if (c && c.torneoSeleccionadoId === tid) {
                renderDelegadoTraspasoReporteContent(el, c.reporteAfiliados || [], tid, c.otrasAsocs || []);
            }
        }, 320);
    });
    btn?.addEventListener('click', () => {
        window.clearTimeout(delTrRepDebTimer);
        delTrRepDebTimer = 0;
        apply();
    });
    sel?.addEventListener('change', () => {
        window.clearTimeout(delTrRepDebTimer);
        delTrRepDebTimer = 0;
        apply();
    });
}

/**
 * @param {{ preserveFilters?: boolean }} [opts]
 */
async function loadDelegadoCarnetPanel(opts = {}) {
    const el = document.getElementById('panel-del-carnet');
    if (!el || !ctx) return;
    const tid = getDelegadoTorneoId();
    if (!tid) {
        delegadoCarnetCache = null;
        el.innerHTML =
            '<p class="error">Seleccione un torneo activo en la franja superior para solicitar carnet en ese evento.</p>';
        return;
    }
    if (!opts.preserveFilters) {
        listState.delCar = 1;
        listState._delCarQ = '';
        listState._delCarFiltroSol = 'todos';
    }
    window.clearTimeout(delCarDebTimer);
    delCarDebTimer = 0;
    el.innerHTML = '<p class="ag-muted">Cargando reporte…</p>';
    const { res, data } = await fetchJson(`api/delegado_reporte_afiliados_torneo.php?torneo_id=${encodeURIComponent(String(tid))}`);
    if (!res.ok || !data.ok) {
        delegadoCarnetCache = null;
        el.innerHTML = `<p class="error">${esc(data.message || 'Error al cargar')}</p>`;
        return;
    }
    const items = Array.isArray(data.items) ? data.items : [];
    delegadoCarnetCache = { items, tid };
    renderDelegadoCarnetPanelContent(el, items, tid);
}

/** @type {{ reporteAfiliados: any[], otrasAsocs: any[], torneoSeleccionadoId: number } | null} */
let delegadoTraspasoCache = null;

/**
 * @param {boolean} [refresh]
 * @param {{ preserveTrRepFilters?: boolean }} [opts]
 */
async function loadDelegadoTraspasosPanel(refresh = true, opts = {}) {
    const el = document.getElementById('panel-del-traspasos');
    if (!el || !ctx) return;
    const tid = getDelegadoTorneoId();
    if (!tid) {
        delegadoTraspasoCache = null;
        el.innerHTML =
            '<p class="error">Seleccione un torneo activo en la franja superior para operar traspasos en ese evento.</p>';
        return;
    }
    if (refresh) {
        if (!opts.preserveTrRepFilters) {
            listState.delTrRep = 1;
            listState._delTrRepQ = '';
            listState._delTrRepFiltroSol = 'todos';
        }
        window.clearTimeout(delTrRepDebTimer);
        delTrRepDebTimer = 0;
        el.innerHTML = '<p class="ag-muted">Cargando…</p>';
        const [r2, r3] = await Promise.all([
            fetchJson(`api/delegado_reporte_afiliados_torneo.php?torneo_id=${encodeURIComponent(String(tid))}`),
            fetchJson('api/delegado_otras_asociaciones.php'),
        ]);
        if (!r2.res.ok || !r2.data || !r2.data.ok) {
            el.innerHTML = `<p class="error">${esc(r2.data?.message || 'Error al cargar afiliados')}</p>`;
            delegadoTraspasoCache = null;
            return;
        }
        if (!r3.res.ok || !r3.data || !r3.data.ok) {
            el.innerHTML = `<p class="error">${esc(r3.data?.message || 'Error al cargar asociaciones')}</p>`;
            delegadoTraspasoCache = null;
            return;
        }
        const repItems = Array.isArray(r2.data.items) ? r2.data.items : [];
        const asItems = Array.isArray(r3.data.items) ? r3.data.items : [];
        delegadoTraspasoCache = {
            reporteAfiliados: repItems,
            otrasAsocs: asItems,
            torneoSeleccionadoId: tid,
        };
    }
    const cache = delegadoTraspasoCache;
    if (!cache) {
        el.innerHTML = '<p class="error">Sin datos. Vuelva a abrir Traspasos.</p>';
        return;
    }
    const torLead = `<span class="ag-muted">Torneo seleccionado en la barra superior: <strong>#${esc(String(tid))}</strong>. Las solicitudes de traspaso se gestionan desde el listado siguiente.</span>`;
    const repAll = cache.reporteAfiliados || [];
    const asocs = cache.otrasAsocs || [];
    el.innerHTML = `
    <p class="ag-fin-torneo-lead">${torLead}</p>
    <div id="del-tr-rep-slot" style="margin-bottom:1.25rem;"></div>`;
    renderDelegadoTraspasoReporteContent(el, repAll, tid, asocs);
}

async function loadDelegadoInscripcionTraspasoPanel() {
    const el = document.getElementById('panel-del-inscripcion-traspaso');
    if (!el || !ctx) return;
    el.innerHTML = '<p class="ag-muted">Cargando…</p>';
    const { res, data } = await fetchJson('api/delegado_otras_asociaciones.php');
    if (!res.ok || !data.ok) {
        el.innerHTML = `<p class="error">${esc(data.message || 'Error')}</p>`;
        return;
    }
    const items = Array.isArray(data.items) ? data.items : [];
    const opts = ['<option value="">— Otra asociación (referencia) —</option>']
        .concat(items.map((a) => `<option value="${esc(String(a.id))}">${esc(a.nombre)}</option>`))
        .join('');
    el.innerHTML = `
    <div class="ag-del-two-col">
      <div class="ag-del-col">
        <h3 class="ag-subtitle">Sus afiliados (referencia)</h3>
        <p class="ag-muted">Primeros elegibles al torneo activo (mismo criterio que en Traspasos → disponibles).</p>
        <div id="del-ins-tr-disp-slot"><p class="ag-muted">Pulse «Cargar elegibles».</p></div>
        <p><button type="button" class="btn-secondary btn-sm" id="btn-del-ins-cargar">Cargar elegibles</button></p>
      </div>
      <div class="ag-del-col">
        <h3 class="ag-subtitle">Asociación de referencia (hacia / desde)</h3>
        <p class="ag-muted">Seleccione la otra asociación involucrada en la operación de traspaso que vaya a registrar en <strong>Inscripciones</strong>. El traspaso se genera al inscribir en su nómina a un atleta cuyo usuario siga figurando en otra asociación; la FVD debe aprobarlo.</p>
        <label class="admin-toolbar-filter"><span>Asociación</span>
          <select id="del-ins-tr-asoc-hint" class="admin-search">${opts}</select>
        </label>
        <p id="del-ins-tr-hint" class="ag-muted ag-del-ins-hint" role="status"></p>
        <p><a href="inscripciones.html" class="btn-primary">Abrir inscripciones al torneo</a></p>
      </div>
    </div>`;
    const hint = () => {
        const sel = /** @type {HTMLSelectElement|null} */ (document.getElementById('del-ins-tr-asoc-hint'));
        const out = document.getElementById('del-ins-tr-hint');
        const v = sel ? String(sel.value || '') : '';
        if (!out) return;
        if (!v) {
            out.textContent =
                'Sin selección: en Inscripciones podrá buscar atletas de otras asociaciones; al agregarlos a su nómina puede generarse una solicitud de traspaso.';
        } else {
            const lab = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex].text : '';
            out.textContent = `Referencia: operaciones relacionadas con «${lab.trim()}».`;
        }
    };
    document.getElementById('del-ins-tr-asoc-hint')?.addEventListener('change', hint);
    hint();
    document.getElementById('btn-del-ins-cargar')?.addEventListener('click', async () => {
        const slot = document.getElementById('del-ins-tr-disp-slot');
        if (!slot) return;
        slot.innerHTML = '<p class="ag-muted">Cargando…</p>';
        const { res: r2, data: d2 } = await fetchJson('api/delegado_traspasos_vista.php');
        if (!r2.ok || !d2.ok) {
            slot.innerHTML = `<p class="error">${esc(d2.message || 'Error')}</p>`;
            return;
        }
        const list = d2.disponibles || [];
        const max = 40;
        const show = list.slice(0, max);
        slot.innerHTML = `<div class="admin-fin-pane"><table class="fvd-table admin-crud-table admin-crud-table--compact text-sm"><thead><tr><th>Cédula</th><th>Nombre</th><th class="ag-num">Nº FVD</th></tr></thead>
      <tbody>${show.map((u) => `<tr><td>${esc(u.cedula)}</td><td>${esc(u.nombre)}</td><td class="ag-num">${esc(String(u.numfvd))}</td></tr>`).join('') || '<tr><td colspan="3" class="ag-muted">—</td></tr>'}</tbody></table></div>${
            list.length > max ? `<p class="ag-muted">Mostrando ${max} de ${list.length}. Vea el reporte completo en <strong>Traspasos</strong>.</p>` : ''
        }`;
    });
}

async function openMiOrganizacionDesdePanel() {
    if (!ctx) return;
    if (ctx.rol === 'admingral') {
        navigateAdmingralWorkspace('org', 'Organización FVD');
        return;
    }
    const aid = ctx.asociacion_id != null ? Number(ctx.asociacion_id) : 0;
    if (aid < 1) {
        showGlobalMsg('Su usuario no tiene asociación asignada.', false);
        return;
    }
    const { res, data } = await fetchJson(`api/crud_asociaciones.php?id=${encodeURIComponent(String(aid))}`);
    if (!res.ok || !data.ok || !data.item) {
        showGlobalMsg(data.message || 'No se pudo cargar su asociación.', false);
        return;
    }
    openAsociacionEditor(data.item);
}

/**
 * Panel admin. gral.: vista principal por tarjetas (sin pestañas laterales) + área de trabajo.
 * @param {HTMLElement|null} app
 * @param {number} pTr
 */
function mountAdmingralPanel(app, pTr) {
    if (!app) return;
    app.innerHTML = `
        <div class="admin-app-admingral">
        <header class="ag-panel-page-head">
            <h1 class="ag-panel-page-title">Administrador General</h1>
        </header>
        <div id="admin-traspaso-slot"></div>
        <div id="ag-panel" class="ag-panel agdash-board agdash-board--ref">
            <div class="agdash-grid">
                <section class="agdash-col agdash-col--servicios" aria-labelledby="agdash-h-svc">
                    <h2 id="agdash-h-svc" class="agdash-col-h">Servicios</h2>
                    <div class="agdash-items">
                        <button type="button" class="agdash-item agdash-item--svc" data-ag-ws="org" data-ag-title="Organización FVD"><span class="agdash-item-label">Mi organización</span><span class="agdash-item-ic" aria-hidden="true">⌂</span></button>
                        <button type="button" class="agdash-item agdash-item--svc2" data-ag-ws="aso" data-ag-title="Asociaciones"><span class="agdash-item-label">Asociaciones</span><span class="agdash-item-ic" aria-hidden="true">▣</span></button>
                        <button type="button" class="agdash-item agdash-item--svc3" data-ag-ws="atl" data-ag-title="Atletas"><span class="agdash-item-label">Atletas</span><span class="agdash-item-ic" aria-hidden="true">👥</span></button>
                        <button type="button" class="agdash-item agdash-item--svc4" data-ag-ws="nom" data-ag-title="Nómina desde atletas (movimiento_torneo)"><span class="agdash-item-label">Nómina atletas</span><span class="agdash-item-ic" aria-hidden="true">📋</span></button>
                    </div>
                </section>
                <section class="agdash-col agdash-col--supervision" aria-labelledby="agdash-h-sup">
                    <h2 id="agdash-h-sup" class="agdash-col-h">Supervisión</h2>
                    <div class="agdash-items">
                        <button type="button" class="agdash-item agdash-item--sup1" data-ag-ws="sup-todas" data-ag-title="Todas las solicitudes (delegado)"><span class="agdash-item-label">Todas <span class="agdash-badge agdash-badge--empty" data-sup-badge="todas" aria-label="Pendientes">0</span></span><span class="agdash-item-ic" aria-hidden="true">☰</span></button>
                        <button type="button" class="agdash-item agdash-item--sup2" data-ag-ws="sup-afiliacion" data-ag-title="Afiliaciones (delegado)"><span class="agdash-item-label">Afiliaciones <span class="agdash-badge agdash-badge--empty" data-sup-badge="afiliacion" aria-label="Pendientes">0</span></span><span class="agdash-item-ic" aria-hidden="true">✚</span></button>
                        <button type="button" class="agdash-item agdash-item--sup3" data-ag-ws="sup-carnet" data-ag-title="Carnets pendientes"><span class="agdash-item-label">Carnets <span class="agdash-badge agdash-badge--empty" data-sup-badge="carnet" aria-label="Pendientes">0</span></span><span class="agdash-item-ic" aria-hidden="true">▭</span></button>
                        <button type="button" class="agdash-item agdash-item--sup4" data-ag-ws="sup-traspaso" data-ag-title="Traspasos (torneo activo)"><span class="agdash-item-label">Traspasos <span class="agdash-badge agdash-badge--empty" data-sup-badge="traspaso" aria-label="Pendientes">0</span></span><span class="agdash-item-ic" aria-hidden="true">⇄</span></button>
                        <button type="button" class="agdash-item agdash-item--sup5" data-ag-ws="usr-sol" data-ag-title="Solicitudes de acceso (portal)"><span class="agdash-item-label">Solicitudes portal</span><span class="agdash-item-ic" aria-hidden="true">⏻</span></button>
                    </div>
                </section>
                <section class="agdash-col agdash-col--operaciones" aria-labelledby="agdash-h-op">
                    <h2 id="agdash-h-op" class="agdash-col-h">Operaciones</h2>
                    <div class="agdash-items">
                        <button type="button" class="agdash-item agdash-item--op1" data-ag-ws="tor" data-ag-title="Torneos"><span class="agdash-item-label">Torneos</span><span class="agdash-item-ic" aria-hidden="true">🏆</span></button>
                        <button type="button" class="agdash-item agdash-item--op2" data-ag-nav="${hrefInformesConsolidado()}"><span class="agdash-item-label">Informes</span><span class="agdash-item-ic" aria-hidden="true">📊</span></button>
                    </div>
                </section>
                <section class="agdash-col agdash-col--finanzas" aria-labelledby="agdash-h-fin">
                    <h2 id="agdash-h-fin" class="agdash-col-h">Finanzas</h2>
                    <div class="agdash-items">
                        <button type="button" class="agdash-item agdash-item--fn1" data-ag-ws="fin" data-ag-title="Estado de cuentas y deudas"><span class="agdash-item-label">Estado de cuentas</span><span class="agdash-item-ic" aria-hidden="true">€</span></button>
                        <button type="button" class="agdash-item agdash-item--fn0" data-ag-nav="resumen_finanzas_fvd.html"><span class="agdash-item-label">Resumen del periodo</span><span class="agdash-item-ic" aria-hidden="true">∑</span></button>
                        <button type="button" class="agdash-item agdash-item--fn5" data-ag-nav="gastos_torneo.html"><span class="agdash-item-label">Gastos del torneo</span><span class="agdash-item-ic" aria-hidden="true">−</span></button>
                        <button type="button" class="agdash-item agdash-item--fn6" data-ag-nav="resultado_financiero_torneo.html"><span class="agdash-item-label">Resultado ing./gastos</span><span class="agdash-item-ic" aria-hidden="true">±</span></button>
                        <button type="button" class="agdash-item agdash-item--fn2" data-ag-ws="fin-otros" data-ag-title="Torneos pasados — finanzas por torneo"><span class="agdash-item-label">Torneos pasados</span><span class="agdash-item-ic" aria-hidden="true">◎</span></button>
                        <button type="button" class="agdash-item agdash-item--fn3" data-ag-nav="${hrefInformesConsolidado()}"><span class="agdash-item-label">Consolidado</span><span class="agdash-item-ic" aria-hidden="true">📊</span></button>
                        <button type="button" class="agdash-item agdash-item--fn4" data-ag-nav="${hrefReporteParticipacion()}"><span class="agdash-item-label">Participación</span><span class="agdash-item-ic" aria-hidden="true">📋</span></button>
                    </div>
                </section>
            </div>
        </div>
        <div id="ag-workspace" class="ag-workspace ag-workspace--ag is-hidden">
            <div class="ag-ws-bar">
                <button type="button" class="btn-secondary" id="ag-btn-home">← Panel</button>
                <span id="ag-ws-title" class="ag-ws-title"></span>
            </div>
            <p id="admin-global-msg" class="form-msg" style="display: none;"></p>
            <div id="ag-ws-panels">
                <section id="panel-org" class="ag-ws-panel is-hidden"></section>
                <section id="panel-tor" class="ag-ws-panel is-hidden"></section>
                <section id="panel-aso" class="ag-ws-panel is-hidden"></section>
                <section id="panel-atl" class="ag-ws-panel is-hidden"></section>
                <section id="panel-usr" class="ag-ws-panel is-hidden"></section>
                <section id="panel-fin" class="ag-ws-panel is-hidden"></section>
                <section id="panel-sup" class="ag-ws-panel is-hidden"></section>
                <section id="panel-nom" class="ag-ws-panel is-hidden"></section>
            </div>
        </div>
        </div>`;
    if (pTr > 0) {
        const slot = document.getElementById('admin-traspaso-slot');
        if (slot) {
            slot.innerHTML = `<div id="admin-traspaso-banner" class="admin-traspaso-banner" role="status"><strong>Alerta traspaso:</strong> ${esc(
                String(pTr)
            )} inscripción(es) con <code>traspaso=1</code> en el torneo activo. Revise <a href="inscripciones.html" class="ag-inline-link">Inscripciones</a> o la tarjeta <strong>Supervisión → Traspasos</strong>.</div>`;
        }
    }
    paintSupervisionBadges();
    wireAgNavLinks(app);
    app.querySelectorAll('[data-ag-ws]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const ws = btn.getAttribute('data-ag-ws') || '';
            const title = btn.getAttribute('data-ag-title') || '';
            navigateAdmingralWorkspace(ws, title);
        });
    });
    document.getElementById('ag-btn-home')?.addEventListener('click', () => showAdmingralPanel());
    void enfoqueAsocDesdeInformes();
}

async function enfoqueAsocDesdeInformes() {
    if (!ctx || ctx.rol !== 'admingral') return;
    let aid = '';
    try {
        aid = sessionStorage.getItem('fvd_focus_asoc_id') || '';
        if (aid) sessionStorage.removeItem('fvd_focus_asoc_id');
    } catch (_) {}
    if (!aid) return;
    navigateAdmingralWorkspace('aso', 'Asociaciones');
    await loadAsociacionesPanel();
    const d = await fetchJson(`api/crud_asociaciones.php?id=${encodeURIComponent(aid)}`);
    if (d.res.ok && d.data.ok && d.data.item) openAsociacionEditor(d.data.item);
}

function showAdmingralPanel() {
    document.getElementById('ag-panel')?.classList.remove('is-hidden');
    document.getElementById('ag-workspace')?.classList.add('is-hidden');
    document.querySelectorAll('.ag-ws-panel').forEach((p) => p.classList.add('is-hidden'));
}

/**
 * @param {string} slice org|tor|aso|atl|usr|usr-sol|fin|fin-otros|sup-todas|sup-afiliacion|sup-carnet|sup-traspaso
 * @param {string} title
 */
function navigateAdmingralWorkspace(slice, title) {
    document.getElementById('ag-panel')?.classList.add('is-hidden');
    const ws = document.getElementById('ag-workspace');
    if (ws) ws.classList.remove('is-hidden');
    const t = document.getElementById('ag-ws-title');
    if (t) t.textContent = title;
    document.querySelectorAll('.ag-ws-panel').forEach((p) => p.classList.add('is-hidden'));
    const map = {
        org: 'org',
        tor: 'tor',
        aso: 'aso',
        atl: 'atl',
        usr: 'usr',
        'usr-sol': 'usr',
        fin: 'fin',
        'fin-otros': 'fin',
        'sup-todas': 'sup',
        'sup-afiliacion': 'sup',
        'sup-carnet': 'sup',
        'sup-traspaso': 'sup',
        nom: 'nom',
    };
    const panel = map[slice] || slice;
    const el = document.getElementById(`panel-${panel}`);
    if (el) el.classList.remove('is-hidden');
    if (slice === 'org') loadOrgPanel();
    else if (slice === 'tor') loadTorneosPanel();
    else if (slice === 'aso') loadAsociacionesPanel();
    else if (slice === 'atl') {
        listState.atl = 1;
        loadAtletasReportPanel();
    } else if (slice === 'usr') {
        listState._usrStatus = null;
        loadUsuariosPanel();
    } else if (slice === 'usr-sol') {
        listState._usrStatus = 9;
        listState.usr = 1;
        listState._usrQ = '';
        loadUsuariosSolicitudesPanel();
    } else if (slice === 'fin') {
        listState.fin = 1;
        listState._finTorneoPanelId = 0;
        listState._finGrupoPanelId = 0;
        listState._finAsocDetalleId = 0;
        listState._finOtrosTorneoId = 0;
        listState._finOtrosConsolidar = false;
        try {
            localStorage.removeItem('fvd_fin_torneo_id');
        } catch (_) {}
        loadFinanzasPanel();
    } else if (slice === 'fin-otros') {
        listState.fin = 1;
        listState._finOtrosBlocPag = {};
        void loadFinanzasOtrosTorneosPanel();
    } else if (slice === 'sup-todas' || slice === 'sup-afiliacion' || slice === 'sup-carnet' || slice === 'sup-traspaso') {
        if (listState._supSlice !== slice) {
            listState._supSlice = slice;
            listState.sup = 1;
        }
        void loadSupervisionPanel(slice);
    } else if (slice === 'nom') {
        listState._finTorneoPanelId = 0;
        void loadNominaDesdeAtletasPanel(el, fetchJson);
    }
}

/**
 * Supervisión FVD: prioridad afiliación > traspaso > carnet; decisión con interruptor y aplicación masiva.
 * @param {'sup-todas'|'sup-afiliacion'|'sup-carnet'|'sup-traspaso'} slice
 */
async function loadSupervisionPanel(slice) {
    const el = document.getElementById('panel-sup');
    if (!el || !ctx) return;
    const seg =
        slice === 'sup-todas'
            ? 'todas'
            : slice === 'sup-afiliacion'
              ? 'afiliacion'
              : slice === 'sup-carnet'
                ? 'carnet'
                : 'traspaso';
    el.innerHTML = '<p class="ag-muted">Cargando supervisión…</p>';
    const { res, data } = await fetchJson(`api/supervision_fvd.php?segmento=${encodeURIComponent(seg)}`);
    if (!res.ok || !data.ok) {
        el.innerHTML = `<p class="error">${esc(data.message || 'Error al cargar')}</p>`;
        return;
    }
    const items = Array.isArray(data.items) ? data.items : [];
    const torneoId = data.torneo_id != null ? String(data.torneo_id) : '—';
    const msgTorneo = data.message ? `<p class="ag-muted ag-sup-note">${esc(data.message)}</p>` : '';
    const perSup = REPORTE_FILAS_POR_PAGINA;
    const pgSup = listState.sup;
    const totalSup = items.length;
    const itemsPage = reporteSliceCliente(items, pgSup, perSup);
    const prefSup = `sup-${seg.replace(/[^a-z0-9_-]/gi, 'x')}`;
    const pagerSup = reporteHtmlPaginador(prefSup, pgSup, totalSup, perSup);

    const accionPorEje = (eje, aprobar) => {
        if (eje === 'afiliacion') return aprobar ? 'aprobar_afiliacion_movimiento' : 'rechazar_afiliacion_movimiento';
        if (eje === 'carnet') return aprobar ? 'aprobar_carnet_movimiento' : 'rechazar_carnet_movimiento';
        if (eje === 'traspaso') return aprobar ? 'aprobar_traspaso' : 'rechazar_traspaso';
        return '';
    };

    const switchRow = (it) => {
        const eje = String(it.eje_supervision || '').trim();
        if (!eje) return '<td class="ag-sup-th-acc"><span class="ag-sup-actions ag-sup-actions--na">—</span></td>';
        const mid = esc(String(it.movimiento_id));
        const ej = esc(eje);
        return `<td class="ag-sup-th-acc">
            <label class="ag-sup-sw" title="Desactivado = rechazar · Activado = aprobar">
                <span class="ag-sup-sw-cap ag-sup-sw-cap--rej">Rechazar</span>
                <input type="checkbox" class="ag-sup-sw-input" data-sup-sw="1" data-movimiento-id="${mid}" data-sup-eje="${ej}" />
                <span class="ag-sup-sw-pill" aria-hidden="true"></span>
                <span class="ag-sup-sw-cap ag-sup-sw-cap--apr">Aprobar</span>
            </label>
        </td>`;
    };

    const colAsocTraspaso = (it) => {
        if (String(it.eje_supervision || '') === 'traspaso') {
            const desde = it.asociacion_desde_nombre ? esc(String(it.asociacion_desde_nombre)) : '—';
            const hacia = it.asociacion_hacia_nombre ? esc(String(it.asociacion_hacia_nombre)) : '—';
            const title = `${desde} → ${hacia}`;
            return `<td class="ag-sup-transfer admin-cell-truncate text-sm" title="${title}"><span class="ag-sup-transfer-k">Desde</span> ${desde}<br><span class="ag-sup-transfer-k">Hacia</span> ${hacia}</td>`;
        }
        const nom = it.asociacion_nombre ? esc(String(it.asociacion_nombre)) : '—';
        return `<td class="admin-cell-truncate text-sm" title="${nom}">${nom}</td>`;
    };

    const filas = itemsPage
        .map((it) => {
            const tipoLabel = it.tipo_solicitud ? esc(String(it.tipo_solicitud)) : 'Movimiento';
            const nomEsc = esc(String(it.nombre ?? ''));
            return `<tr>
                <td class="admin-cell-truncate text-sm" title="${tipoLabel}">${tipoLabel}</td>
                <td class="text-sm">${esc(String(it.cedula))}</td>
                <td class="admin-cell-truncate text-sm" title="${nomEsc}">${nomEsc}</td>
                <td class="ag-num text-sm">${esc(String(it.numfvd ?? ''))}</td>
                ${colAsocTraspaso(it)}
                ${switchRow(it)}
            </tr>`;
        })
        .join('');

    const thead =
        '<tr><th>Tipo de solicitud</th><th>Cédula</th><th>Nombre</th><th>Nº FVD</th><th>Asociación / traspaso</th><th class="ag-sup-th-acc">Decisión</th></tr>';

    el.innerHTML = `${wrapReporte(
        `<p class="ag-muted">Torneo <strong>${esc(torneoId)}</strong>. Listado desde <code>movimiento_torneo</code>. Prioridad: <strong>Afiliación</strong> (sin Nº FVD) &gt; <strong>Traspaso</strong> &gt; <strong>Carnet</strong>. Al <strong>aprobar carnet o traspaso</strong>, los indicadores <code>carnet</code>/<code>traspaso</code> permanecen en <strong>1</strong> (finanzas) y <code>movimiento</code> pasa a <strong>9</strong> (aprobado en supervisión). Afiliación aprobada: solo asigna <code>numfvd</code>. Use el interruptor y <strong>Aplicar decisiones</strong>.</p>${msgTorneo}
        <div class="ag-sup-toolbar">
            <label class="ag-sup-toolbar-all"><input type="checkbox" id="ag-sup-aprobar-todas" /> <span>Aprobar todas (marca todos los interruptores en Aprobar)</span></label>
            <button type="button" class="btn-primary btn-sm" id="ag-sup-aplicar">Aplicar decisiones</button>
        </div>
        <div class="reporte-vista">${pagerSup}
        <div class="admin-fin-pane ag-sup-wrap ag-inf-scroll">
        <table class="fvd-table admin-crud-table admin-crud-table--compact text-sm ag-sup-table">
        <thead>${thead}</thead>
        <tbody>${filas || '<tr><td colspan="6"><em>Sin solicitudes en este filtro.</em></td></tr>'}</tbody>
        </table></div></div>`
    )}`;

    reporteLigarPaginador(prefSup, pgSup, totalSup, (np) => {
        listState.sup = np;
        void loadSupervisionPanel(slice);
    }, perSup);

    const chkTodas = el.querySelector('#ag-sup-aprobar-todas');
    chkTodas?.addEventListener('change', () => {
        const on = !!chkTodas.checked;
        el.querySelectorAll('input.ag-sup-sw-input[data-sup-sw]').forEach((inp) => {
            inp.checked = on;
        });
    });

    el.querySelector('#ag-sup-aplicar')?.addEventListener('click', async () => {
        const inputs = Array.from(el.querySelectorAll('input.ag-sup-sw-input[data-sup-sw]'));
        const decisiones = [];
        for (const inp of inputs) {
            const mid = parseInt(String(inp.getAttribute('data-movimiento-id') || '0'), 10);
            const eje = String(inp.getAttribute('data-sup-eje') || '').trim();
            if (mid < 1 || !eje) continue;
            const acc = accionPorEje(eje, !!inp.checked);
            if (!acc) continue;
            decisiones.push({ movimiento_id: mid, accion: acc });
        }
        if (decisiones.length === 0) {
            showGlobalMsg('No hay filas con decisión aplicable en esta página.', false);
            return;
        }
        const enPagina = inputs.filter((inp) => String(inp.getAttribute('data-sup-eje') || '').trim() !== '').length;
        const avisoPag =
            totalSup > enPagina
                ? `\n\nSolo se procesan las ${decisiones.length} fila(s) visibles en esta página (${totalSup} pendientes en total). Cambie de página si debe aplicar más.`
                : '';
        if (
            !window.confirm(
                `¿Aplicar ${decisiones.length} decisión(es) en el servidor (validación y recálculo de deuda por asociación)?${avisoPag}`
            )
        ) {
            return;
        }
        const btn = el.querySelector('#ag-sup-aplicar');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Aplicando…';
        }
        const d = await fetchJson('api/supervision_fvd.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'aplicar_masivo', decisiones }),
        });
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Aplicar decisiones';
        }
        if (!d.res.ok || !d.data.ok) {
            const nFail = Array.isArray(d.data.fallidas) ? d.data.fallidas.length : 0;
            const nOk = d.data.aplicadas ?? 0;
            let det = d.data.message || 'Error al aplicar decisiones masivas.';
            if (nFail > 0 && d.data.fallidas[0]?.message) {
                det += ' Ej.: ' + d.data.fallidas[0].message;
            }
            if (nOk > 0) {
                det += ` (${nOk} sí se aplicaron.)`;
            }
            showGlobalMsg(det, false);
            if (d.data.pendientes) ctx.supervision_pendientes = d.data.pendientes;
            paintSupervisionBadges();
            void loadSupervisionPanel(slice);
            return;
        }
        if (d.data.pendientes) ctx.supervision_pendientes = d.data.pendientes;
        paintSupervisionBadges();
        const rec = d.data.deuda_asociaciones_recalculadas ?? 0;
        showGlobalMsg(
            (d.data.message || 'Decisiones aplicadas.') +
                (rec > 0 ? ` Indicadores financieros actualizados (${rec} asociación/es).` : ''),
            true
        );
        void loadSupervisionPanel(slice);
    });
}

/** URL del informe consolidado (conserva torneo elegido en finanzas si existe). */
function hrefInformesConsolidado() {
    const t = leerTorneoFinanzasGuardado();
    return t > 0 ? `informes.html?torneo_id=${encodeURIComponent(String(t))}` : 'informes.html';
}

function wireAgNavLinks(root) {
    if (!root) return;
    root.querySelectorAll('[data-ag-nav]').forEach((el) => {
        el.addEventListener('click', (ev) => {
            const u = el.getAttribute('data-ag-nav');
            if (!u) return;
            ev.preventDefault();
            window.location.assign(u);
        });
    });
}

function torneosBarraDesdeApi(data) {
    const nom = Array.isArray(data.torneos_con_nomina) ? data.torneos_con_nomina : [];
    if (nom.length > 0) return nom;
    return Array.isArray(data.torneos_selector) ? data.torneos_selector : [];
}

const FIN_PANEL_DET_PREFIX = 'fin-panel-det';

async function pintarDetalleAsociacionEnPanel(el, asocId, scope) {
    const box = el.querySelector('#fin-panel-asoc-detalle');
    if (!box || asocId < 1) return;
    box.innerHTML = '<p class="ag-muted">Cargando detalle por torneos…</p>';
    const r = await fetchFinanzaDetalleAsociacion(fetchJson, asocId, scope);
    if (!r.res.ok || !r.data.ok) {
        box.innerHTML = `<p class="error">${esc(r.data.message || 'Error al cargar detalle')}</p>`;
        return;
    }
    const d = r.data.data || {};
    const a = d.asociacion || {};
    const grupos = Array.isArray(d.grupos_informe) ? d.grupos_informe : [];
    const t = d.totales || {};
    box.innerHTML = `<section class="ag-fin-panel-detalle" id="fin-panel-detalle-inner">
        <div class="ag-fin-panel-det-head">
            <h3 class="ag-subtitle ag-fin-section-title">Detalle — ${esc(a.nombre || 'Asociación')}</h3>
            <button type="button" class="btn-secondary btn-sm" id="fin-panel-det-cerrar">Cerrar detalle</button>
        </div>
        <p class="ag-muted ag-fin-panel-det-sum">Total cuenta: <strong>${esc(String(t.deuda_eur ?? 0))} €</strong>
            · Nómina ${esc(String(t.nomina_eur ?? 0))} € · Saldo ${esc(String(t.saldo_eur ?? 0))} €</p>
        <div class="ag-fin-informe-torneos admin-fin-scroll text-sm">${htmlGruposInformeAcordeon(grupos, FIN_PANEL_DET_PREFIX, {
            asocId,
            torneoId: scope.torneoId > 0 ? scope.torneoId : 0,
            grupoId: scope.grupoId > 0 ? scope.grupoId : 0,
        })}</div>
        <p class="ag-fin-panel-det-links">
            <a class="btn-secondary btn-sm" href="finanzas_asociacion.html?id=${encodeURIComponent(String(asocId))}${scope.grupoId > 0 ? `&grupo_evento_id=${scope.grupoId}&consolidar_campeonato=1` : scope.torneoId > 0 ? `&torneo_id=${scope.torneoId}` : '&consolidar_campeonato=1'}">Abrir página completa</a>
        </p>
    </section>`;
    box.querySelector('#fin-panel-det-cerrar')?.addEventListener('click', () => {
        listState._finAsocDetalleId = 0;
        box.innerHTML = '';
        el.querySelectorAll('.fin-panel-row--sel').forEach((tr) => tr.classList.remove('fin-panel-row--sel'));
    });
    wirePaginacionDetalleGrupos(box, grupos, FIN_PANEL_DET_PREFIX, () => {
        const aid = listState._finAsocDetalleId;
        if (aid > 0) void pintarDetalleAsociacionEnPanel(el, aid, scope);
    });
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function loadFinanzasPanel() {
    const el = document.getElementById('panel-fin');
    if (!el) return;
    listState._finOtrosConsolidar = false;
    try {
    let tidReq = listState._finTorneoPanelId > 0 ? listState._finTorneoPanelId : 0;
    let gidReq = listState._finGrupoPanelId > 0 ? listState._finGrupoPanelId : 0;
    const qs = new URLSearchParams();
    if (gidReq > 0) qs.set('grupo_evento_id', String(gidReq));
    else if (tidReq > 0) qs.set('torneo_id', String(tidReq));
    const qstr = qs.toString();
    el.innerHTML = '<p class="ag-muted">Cargando finanzas…</p>';
    const { res, data } = await fetchJson(`api/finanza_resumen.php${qstr ? `?${qstr}` : ''}`);
    if (!res.ok || !data.ok) {
        el.innerHTML = `<p class="error">${esc(
            data.message || 'Error al cargar finanzas. Si acaba de desplegar el portal, ejecute sql/migrations/006_finanza_fvd.sql.'
        )}</p>`;
        return;
    }
    const int = data.integral || {};
    const rowsAll = data.resumen || [];
    const rows = rowsAll.filter((r) => (parseInt(String(r.n_movimiento_torneo ?? 0), 10) || 0) > 0);
    const tor = data.torneo_activo || null;
    const torFilt = data.torneo_filtro || null;
    let tid =
        data.torneo_filtro_id != null ? parseInt(String(data.torneo_filtro_id), 10) || 0 : tidReq;
    const gid =
        data.grupo_evento_id != null ? parseInt(String(data.grupo_evento_id), 10) || 0 : gidReq;
    if (gid > 0) {
        listState._finGrupoPanelId = gid;
        listState._finTorneoPanelId = 0;
        tid = 0;
    } else if (tid > 0) {
        listState._finTorneoPanelId = tid;
        listState._finGrupoPanelId = 0;
        guardarTorneoFinanzas(tid);
    }
    const torneosBar = torneosBarraDesdeApi(data);
    const torneosEstruct = data.torneos_estructurado || null;
    let tasa = Number(data.tasa_eur_bs);
    const pg = listState.fin;
    const per = REPORTE_FILAS_POR_PAGINA;
    const total = rows.length;
    const slice = reporteSliceCliente(rows, pg);
    const pagerFin = reporteHtmlPaginador('fin-res', pg, total, per);
    const tq = torneoFinanzasQs(tid);
    const tm = metaTorneoFinanzas(torFilt || (tid > 0 ? { id: tid, nombre: '' } : null));
    const bannerTor = htmlBannerTorneoCuentas({
        nombre: esc(tm.nombre || (tor && tor.nombre ? String(tor.nombre) : '')),
        torneoId: tm.id || tid,
        fechator: tm.fechator,
        finalizado_en: tm.finalizado_en,
        enCurso: tm.enCurso,
        alcance: 'Resumen por asociación con movimiento en la nómina',
    });
    const barra = htmlBarraTorneoFinanzas({
        prefix: 'fin-panel',
        torneos: torneosBar,
        torneoSeleccionado: tid,
        torneosEstructurado: torneosEstruct,
        showCampeonatoConsolidado: true,
        grupoSeleccionado: gid,
        requerirTorneo: true,
        showConsolidar: false,
        leadHtml:
            '<p class="ag-muted ag-fin-bar-hint">Elija un <strong>torneo</strong> o <strong>cuenta consolidada</strong> del campeonato. Luego pulse <strong>Ver torneos</strong> en una asociación.</p>',
    });
    const avisoSinTor =
        tid < 1 && gid < 1
            ? '<p class="ag-fin-aviso-torneo ag-muted" role="status">Seleccione un <strong>torneo</strong> o <strong>campeonato consolidado</strong> en la lista superior.</p>'
            : '';

    const linkDetalleQs = () => {
        if (gid > 0) {
            return `&grupo_evento_id=${encodeURIComponent(String(gid))}&consolidar_campeonato=1`;
        }
        if (tid > 0) return torneoFinanzasQs(tid);
        return '';
    };

    const accionesFin = (r) => {
        const aid = parseInt(String(r.id), 10) || 0;
        const navOpts = {
            torneoId: tid > 0 ? tid : 0,
            grupoId: gid > 0 ? gid : 0,
        };
        return `<div class="reporte-acciones ag-fin-link-actions">
            <button type="button" class="btn-secondary btn-sm reporte-btn-ic fin-panel-ver-det" data-asoc-id="${aid}" title="Ver torneos" aria-label="Ver torneos"><span class="reporte-btn-ic-sym" aria-hidden="true">📂</span></button>
            <a class="btn-secondary btn-sm reporte-btn-ic" href="${hrefInformeAsociacion(aid, navOpts)}" title="Informe por renglón" aria-label="Informe"><span class="reporte-btn-ic-sym" aria-hidden="true">📊</span></a>
            <a class="btn-secondary btn-sm reporte-btn-ic" href="${hrefFinanzasAsociacion(aid, navOpts)}" title="Página cuenta" aria-label="Página cuenta"><span class="reporte-btn-ic-sym" aria-hidden="true">📋</span></a>
        </div>`;
    };

    const totRen = data.totales_renglones || null;
    const thStatsFin = RENGLONES_ESTADISTICA.map((r) => htmlThEstadisticaRenglon(r)).join('');
    const tableRowsSlice =
        tid < 1 && gid < 1
            ? '<tr><td colspan="12" class="ag-muted">Seleccione un torneo o campeonato arriba para ver el resumen por asociación.</td></tr>'
            : total > 0
            ? slice
                  .map((r) => {
                      const aid = parseInt(String(r.id), 10) || 0;
                      const navAsoc = {
                          asocId: aid,
                          torneoId: tid > 0 ? tid : 0,
                          grupoId: gid > 0 ? gid : 0,
                      };
                      const celdasStat = RENGLONES_ESTADISTICA.map((ren) =>
                          htmlCeldaEstadisticaRenglon(r[ren.n], r[ren.monto], ren, navAsoc)
                      ).join('');
                      const selCls =
                          listState._finAsocDetalleId === aid ? ' fin-panel-row--sel' : '';
                      return `<tr class="fin-panel-asoc-row${selCls}" data-asoc-id="${aid}">
                <td class="reporte-td-img">${reporteThumbImg(r.logo || '', r.nombre || '')}</td>
                <td>${esc(r.nombre)}</td>
                ${celdasStat}
                <td class="ag-num">${esc(String(r.n_movimiento_torneo ?? 0))}</td>
                <td class="ag-num">${esc(String(r.deuda_eur))}</td>
                <td class="ag-num">${esc(String(r.pagado_eur))}</td>
                <td class="ag-num ag-num--bal">${esc(String(r.saldo_eur))}</td>
                <td class="reporte-td-acciones">${accionesFin(r)}</td>
            </tr>`;
                  })
                  .join('')
            : '<tr><td colspan="12" class="ag-muted">Ninguna asociación tiene filas en <strong>movimiento_torneo</strong> para el torneo seleccionado.</td></tr>';
    const tfootFin =
        totRen && total > 0
            ? `<tfoot class="ag-stat-tfoot"><tr>
            <td colspan="2"><strong>Total torneo</strong></td>
            ${RENGLONES_ESTADISTICA.map((r) => htmlCeldaEstadisticaRenglon(totRen[r.n], totRen[r.monto], r)).join('')}
            <td colspan="5"></td>
            </tr></tfoot>`
            : '';

    el.innerHTML = `<div class="ag-fin-shell">
        ${bannerTor}
        ${barra}
        ${avisoSinTor}
        <p class="ag-fin-shell-links"><a class="ag-inline-link ag-fin-shell-link" href="#" id="fin-panel-link-otros">Torneos pasados</a><span class="ag-fin-shell-link-sep" aria-hidden="true">·</span><a class="ag-inline-link ag-fin-shell-link" href="${hrefReporteAsociacionesTorneo({ torneoId: tid > 0 ? tid : 0, grupoId: gid > 0 ? gid : 0 })}">Reporte asoc. × torneo</a><span class="ag-fin-shell-link-sep" aria-hidden="true">·</span><a class="ag-inline-link ag-fin-shell-link" href="${hrefReporteParticipacion()}">Participación (columnas)</a><span class="ag-fin-shell-link-sep" aria-hidden="true">·</span><a class="ag-inline-link ag-fin-shell-link" href="${hrefInformesConsolidado()}">Consolidado</a></p>
        <div class="ag-fin-head ag-fin-head-inline">
            <div class="ag-fin-balance ag-fin-balance--compact">
                <span><strong>Integral</strong> — Deuda: <em>${esc(String(int.deuda_eur))} €</em></span>
                <span>Pagado (verif.): <em>${esc(String(int.pagado_eur))} €</em></span>
                <span>Saldo: <em>${esc(String(int.saldo_eur))} €</em></span>
            </div>
            <div class="ag-fin-tasa ag-fin-tasa--compact">
                <div class="ag-fin-tasa-row ag-fin-tasa-row--compact">
                    <span class="ag-fin-label-inline">Tasa oficial Bs/EUR</span>
                    <input type="number" step="0.0001" min="0.0001" id="fin-tasa-input" class="ag-fin-input ag-fin-input--tasa-sm" value="${esc(String(tasa))}" title="Se guarda al modificar">
                </div>
            </div>
        </div>
        <div class="reporte-vista ag-fin-reporte-vista">
        ${pagerFin}
            <section class="ag-fin-col" id="fin-sec-movimiento">
                <h3 class="ag-subtitle ag-fin-section-title">Asociaciones con movimiento</h3>
                <p class="ag-muted ag-fin-hint-below-title">Resumen por asociación y torneo. Pulse una <strong>celda de concepto</strong> (cantidad &gt; 0) o <strong>📊 Informe</strong> para el detalle del renglón; <strong>📂 Ver torneos</strong> abre el acordeón por variante.</p>
                <p class="ag-fin-shell-links"><a class="ag-inline-link ag-fin-shell-link" href="${hrefReporteAsociacionesTorneo()}">Reporte todas las asoc. × torneo</a><span class="ag-fin-shell-link-sep" aria-hidden="true"> · </span><a class="ag-inline-link ag-fin-shell-link" href="${hrefReporteParticipacion()}">Participación (columnas)</a><span class="ag-fin-shell-link-sep" aria-hidden="true"> · </span><a class="ag-inline-link ag-fin-shell-link" href="${hrefInformeConsolidado({ torneoId: tid > 0 ? tid : 0, grupoId: gid > 0 ? gid : 0 })}">Consolidado por torneo</a></p>
                ${htmlResumenRenglonesCards(totRen)}
                ${htmlFvdTableShell(`<table class="fvd-table admin-crud-table admin-crud-table--compact text-sm">
                    <thead><tr><th class="reporte-th-img">Logo</th><th>Asociación</th>${thStatsFin}<th class="ag-num" title="Filas en movimiento_torneo">Mov.</th><th class="ag-num">Deuda €</th><th class="ag-num">Pagado verif. €</th><th class="ag-num">Saldo €</th><th class="reporte-th-acciones">Acciones</th></tr></thead>
                    <tbody>${tableRowsSlice}</tbody>
                    ${tfootFin}
                </table>`)}
            </section>
        </div>
        <div id="fin-panel-asoc-detalle" class="ag-fin-panel-asoc-detalle"></div></div>`;

    wireFinanzaTasaAutoSave(el, 'fin-tasa-input');

    const scopeFin = () => ({
        torneoId: listState._finTorneoPanelId,
        grupoId: listState._finGrupoPanelId,
    });

    el.querySelectorAll('.fin-panel-ver-det').forEach((btn) => {
        btn.addEventListener('click', (ev) => {
            ev.preventDefault();
            const aid = parseInt(String(btn.getAttribute('data-asoc-id') || '0'), 10);
            if (aid < 1) return;
            if (tid < 1 && gid < 1) {
                window.alert('Seleccione primero un torneo o un campeonato consolidado.');
                return;
            }
            listState._finAsocDetalleId = aid;
            resetPaginasDetalleFinanza(FIN_PANEL_DET_PREFIX);
            void pintarDetalleAsociacionEnPanel(el, aid, scopeFin());
        });
    });

    wireBarraTorneoFinanzas(el, 'fin-panel', {
        onTorneoChange: (v) => {
            const sel = el.querySelector('#fin-panel-sel-torneo');
            const raw = sel ? String(/** @type {HTMLSelectElement} */ (sel).value || '') : String(v);
            listState._finAsocDetalleId = 0;
            if (raw.startsWith('g-')) {
                listState._finGrupoPanelId = parseInt(raw.slice(2), 10) || 0;
                listState._finTorneoPanelId = 0;
                try {
                    localStorage.removeItem('fvd_fin_torneo_id');
                } catch (_) {}
            } else {
                listState._finTorneoPanelId = parseInt(raw, 10) || 0;
                listState._finGrupoPanelId = 0;
                if (listState._finTorneoPanelId > 0) guardarTorneoFinanzas(listState._finTorneoPanelId);
                else {
                    try {
                        localStorage.removeItem('fvd_fin_torneo_id');
                    } catch (_) {}
                }
            }
            listState.fin = 1;
            void loadFinanzasPanel();
        },
        onActualizar: async () => {
            if (tid < 1 && gid < 1) {
                window.alert('Seleccione un torneo o campeonato antes de actualizar deudas.');
                return;
            }
            const fb = el.querySelector('#fin-panel-deuda-fb');
            if (fb) fb.textContent = 'Procesando…';
            if (gid > 0 && torneosEstruct && Array.isArray(torneosEstruct.campeonatos)) {
                const camp = torneosEstruct.campeonatos.find(
                    (g) => parseInt(String(g.grupo_evento_id ?? '0'), 10) === gid
                );
                const ids = (camp?.torneos || [])
                    .map((t) => parseInt(String(t.torneo_id ?? '0'), 10))
                    .filter((n) => n > 0);
                let okAll = true;
                for (const tId of ids) {
                    const r = await postActualizarDeudas(fetchJson, tId);
                    if (!r.res.ok || !r.data.ok) okAll = false;
                }
                if (fb) fb.textContent = okAll ? 'Deudas actualizadas (todas las variantes).' : 'Error en alguna variante.';
                if (okAll) void loadFinanzasPanel();
            } else {
                const r = await postActualizarDeudas(fetchJson, tid);
                if (fb) fb.textContent = r.data.message || (r.res.ok && r.data.ok ? 'Listo.' : 'Error');
                if (r.res.ok && r.data.ok) void loadFinanzasPanel();
            }
        },
    });
    el.querySelector('#fin-panel-link-otros')?.addEventListener('click', (ev) => {
        ev.preventDefault();
        if (tid > 0) listState._finOtrosTorneoId = tid;
        navigateAdmingralWorkspace('fin-otros', 'Torneos pasados — finanzas');
    });

    reporteLigarPaginador('fin-res', pg, total, (np) => {
        listState.fin = np;
        loadFinanzasPanel();
    }, per);
    } catch (e) {
        console.error('loadFinanzasPanel', e);
        el.innerHTML = `<p class="error">Error al cargar finanzas: ${esc(e && e.message ? String(e.message) : 'desconocido')}</p>`;
    }
}

/**
 * Panel admin. gral. — Finanzas «Otros torneos»: misma página de estado de cuentas (#panel-fin) con selector de torneo y opción de consolidar por torneo.
 */
async function loadFinanzasOtrosTorneosPanel() {
    const el = document.getElementById('panel-fin');
    if (!el) return;
    el.innerHTML = '<p class="ag-muted">Cargando torneos pasados…</p>';

    try {
    if (
        listState._finOtrosTorneoId < 1 &&
        !listState._finOtrosConsolidar &&
        leerTorneoFinanzasGuardado() > 0
    ) {
        listState._finOtrosTorneoId = leerTorneoFinanzasGuardado();
    }
    const qs = new URLSearchParams();
    if (!listState._finOtrosConsolidar) {
        if (listState._finOtrosTorneoId > 0) {
            qs.set('torneo_id', String(listState._finOtrosTorneoId));
        } else {
            qs.set('solo_activo', '1');
        }
    }
    if (listState._finOtrosConsolidar) {
        qs.set('consolidar', '1');
    }
    const qstr = qs.toString();
    const { res, data } = await fetchJson(`api/finanza_resumen.php${qstr ? `?${qstr}` : ''}`);
    if (!res.ok || !data.ok) {
        el.innerHTML = `<p class="error">${esc(data.message || 'Error al cargar finanzas.')}</p>`;
        return;
    }

    const int = data.integral || {};
    let tasa = Number(data.tasa_eur_bs);
    const consolidar = !!data.consolidar;
    const torAct = data.torneo_activo || null;
    const torFilt = data.torneo_filtro || null;
    const tsel = torneosBarraDesdeApi(data);
    const tidOtros =
        consolidar || listState._finOtrosTorneoId < 1
            ? data.torneo_filtro_id != null
                ? parseInt(String(data.torneo_filtro_id), 10) || 0
                : 0
            : listState._finOtrosTorneoId;
    const tqOtros = torneoFinanzasQs(tidOtros);
    const tmOt = metaTorneoFinanzas(torFilt || torAct);
    const bannerOt = htmlBannerTorneoCuentas({
        multiple: consolidar,
        nombre: consolidar
            ? esc('Consolidado — todos los torneos con nómina')
            : esc(tmOt.nombre || (torAct && torAct.nombre ? String(torAct.nombre) : '')),
        torneoId: consolidar ? 0 : tmOt.id || tidOtros,
        fechator: tmOt.fechator,
        finalizado_en: tmOt.finalizado_en,
        enCurso: tmOt.enCurso,
        alcance: consolidar ? 'Un bloque por torneo' : 'Resumen por asociación',
    });
    const barraOtros = htmlBarraTorneoFinanzas({
        prefix: 'fin-otros',
        torneos: tsel,
        torneoSeleccionado: consolidar ? 0 : listState._finOtrosTorneoId,
        showConsolidar: true,
        consolidarChecked: consolidar,
    });

    const accionesFin = (r) => {
        const sid = encodeURIComponent(String(r.id));
        return `<div class="reporte-acciones ag-fin-link-actions">
            <a class="btn-secondary btn-sm reporte-btn-ic" href="informes.html?asoc=${sid}${tqOtros}" title="Informe / conceptos" aria-label="Informe"><span class="reporte-btn-ic-sym" aria-hidden="true">📊</span></a>
            <a class="btn-secondary btn-sm reporte-btn-ic" href="finanzas_asociacion.html?id=${sid}${tqOtros}" title="Estado y detalle" aria-label="Estado y detalle"><span class="reporte-btn-ic-sym" aria-hidden="true">📋</span></a>
        </div>`;
    };

    const perBl = REPORTE_FILAS_POR_PAGINA;
    const finOtrosBlocDomKey = (k) => String(k).replace(/[^a-zA-Z0-9]/g, '_');

    /** @type {{ statKey: string, total: number }[]} */
    const bloquesPagerMeta = [];

    const filasTablaBloqueConPager = (rowsAll, statKey) => {
        const rows = (rowsAll || []).filter((r) => (parseInt(String(r.n_movimiento_torneo ?? 0), 10) || 0) > 0);
        if (rows.length < 1) {
            return '<p class="ag-muted">Ninguna asociación tiene movimiento en este torneo.</p>';
        }
        const k = String(statKey);
        if (listState._finOtrosBlocPag[k] == null) listState._finOtrosBlocPag[k] = 1;
        const pg = listState._finOtrosBlocPag[k];
        const total = rows.length;
        bloquesPagerMeta.push({ statKey: k, total });
        const pref = `fin-ot-bl-${finOtrosBlocDomKey(k)}`;
        const slice = reporteSliceCliente(rows, pg, perBl);
        const pager = reporteHtmlPaginador(pref, pg, total, perBl);
        const tr = slice
            .map(
                (r) => `<tr>
                <td class="reporte-td-img">${reporteThumbImg(r.logo || '', r.nombre || '')}</td>
                <td>${esc(r.nombre)}</td>
                <td class="ag-num">${esc(String(r.n_movimiento_torneo ?? 0))}</td>
                <td class="ag-num">${esc(String(r.deuda_eur))}</td>
                <td class="ag-num">${esc(String(r.pagado_eur))}</td>
                <td class="ag-num ag-num--bal">${esc(String(r.saldo_eur))}</td>
                <td class="reporte-td-acciones">${accionesFin(r)}</td>
            </tr>`
            )
            .join('');
        return `<div class="reporte-vista">${pager}${htmlFvdTableShell(`<table class="fvd-table admin-crud-table admin-crud-table--compact text-sm">
            <thead><tr><th class="reporte-th-img">Logo</th><th>Asociación</th><th class="ag-num" title="Filas en movimiento_torneo">Mov.</th><th class="ag-num">Deuda €</th><th class="ag-num">Pagado verif. €</th><th class="ag-num">Saldo €</th><th class="reporte-th-acciones">Acciones</th></tr></thead>
            <tbody>${tr}</tbody>
            </table>`)}</div>`;
    };

    let tablasHtml = '';
    if (consolidar && Array.isArray(data.bloques_por_torneo)) {
        const bloques = data.bloques_por_torneo;
        tablasHtml = bloques
            .map((bl, idx) => {
                const tor = bl.torneo || {};
                const tid = tor.torneo_id != null ? parseInt(String(tor.torneo_id), 10) : 0;
                const nombre = tor.nombre ? String(tor.nombre) : `Torneo ${tid}`;
                const fin = tor.finalizado_en
                    ? ' <span class="ag-muted">(torneo finalizado)</span>'
                    : ' <span class="ag-muted">(en curso o sin cierre registrado)</span>';
                const rows = bl.resumen || [];
                const statKey = tid > 0 ? String(tid) : `i${idx}`;
                return `<section class="ag-fin-bloque-torneo">
                    <h3 class="ag-subtitle ag-fin-section-title">${esc(nombre)}${fin}</h3>
                    <p class="ag-fin-bloque-torneo-meta">Cuentas del torneo · ref. <code>${esc(String(tid))}</code></p>
                    ${filasTablaBloqueConPager(rows, statKey)}
                </section>`;
            })
            .join('');
    } else {
        const rowsAll = data.resumen || [];
        const pg = listState.fin;
        const per = REPORTE_FILAS_POR_PAGINA;
        const rows = rowsAll.filter((r) => (parseInt(String(r.n_movimiento_torneo ?? 0), 10) || 0) > 0);
        const total = rows.length;
        const slice = reporteSliceCliente(rows, pg);
        const pagerFin = reporteHtmlPaginador('fin-res-otros', pg, total, per);
        const tr =
            total > 0
                ? slice
                      .map(
                          (r) => `<tr>
                <td class="reporte-td-img">${reporteThumbImg(r.logo || '', r.nombre || '')}</td>
                <td>${esc(r.nombre)}</td>
                <td class="ag-num">${esc(String(r.n_movimiento_torneo ?? 0))}</td>
                <td class="ag-num">${esc(String(r.deuda_eur))}</td>
                <td class="ag-num">${esc(String(r.pagado_eur))}</td>
                <td class="ag-num ag-num--bal">${esc(String(r.saldo_eur))}</td>
                <td class="reporte-td-acciones">${accionesFin(r)}</td>
            </tr>`
                      )
                      .join('')
                : '<tr><td colspan="7" class="ag-muted">Ninguna asociación tiene movimiento para el torneo seleccionado.</td></tr>';
        tablasHtml = `<div class="reporte-vista ag-fin-reporte-vista">${pagerFin}
        <section class="ag-fin-col">
            <h3 class="ag-subtitle ag-fin-section-title">Asociaciones con movimiento</h3>
            ${htmlFvdTableShell(`<table class="fvd-table admin-crud-table admin-crud-table--compact text-sm">
                <thead><tr><th class="reporte-th-img">Logo</th><th>Asociación</th><th class="ag-num" title="Filas en movimiento_torneo">Mov.</th><th class="ag-num">Deuda €</th><th class="ag-num">Pagado verif. €</th><th class="ag-num">Saldo €</th><th class="reporte-th-acciones">Acciones</th></tr></thead>
                <tbody>${tr}</tbody>
            </table>`)}
        </section>
        </div>`;
    }

    el.innerHTML = `<div class="ag-fin-shell">
        ${bannerOt}
        ${barraOtros}
        <p class="ag-fin-shell-links"><a class="ag-inline-link ag-fin-shell-link" href="#" id="fin-otros-link-principal">← Estado de cuentas (un torneo)</a></p>
        <div class="ag-fin-head ag-fin-head-inline">
            <div class="ag-fin-balance ag-fin-balance--compact">
                <span><strong>Integral</strong> — Deuda: <em>${esc(String(int.deuda_eur))} €</em></span>
                <span>Pagado (verif.): <em>${esc(String(int.pagado_eur))} €</em></span>
                <span>Saldo: <em>${esc(String(int.saldo_eur))} €</em></span>
            </div>
            <div class="ag-fin-tasa ag-fin-tasa--compact">
                <div class="ag-fin-tasa-row ag-fin-tasa-row--compact">
                    <span class="ag-fin-label-inline">Tasa oficial Bs/EUR</span>
                    <input type="number" step="0.0001" min="0.0001" id="fin-otros-tasa-input" class="ag-fin-input ag-fin-input--tasa-sm" value="${esc(String(tasa))}" title="Se guarda al modificar">
                </div>
            </div>
        </div>
        ${tablasHtml}</div>`;

    if (!consolidar) {
        const rowsAll = data.resumen || [];
        const rows = rowsAll.filter((r) => (parseInt(String(r.n_movimiento_torneo ?? 0), 10) || 0) > 0);
        const total = rows.length;
        reporteLigarPaginador('fin-res-otros', listState.fin, total, (np) => {
            listState.fin = np;
            void loadFinanzasOtrosTorneosPanel();
        }, REPORTE_FILAS_POR_PAGINA);
    } else {
        for (const { statKey, total } of bloquesPagerMeta) {
            const pref = `fin-ot-bl-${finOtrosBlocDomKey(statKey)}`;
            const pg = listState._finOtrosBlocPag[statKey] ?? 1;
            reporteLigarPaginador(pref, pg, total, (np) => {
                listState._finOtrosBlocPag[statKey] = np;
                void loadFinanzasOtrosTorneosPanel();
            }, perBl);
        }
    }

    wireBarraTorneoFinanzas(el, 'fin-otros', {
        onTorneoChange: (v) => {
            listState._finOtrosTorneoId = v;
            if (v > 0) guardarTorneoFinanzas(v);
            listState.fin = 1;
            listState._finOtrosBlocPag = {};
            void loadFinanzasOtrosTorneosPanel();
        },
        onConsolidarChange: (checked) => {
            listState._finOtrosConsolidar = checked;
            if (checked) listState._finOtrosTorneoId = 0;
            listState.fin = 1;
            listState._finOtrosBlocPag = {};
            void loadFinanzasOtrosTorneosPanel();
        },
        onActualizar: async () => {
            const fb = el.querySelector('#fin-otros-deuda-fb');
            if (fb) fb.textContent = 'Procesando…';
            const tidAct = consolidar ? 0 : tidOtros > 0 ? tidOtros : listState._finOtrosTorneoId;
            const r = await postActualizarDeudas(fetchJson, tidAct);
            if (fb) fb.textContent = r.data.message || (r.res.ok && r.data.ok ? 'Listo.' : 'Error');
            if (r.res.ok && r.data.ok) void loadFinanzasOtrosTorneosPanel();
        },
    });
    el.querySelector('#fin-otros-link-principal')?.addEventListener('click', (ev) => {
        ev.preventDefault();
        if (tidOtros > 0) listState._finTorneoPanelId = tidOtros;
        navigateAdmingralWorkspace('fin', 'Estado de cuentas y deudas');
    });

    wireFinanzaTasaAutoSave(el, 'fin-otros-tasa-input');
    } catch (e) {
        console.error('loadFinanzasOtrosTorneosPanel', e);
        el.innerHTML = `<p class="error">Error al mostrar finanzas por torneo: ${esc(e && e.message ? String(e.message) : 'desconocido')}</p>
            <p><button type="button" class="btn-secondary" id="fin-otros-retry">Reintentar</button></p>`;
        el.querySelector('#fin-otros-retry')?.addEventListener('click', () => void loadFinanzasOtrosTorneosPanel());
    }
}

const TAB_DEF = [
    { id: 'org', label: 'Organización FVD', cap: 'organizacion' },
    { id: 'tor', label: 'Torneos', cap: 'torneos' },
    { id: 'aso', label: 'Asociaciones', cap: 'asociaciones' },
    { id: 'usr', label: 'Usuarios', cap: 'usuarios' },
];

function renderTabsAndPanels() {
    const caps = ctx.capabilities || {};
    const tabsEl = document.getElementById('admin-tabs');
    const panelsEl = document.getElementById('admin-tab-panels');
    if (!tabsEl || !panelsEl) return;
    const visible = TAB_DEF.filter((t) => caps[t.cap]?.read);
    tabsEl.innerHTML = visible
        .map(
            (t, i) =>
                `<button type="button" class="admin-tab${i === 0 ? ' is-active' : ''}" data-tab="${esc(t.id)}">${esc(t.label)}</button>`
        )
        .join('');
    panelsEl.innerHTML = visible
        .map(
            (t, i) =>
                `<section class="admin-tab-panel${i === 0 ? '' : ' is-hidden'}" data-panel="${esc(t.id)}" id="panel-${esc(t.id)}"></section>`
        )
        .join('');
    tabsEl.querySelectorAll('.admin-tab').forEach((btn) => {
        btn.addEventListener('click', () => activateTab(btn.getAttribute('data-tab') || ''));
    });
    if (visible[0]) {
        loadTabContent(visible[0].id);
    }
}

function activateTab(id) {
    document.querySelectorAll('.admin-tab').forEach((b) => {
        b.classList.toggle('is-active', b.getAttribute('data-tab') === id);
    });
    document.querySelectorAll('.admin-tab-panel').forEach((p) => {
        p.classList.toggle('is-hidden', p.getAttribute('data-panel') !== id);
    });
    loadTabContent(id);
}

const listState = {
    tor: 1,
    aso: 1,
    usr: 1,
    fin: 1,
    /** Paginación supervisión FVD (segmento en panel-sup). */
    sup: 1,
    _supSlice: '',
    /** Páginas por torneo id en Finanzas «Otros torneos» modo consolidar (claves string numéricas). */
    _finOtrosBlocPag: /** @type {Record<string, number>} */ ({}),
    _finTorneoPanelId: 0,
    _finGrupoPanelId: 0,
    _finAsocDetalleId: 0,
    _finOtrosTorneoId: 0,
    _finOtrosConsolidar: false,
    org: 1,
    atl: 1,
    _usrQ: '',
    _usrStatus: /** @type {number|null} */ (null),
    _asoEstatus: /** @type {number|null} */ (null),
    _atlAsocId: 0,
    _atlUsrStatus: /** @type {number|string} */ (''),
    _usrHideStatusFilter: false,
    /** '' | 'en_proceso' | 'realizados' — filtro listado torneos */
    _torFase: '',
    /** '' | 'sin' | id numérico — filtro campeonato (solo admin. gral.) */
    _torGrupoList: '',
    /** Panel delegado — paginación listados */
    delAtl: 1,
    delTrRep: 1,
    _delTrRepQ: '',
    /** `todos` | `solicitados` — reporte traspaso (delegado) */
    _delTrRepFiltroSol: 'todos',
    delCar: 1,
    _delCarQ: '',
    /** `todos` | `solicitados` — panel delegado Carnet */
    _delCarFiltroSol: 'todos',
    /** Torneo seleccionado (delegado): movimientos e inscripciones */
    delegadoTorneoId: 0,
};

async function loadTabContent(id) {
    if (id === 'org') return loadOrgPanel();
    if (id === 'tor') return loadTorneosPanel();
    if (id === 'aso') return loadAsociacionesPanel();
    if (id === 'usr') return loadUsuariosPanel();
}

function usuarioSiguienteStatusToggle(statusActual) {
    const s = Number(statusActual);
    return s === 9 ? 0 : 9;
}

async function loadOrgPanel() {
    const el = document.getElementById('panel-org');
    if (!el) return;
    const { res, data } = await fetchJson('api/crud_organizacion.php');
    if (!res.ok || !data.ok) {
        el.innerHTML = `<p class="error">${esc(data.message || 'Error al cargar')}</p>`;
        return;
    }
    const items = data.items || [];
    const total = typeof data.total === 'number' ? data.total : items.length;
    const canW = ctx.capabilities?.organizacion?.write;
    const p = listState.org;
    const slice = reporteSliceCliente(items, p);
    const pager = reporteHtmlPaginador('org', p, total);
    el.innerHTML = wrapReporte(
        '<h2 class="admin-section-title">Organización rectora</h2>' +
        pager +
        '<div class="admin-asoc-pane"><table class="fvd-table admin-crud-table admin-crud-table--compact text-sm"><thead><tr><th class="reporte-th-img">Logo</th><th>Nombre</th><th>Estatus</th><th>Acciones</th></tr></thead><tbody>' +
        slice
            .map(
                (r) =>
                    `<tr>
            <td class="reporte-td-img">${reporteThumbImg(r.logo || '', r.nombre || '')}</td>
            <td>${esc(r.nombre)}</td>
            <td>${esc(r.estatus)}</td>
            <td class="reporte-td-acciones">${reporteHtmlAcciones({
                prefijo: 'org',
                id: r.id,
                campoEstado: 'estatus',
                valorEstado: r.estatus,
                puedeEscribir: !!canW,
            })}</td>
        </tr>`
            )
            .join('') +
        '</tbody></table></div>'
    );
    reporteLigarPaginador('org', p, total, (np) => {
        listState.org = np;
        loadOrgPanel();
    });
    reporteDelegarAcciones(el, 'org', {
        onVer: (id) => {
            const row = items.find((x) => String(x.id) === id);
            if (row) openOrgEditor(row, { readOnly: true });
        },
        onEditar: (id) => {
            const row = items.find((x) => String(x.id) === id);
            if (row && canW) openOrgEditor(row);
        },
        onToggle: async (id, _campo, valorActual) => {
            if (!canW) return;
            const next = reporteToggleEstatusBinario(valorActual);
            const d = await fetchJson('api/crud_organizacion.php', {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: parseInt(id, 10), estatus: next }),
            });
            if (d.res.ok && d.data.ok) {
                showGlobalMsg('Estatus actualizado.', true);
                loadOrgPanel();
            } else showGlobalMsg(d.data.message || 'Error', false);
        },
    });
}

/**
 * @param {HTMLElement} container
 * @param {string} fileInputId
 * @param {string} previewImgId
 * @param {string} hiddenLogoSelector
 */
function wireEntidadLogoPreview(container, fileInputId, previewImgId, hiddenLogoSelector) {
    const fileInp = container.querySelector('#' + fileInputId);
    const img = container.querySelector('#' + previewImgId);
    const hidden = container.querySelector(hiddenLogoSelector);
    if (!fileInp || !img || !hidden) return;
    const applySrc = (src) => {
        if (src) {
            img.src = src;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
    };
    const initial = (hidden.value || '').trim() || FVD_BRAND.loginPng;
    applySrc(initial);
    fileInp.addEventListener('change', () => {
        const f = fileInp.files && fileInp.files[0];
        if (!f || !f.type.startsWith('image/')) {
            applySrc((hidden.value || '').trim() || FVD_BRAND.loginPng);
            return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
            const r = e.target && e.target.result;
            applySrc(typeof r === 'string' ? r : '');
        };
        reader.readAsDataURL(f);
    });
}

/**
 * @param {HTMLElement} container
 * @param {object|null} row torneo row or null
 */
function wireTorneoMediaPreviews(container, row) {
    const aficheH = container.querySelector('input[name="afiche"]');
    const img = container.querySelector('#tor-afiche-preview');
    const fileAf = container.querySelector('#tor-afiche-file');
    const invH = container.querySelector('input[name="invitacion"]');
    const invLabel = container.querySelector('#tor-inv-filename');
    const fileInv = container.querySelector('#tor-inv-file');
    if (img && aficheH) {
        const p = (aficheH.value || '').trim();
        if (p) {
            img.src = p;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
    }
    if (fileAf && img && aficheH) {
        fileAf.addEventListener('change', () => {
            const f = fileAf.files && fileAf.files[0];
            if (!f || !f.type.startsWith('image/')) {
                const p = (aficheH.value || '').trim();
                if (p) {
                    img.src = p;
                    img.style.display = 'block';
                } else {
                    img.removeAttribute('src');
                    img.style.display = 'none';
                }
                return;
            }
            const reader = new FileReader();
            reader.onload = (e) => {
                const u = e.target && e.target.result;
                if (typeof u === 'string') {
                    img.src = u;
                    img.style.display = 'block';
                }
            };
            reader.readAsDataURL(f);
        });
    }
    const setInvLabel = () => {
        if (!invLabel) return;
        const f = fileInv && fileInv.files && fileInv.files[0];
        if (f) {
            invLabel.textContent = 'Seleccionado: ' + f.name;
            return;
        }
        const p = invH ? (invH.value || '').trim() : '';
        invLabel.textContent = p ? 'En servidor: ' + p : 'Sin invitación adjunta';
    };
    setInvLabel();
    if (fileInv) fileInv.addEventListener('change', setInvLabel);
}

/**
 * Lista de grupos de evento (solo admin. gral.).
 * @returns {Promise<Array<{ grupo_evento_id: number, etiqueta: string, n_torneos: number }>>}
 */
async function fetchTorneoGruposEventoAg() {
    if (!ctx || ctx.rol !== 'admingral') return [];
    const { res, data } = await fetchJson('api/crud_torneos.php?grupos_evento=1');
    if (!res.ok || !data.ok || !Array.isArray(data.grupos)) return [];
    return data.grupos;
}

/**
 * Selector rápido de campeonato → copia a `grupo_evento_id` (solo si existe #tor-grupo-pick).
 * @param {HTMLElement} inner
 */
/**
 * Modo campeonato: deshabilita género/grupo/rondas según plantilla y muestra ayuda.
 * @param {HTMLElement} inner
 */
function wireTorneoCampeonatoMode(inner) {
    const sel = inner.querySelector('#tor-modo-registro');
    const hint = inner.querySelector('#tor-camp-hint');
    const tipo = inner.querySelector('select[name="tipo"]');
    const rondas = inner.querySelector('input[name="rondas"]');
    const gidInp = inner.querySelector('#tor-grupo-inp');
    const gidPick = inner.querySelector('#tor-grupo-pick');
    if (!sel) return;

    const apply = () => {
        const modo = sel.value || 'simple';
        const esGen = modo === 'campeonato_genero';
        const esCat = modo === 'campeonato_categoria';
        const esCamp = esGen || esCat;
        if (tipo) {
            tipo.required = modo === 'simple';
            tipo.disabled = esGen;
            if (esGen) tipo.value = '';
        }
        if (rondas) {
            rondas.disabled = esCat;
            if (esCat) rondas.value = '5';
        }
        if (gidInp) {
            gidInp.disabled = esCamp;
            if (esCamp) gidInp.value = '';
        }
        if (gidPick) gidPick.disabled = esCamp;
        if (hint) {
            if (esGen) {
                hint.textContent =
                    'Se crearán 2 torneos con el mismo grupo: MASCULINO y FEMENINO (misma fecha, lugar, clase y rondas).';
            } else if (esCat) {
                hint.textContent =
                    'Se crearán 3 torneos con el mismo grupo: CATEG SUB 12 (5 rondas), SUB 15 y SUB 18 (7 rondas cada uno).';
            } else {
                hint.textContent = '';
            }
        }
    };
    sel.addEventListener('change', apply);
    apply();
}

function wireTorneoGrupoPick(inner) {
    const pick = inner.querySelector('#tor-grupo-pick');
    const inp = inner.querySelector('#tor-grupo-inp');
    if (!pick || !inp) return;
    const syncPickFromInp = () => {
        const v = (inp.value || '').trim();
        if (!v) {
            pick.value = '';
            return;
        }
        let found = false;
        for (let i = 0; i < pick.options.length; i++) {
            if (pick.options[i].value === v) {
                pick.selectedIndex = i;
                found = true;
                break;
            }
        }
        if (!found) {
            const o = document.createElement('option');
            o.value = v;
            o.textContent = `#${v} (actual)`;
            pick.insertBefore(o, pick.options[1] || null);
            pick.value = v;
        }
    };
    pick.addEventListener('change', () => {
        const v = pick.value;
        if (v === '') return;
        inp.value = v;
    });
    inp.addEventListener('input', () => {
        const v = (inp.value || '').trim();
        if (v === '') {
            pick.value = '';
            return;
        }
        let found = false;
        for (let i = 0; i < pick.options.length; i++) {
            if (pick.options[i].value === v) {
                pick.selectedIndex = i;
                found = true;
                break;
            }
        }
        if (!found) pick.value = '';
    });
    syncPickFromInp();
}

/**
 * @param {Array<{ fechator: string, lugar: string, torneos: Array<{ torneo: number, nombre?: string, clavetor?: string }> }>} grupos
 */
function buildTorneoAgruparModalHtml(grupos) {
    if (!grupos || grupos.length === 0) {
        return `<div class="tor-agru">
            <p class="tor-agru__intro">No hay torneos elegibles para asociar. Se requieren al menos <strong>dos</strong> torneos <strong>pendientes</strong> (sin fecha de cierre), <strong>sin campeonato</strong> (sin grupo), con la <strong>misma fecha</strong> y el <strong>mismo lugar</strong> registrados.</p>
        </div>`;
    }
    let h = `<div class="tor-agru">
        <p class="tor-agru__intro">Marque entre <strong>2 y 3</strong> torneos de <strong>un solo bloque</strong> (misma fecha y lugar). Si elige un torneo de otro bloque, se desmarcan los anteriores.</p>`;
    grupos.forEach((g, idx) => {
        const lid = `tor-agru-h-${idx}`;
        h += `<section class="tor-agru-cluster" aria-labelledby="${lid}">
            <h3 id="${lid}" class="tor-agru-cluster__hdr">${esc(g.fechator)} · ${esc(g.lugar || '—')}</h3>
            <ul class="tor-agru-list">`;
        (g.torneos || []).forEach((t) => {
            const tid = t.torneo != null ? String(t.torneo) : '';
            h += `<li class="tor-agru-row">
                <label class="tor-agru-label">
                    <input type="checkbox" class="tor-agru-chk" data-cluster="${idx}" value="${esc(tid)}">
                    <span><strong>${esc(String(t.clavetor || ''))}</strong> — ${esc(String(t.nombre || ''))}</span>
                </label>
            </li>`;
        });
        h += '</ul></section>';
    });
    h += `<div class="tor-agru-actions">
        <button type="button" class="btn-primary" id="tor-agru-submit" disabled>Asociar seleccionados</button>
    </div></div>`;
    return h;
}

/**
 * @param {HTMLElement} inner
 */
function wireTorneoAgruparModal(inner) {
    const chks = Array.from(inner.querySelectorAll('.tor-agru-chk'));
    const btn = inner.querySelector('#tor-agru-submit');
    const updateBtn = () => {
        const on = chks.filter((c) => c.checked);
        if (btn) btn.disabled = on.length < 2 || on.length > 3;
    };
    chks.forEach((c) => {
        c.addEventListener('change', () => {
            if (c.checked) {
                const cl = c.getAttribute('data-cluster') || '';
                chks.forEach((x) => {
                    if ((x.getAttribute('data-cluster') || '') !== cl) x.checked = false;
                });
                const same = chks.filter((x) => x.checked && (x.getAttribute('data-cluster') || '') === cl);
                if (same.length > 3) {
                    c.checked = false;
                    showGlobalMsg('Como máximo 3 torneos por agrupación.', false);
                }
            }
            updateBtn();
        });
    });
    btn?.addEventListener('click', async () => {
        const sel = chks.filter((c) => c.checked).map((c) => parseInt(c.value, 10)).filter((n) => n > 0);
        if (sel.length < 2 || sel.length > 3) return;
        const { res, data } = await fetchJson('api/torneos_agrupar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ torneo_ids: sel }),
        });
        if (res.ok && data.ok) {
            showGlobalMsg(data.message || 'Torneos asociados.', true);
            closeModal();
            loadTorneosPanel();
        } else showGlobalMsg(data.message || 'Error', false);
    });
}

async function openTorneoAgruparModal() {
    if (!ctx || ctx.rol !== 'admingral') return;
    const { res, data } = await fetchJson('api/torneos_agrupar.php');
    if (!res.ok || !data.ok) {
        showGlobalMsg(data.message || 'No se pudieron cargar los candidatos.', false);
        return;
    }
    const grupos = data.grupos || [];
    openModal(
        'Asociar torneos (mismo día y lugar)',
        buildTorneoAgruparModalHtml(grupos),
        null,
        modalTorneoCrud({
            hideSave: true,
            afterRender(inner) {
                wireTorneoAgruparModal(inner);
            },
        })
    );
}

/**
 * @param {HTMLElement} container
 * @param {object|null} row usuario o null
 */
function wireUsuarioImgPreviews(container, row) {
    const wireOne = (fileId, imgId, hiddenSel) => {
        const fileInp = container.querySelector('#' + fileId);
        const img = container.querySelector('#' + imgId);
        const hidden = container.querySelector(hiddenSel);
        if (!fileInp || !img || !hidden) return;
        const apply = (src) => {
            if (src) {
                img.src = src;
                img.style.display = 'block';
            } else {
                img.removeAttribute('src');
                img.style.display = 'none';
            }
        };
        const initial = (hidden.value || '').trim() || FVD_BRAND.loginPng;
        apply(initial);
        fileInp.addEventListener('change', () => {
            const f = fileInp.files && fileInp.files[0];
            if (!f || !f.type.startsWith('image/')) {
                apply((hidden.value || '').trim() || FVD_BRAND.loginPng);
                return;
            }
            const reader = new FileReader();
            reader.onload = (e) => {
                const u = e.target && e.target.result;
                apply(typeof u === 'string' ? u : '');
            };
            reader.readAsDataURL(f);
        });
    };
    wireOne('usr-foto-file', 'usr-foto-preview', 'input[name="urlimgfoto"]');
    wireOne('usr-ced-file', 'usr-ced-preview', 'input[name="urlimgcedula"]');
}

function openOrgEditor(row, opts = {}) {
    const ro = !!(opts && opts.readOnly);
    const logoVal = (row.logo && String(row.logo).trim()) || '';
    const fields = [
        ['nombre', 'Nombre', 'text'],
        ['direccion', 'Dirección', 'text'],
        ['telefono', 'Teléfono', 'text'],
        ['email', 'Email', 'email'],
        ['numreg', 'Nº registro', 'text'],
        ['providencia', 'Providencia', 'text'],
        ['responsable_principal', 'Responsable', 'text'],
        ['indica', 'Indica (número)', 'number'],
        ['estatus', 'Estatus (1=activo)', 'number'],
        ['fechreg', 'Fecha registro (AAAA-MM-DD)', 'date'],
    ];
    const gridFields = fields
        .map(([k, lab, typ]) => {
            const ph = esc(lab);
            const v = esc(row[k] ?? '');
            const full = k === 'nombre' ? ' col-span-full' : '';
            return `<label class="afiliacion-field${full}"><span class="text-sm">${esc(lab)}</span><input type="${esc(typ)}" name="${esc(k)}" value="${v}" placeholder="${ph}" title="${ph}"></label>`;
        })
        .join('');
    const logoBlock = `
        <div class="admin-logo-block admin-logo-block--org-right">
            <span class="admin-logo-block-title">Logo</span>
            <input type="hidden" name="logo" value="${esc(logoVal)}">
            <input type="file" id="org-logo-file" class="admin-org-logo-file" accept="image/jpeg,image/png,image/webp,image/x-icon,.ico,.jpg,.jpeg,.png,.webp" aria-label="Archivo de imagen del logo">
            <div class="admin-org-logo-frame">
                <img id="org-logo-preview" class="admin-org-logo-img" alt="Vista previa del logo">
            </div>
        </div>`;
    const html = `<div class="admin-org-form admin-org-form--org-editor admin-org-form--split"><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 gap-y-3 admin-org-form__fields">${gridFields}</div>${logoBlock}</div>`;
    openModal(
        ro ? 'Ver organización FVD' : 'Editar organización FVD',
        html,
        ro
            ? null
            : async () => {
            const inner = document.getElementById('admin-modal-inner');
            if (!inner) return;
            const body = { id: row.id };
            inner.querySelectorAll('input[name]').forEach((inp) => {
                const n = inp.getAttribute('name');
                if (n) body[n] = inp.value;
            });
            const fileInp = inner.querySelector('#org-logo-file');
            if (fileInp && fileInp.files && fileInp.files[0]) {
                const fd = new FormData();
                fd.append('entidad', 'organizacion');
                fd.append('id', String(row.id));
                fd.append('logo', fileInp.files[0]);
                const { res, data: upData } = await uploadPanelAsset(fd);
                if (!res.ok || !upData.ok) {
                    showGlobalMsg(upData.message || 'No se pudo subir el logo.', false);
                    return;
                }
                body.logo = upData.path;
            }
            const { res, data } = await fetchJson('api/crud_organizacion.php', {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            if (res.ok && data.ok) {
                showGlobalMsg(data.message || 'Guardado.', true);
                closeModal();
                loadOrgPanel();
            } else {
                showGlobalMsg(data.message || 'Error al guardar', false);
            }
        },
        modalForm60({
            readOnly: ro,
            hideSave: ro,
            afterRender(inner) {
                wireEntidadLogoPreview(inner, 'org-logo-file', 'org-logo-preview', 'input[name="logo"]');
            },
        })
    );
}

async function loadTorneosPanel(panelHostId = 'panel-tor') {
    const el = document.getElementById(panelHostId);
    if (!el) return;
    const p = listState.tor;
    const per = REPORTE_FILAS_POR_PAGINA;
    let gruposToolbar = [];
    if (ctx?.rol === 'admingral') {
        gruposToolbar = await fetchTorneoGruposEventoAg();
    }
    const fase = listState._torFase;
    const faseQ = fase === 'en_proceso' || fase === 'realizados' ? `&fase=${encodeURIComponent(fase)}` : '';
    const gSel = listState._torGrupoList || '';
    const grpQ = ctx?.rol === 'admingral' && gSel !== '' ? `&grupo=${encodeURIComponent(gSel)}` : '';
    const { res, data } = await fetchJson(`api/crud_torneos.php?page=${p}&perPage=${per}${faseQ}${grpQ}`);
    if (!res.ok || !data.ok) {
        el.innerHTML = `<p class="error">${esc(data.message || 'Error')}</p>`;
        return;
    }
    const cap = ctx.capabilities?.torneos || {};
    const items = data.items || [];
    const total = typeof data.total === 'number' ? data.total : items.length;
    const pager = reporteHtmlPaginador('tor', p, total, per);
    const faseSel = fase || '';
    const filtroFaseHtml = `<label class="admin-toolbar-filter"><span>Estado</span><select id="tor-filter-fase" class="admin-search" title="En proceso: torneo sin fecha de cierre (finalizado_en vacío). Realizados: ya cerrado.">
            <option value=""${faseSel === '' ? ' selected' : ''}>Todos</option>
            <option value="en_proceso"${faseSel === 'en_proceso' ? ' selected' : ''}>En proceso</option>
            <option value="realizados"${faseSel === 'realizados' ? ' selected' : ''}>Realizados</option>
        </select></label>`;
    const filtroCampeonatoHtml =
        ctx?.rol === 'admingral'
            ? `<label class="admin-toolbar-filter"><span>Campeonato</span><select id="tor-filter-grupo" class="admin-search" title="Filtra por grupo_evento_id: torneos del mismo campeonato comparten ID. Sin agrupar: sin ID.">
            <option value=""${gSel === '' ? ' selected' : ''}>Todos</option>
            <option value="sin"${gSel === 'sin' ? ' selected' : ''}>Sin agrupar</option>
            ${gruposToolbar
                .map(
                    (g) =>
                        `<option value="${esc(String(g.grupo_evento_id))}"${gSel === String(g.grupo_evento_id) ? ' selected' : ''}>#${esc(String(g.grupo_evento_id))} — ${esc(g.etiqueta || '')}</option>`
                )
                .join('')}
        </select></label>`
            : '';
    el.innerHTML = wrapReporte(
        `<h2 class="admin-section-title">Torneos</h2>
        <div class="admin-toolbar admin-toolbar--tor-filters">
            ${cap.create ? '<button type="button" class="btn-primary" id="btn-new-torneo">Nuevo torneo</button>' : ''}
            ${ctx?.rol === 'admingral' ? '<button type="button" class="btn-secondary" id="btn-tor-agru">Asociar torneos</button>' : ''}
            ${filtroFaseHtml}
            ${filtroCampeonatoHtml}
        </div>
        ${pager}
        <table class="fvd-table admin-crud-table"><thead><tr><th class="reporte-th-img">Afiche</th><th>Clave</th><th>Nombre</th><th>Fecha</th><th>Grupo</th><th>Estatus</th><th>Acciones</th></tr></thead><tbody>` +
        items
            .map((r) => {
                const id = r.torneo;
                const acc = `${reporteHtmlAcciones({
                    prefijo: 'tor',
                    id,
                    campoEstado: 'estatus',
                    valorEstado: r.estatus,
                    puedeEscribir: !!cap.write,
                })}`;
                const gid =
                    r.grupo_evento_id != null && String(r.grupo_evento_id).trim() !== '' && Number(r.grupo_evento_id) > 0
                        ? esc(String(r.grupo_evento_id))
                        : '—';
                return `<tr><td class="reporte-td-img">${reporteThumbImg(r.afiche || '', r.nombre || '')}</td><td>${esc(r.clavetor)}</td><td>${esc(r.nombre)}</td><td>${esc(r.fechator)}</td><td>${gid}</td><td>${esc(r.estatus)}</td><td class="reporte-td-acciones">${acc}</td></tr>`;
            })
            .join('') +
        '</tbody></table>'
    );
    document.getElementById('btn-new-torneo')?.addEventListener('click', () => openNewTorneo());
    document.getElementById('btn-tor-agru')?.addEventListener('click', () => void openTorneoAgruparModal());
    document.getElementById('tor-filter-fase')?.addEventListener('change', (ev) => {
        listState._torFase = /** @type {HTMLSelectElement} */ (ev.target).value;
        listState.tor = 1;
        loadTorneosPanel();
    });
    document.getElementById('tor-filter-grupo')?.addEventListener('change', (ev) => {
        listState._torGrupoList = /** @type {HTMLSelectElement} */ (ev.target).value;
        listState.tor = 1;
        loadTorneosPanel();
    });
    reporteLigarPaginador('tor', p, total, (np) => {
        listState.tor = np;
        loadTorneosPanel();
    }, per);
    reporteDelegarAcciones(el, 'tor', {
        onVer: (id) => {
            const row = items.find((x) => String(x.torneo) === id);
            if (row) void openTorneoEditor(row, { readOnly: true });
        },
        onEditar: (id) => {
            const row = items.find((x) => String(x.torneo) === id);
            if (row && cap.write) void openTorneoEditor(row);
        },
        onToggle: async (id, _campo, valorActual) => {
            if (!cap.write) return;
            const next = reporteToggleEstatusBinario(valorActual);
            const d = await fetchJson('api/crud_torneos.php', {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ torneo: parseInt(id, 10), estatus: next }),
            });
            if (d.res.ok && d.data.ok) {
                showGlobalMsg('Estatus actualizado.', true);
                loadTorneosPanel();
            } else showGlobalMsg(d.data.message || 'Error', false);
        },
    });
}

/**
 * HTML del formulario CRUD torneo (layout alineado al portal de referencia).
 * @param {object|null} r fila o valores por defecto (alta)
 * @param {{ isNew?: boolean, orgNombre?: string, orgWarning?: boolean, esAdmingral?: boolean, gruposEvento?: Array<{ grupo_evento_id: number, etiqueta: string, n_torneos: number }> }} [meta]
 */
function torneoFieldsHtml(r, meta = {}) {
    const o = r || {};
    const isNew = meta.isNew != null ? meta.isNew : !o.torneo;
    const orgNombre = meta.orgNombre != null ? String(meta.orgNombre) : '—';
    const orgWarn = !!meta.orgWarning;
    const esAg = !!(meta && meta.esAdmingral);
    const gruposEv = Array.isArray(meta && meta.gruposEvento) ? meta.gruposEvento : [];
    const gidVal = o.grupo_evento_id != null && o.grupo_evento_id !== '' ? String(o.grupo_evento_id) : '';
    const modoRegVal = o.modo_registro != null ? String(o.modo_registro) : 'simple';
    const modoRegBlock = isNew
        ? `<label class="torneo-crud__field torneo-crud__field--full">
                <span>Tipo de registro *</span>
                <select name="modo_registro" id="tor-modo-registro" required>
                    <option value="simple"${modoRegVal === 'simple' ? ' selected' : ''}>Torneo único</option>
                    <option value="campeonato_genero"${modoRegVal === 'campeonato_genero' ? ' selected' : ''}>Campeonato por género (Masculino + Femenino)</option>
                    <option value="campeonato_categoria"${modoRegVal === 'campeonato_categoria' ? ' selected' : ''}>Campeonato por categoría (Sub 12, 15 y 18)</option>
                </select>
                <p id="tor-camp-hint" class="torneo-crud__hint"></p>
            </label>`
        : '';
    const grupoBlockHtml = esAg
        ? `<label class="torneo-crud__field torneo-crud__field--full">
                <span>Campeonato / grupo de evento</span>
                <select id="tor-grupo-pick" class="admin-search torneo-crud__grupo-pick" aria-label="Elegir campeonato existente">
                    <option value="">— Sin seleccionar (use el ID abajo) —</option>
                    ${gruposEv
                        .map(
                            (g) =>
                                `<option value="${esc(String(g.grupo_evento_id))}">#${esc(String(g.grupo_evento_id))} — ${esc(g.etiqueta || '')} (${esc(String(g.n_torneos))} torneo(s))</option>`
                        )
                        .join('')}
                </select>
                <input type="number" name="grupo_evento_id" id="tor-grupo-inp" min="1" step="1" value="${esc(gidVal)}" placeholder="ID compartido (vacío = sin campeonato)">
                <p class="torneo-crud__hint">Varios torneos con el mismo ID comparten un campeonato o evento.</p>
            </label>`
        : `<label class="torneo-crud__field">
                <span>Grupo evento (id opcional)</span>
                <input type="number" name="grupo_evento_id" id="tor-grupo-inp" min="1" step="1" value="${esc(gidVal)}" placeholder="Vacío = ninguno">
            </label>`;
    const ov = (k, def = '') => {
        const v = o[k];
        if (v === null || v === undefined || v === '') return def;
        return v;
    };
    const aficheVal = (o.afiche && String(o.afiche).trim()) || '';
    const invVal = (o.invitacion && String(o.invitacion).trim()) || '';
    const orgIdVal = ov('organizacion_id', '');
    const pub = Number(o.publicar_landing) === 1 || o.publicar_landing === true || o.publicar_landing === '1';
    const invDesp =
        Number(o.invitaciones_despachadas) === 1 || o.invitaciones_despachadas === true || o.invitaciones_despachadas === '1';
    const rkSel = String(ov('ranking', '1'));
    const estSel = String(ov('estatus', '0'));
    const fechatorStr = String(o.fechator ?? '');
    const fechaLimStr = String(o.fecha_limite_cambios ?? '');
    const claveBlock = isNew
        ? '<p class="torneo-crud__hint">La clave del torneo se generará al registrar.</p>'
        : `<label class="torneo-crud__field torneo-crud__field--full">
                <span>Clave torneo</span>
                <input type="text" class="torneo-crud__input-readonly" name="clavetor" value="${esc(String(o.clavetor ?? ''))}" readonly>
            </label>`;

    return `<div class="torneo-crud">
        <p class="torneo-crud__org"><span class="torneo-crud__org-label">Organización:</span> <strong>${esc(orgNombre)}</strong></p>
        ${orgWarn ? '<p class="torneo-crud__warn">No se detectó organización FVD en el listado. Revise la organización rectora antes de guardar.</p>' : ''}
        <input type="hidden" name="organizacion_id" value="${esc(String(orgIdVal))}">

        <div class="torneo-crud__grid">
            ${modoRegBlock}
            <label class="torneo-crud__field torneo-crud__field--span2">
                <span>Nombre del torneo *</span>
                <input type="text" name="nombre" required value="${esc(String(o.nombre ?? ''))}" placeholder="Ej: Campeonato Nacional 2026" autocomplete="off">
            </label>
            <label class="torneo-crud__field torneo-crud__field--span2">
                <span>Lugar</span>
                <input type="text" name="lugar" value="${esc(String(o.lugar ?? ''))}" placeholder="Ej: Club Central, sala principal">
            </label>

            <label class="torneo-crud__field">
                <span>Fecha *</span>
                <input type="date" name="fechator" required value="${esc(fechatorStr.slice(0, 10))}">
            </label>
            <label class="torneo-crud__field">
                <span>Tiempo (min)</span>
                <input type="number" name="tiempo" min="1" step="1" value="${esc(String(ov('tiempo', 35)))}">
            </label>
            <label class="torneo-crud__field">
                <span>Puntos</span>
                <input type="number" name="puntos" min="1" step="1" value="${esc(String(ov('puntos', 200)))}">
            </label>
            <label class="torneo-crud__field">
                <span>Rondas</span>
                <input type="number" name="rondas" min="1" step="1" value="${esc(String(ov('rondas', 9)))}">
            </label>

            <label class="torneo-crud__field">
                <span>Clase *</span>
                <select name="clase" required>
                    <option value="">Seleccionar…</option>
                    <option value="1">Individual</option>
                    <option value="2">Parejas</option>
                    <option value="3">Equipos</option>
                </select>
            </label>
            <label class="torneo-crud__field">
                <span>Modalidad (sexo) *</span>
                <select name="tipo" required>
                    <option value="">Seleccionar…</option>
                    <option value="1">Masculino</option>
                    <option value="2">Femenino</option>
                    <option value="3">Mixto</option>
                </select>
            </label>
            <label class="torneo-crud__field">
                <span>Jugadores por club</span>
                <input type="number" name="pareclub" min="0" step="1" value="${esc(String(ov('pareclub', 0)))}">
            </label>
            <label class="torneo-crud__field">
                <span>Ranking</span>
                <select name="ranking">
                    <option value="0"${rkSel === '0' ? ' selected' : ''}>No</option>
                    <option value="1"${rkSel !== '0' ? ' selected' : ''}>Sí</option>
                </select>
            </label>

            <label class="torneo-crud__field">
                <span>Costo</span>
                <input type="number" name="costotor" min="0" step="0.01" value="${esc(String(ov('costotor', '0')))}">
            </label>
            <label class="torneo-crud__field">
                <span>Estatus *</span>
                <select name="estatus" required>
                    <option value="0"${estSel === '0' ? ' selected' : ''}>Activo (0)</option>
                    <option value="1"${estSel !== '0' ? ' selected' : ''}>Inactivo (1)</option>
                </select>
            </label>
            ${grupoBlockHtml}
            <label class="torneo-crud__field">
                <span>Límite cambios (fecha)</span>
                <input type="date" name="fecha_limite_cambios" value="${esc(fechaLimStr.slice(0, 10))}">
            </label>

            <div class="torneo-crud__field torneo-crud__field--full">
                <span>Publicación e invitaciones</span>
                <div class="torneo-crud__toggles">
                    <label class="torneo-crud__toggle">
                        <input type="checkbox" name="publicar_landing" data-tor-bool${pub ? ' checked' : ''}>
                        Publicar en portal (landing)
                    </label>
                    <label class="torneo-crud__toggle">
                        <input type="checkbox" name="invitaciones_despachadas" data-tor-bool${invDesp ? ' checked' : ''}>
                        Invitaciones despachadas
                    </label>
                </div>
            </div>
            ${claveBlock}
        </div>

        <section class="torneo-crud__files" aria-label="Archivos del torneo">
            <h3 class="torneo-crud__files-title">Archivos del torneo</h3>
            <div class="torneo-crud__files-grid">
                <div class="torneo-crud__file-col">
                    <span class="torneo-crud__file-title">Afiche del torneo</span>
                    <input type="hidden" name="afiche" value="${esc(aficheVal)}">
                    <input type="file" id="tor-afiche-file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" aria-label="Subir afiche">
                    <div class="admin-logo-preview-wrap" style="margin-top:0.5rem">
                        <img id="tor-afiche-preview" alt="Vista previa afiche" class="admin-logo-preview-img" width="200" height="120" style="max-width:100%;height:auto;display:none">
                    </div>
                </div>
                <div class="torneo-crud__file-col">
                    <span class="torneo-crud__file-title">Invitación oficial (PDF o Word)</span>
                    <input type="hidden" name="invitacion" value="${esc(invVal)}">
                    <input type="file" id="tor-inv-file" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" aria-label="Subir invitación">
                    <p id="tor-inv-filename" class="admin-file-hint torneo-crud__hint"></p>
                </div>
                <div class="torneo-crud__file-col torneo-crud__file-col--muted">
                    <span class="torneo-crud__file-title">Normas / condiciones</span>
                    <p class="torneo-crud__hint">Pendiente: no hay columna en <code>torneosact</code> para un tercer archivo.</p>
                </div>
            </div>
        </section>
    </div>`;
}

async function openNewTorneo() {
    let orgNombre = '—';
    let orgId = '';
    const dOrg = await fetchJson('api/crud_organizacion.php');
    if (dOrg.res.ok && dOrg.data.ok && Array.isArray(dOrg.data.items) && dOrg.data.items.length > 0) {
        const it = dOrg.data.items[0];
        if (it.nombre != null) orgNombre = String(it.nombre);
        if (it.id != null) orgId = String(it.id);
    }
    const defaults = {
        tiempo: 35,
        puntos: 200,
        rondas: 9,
        ranking: 1,
        estatus: 0,
        publicar_landing: 1,
        pareclub: 0,
        invitaciones_despachadas: 0,
        organizacion_id: orgId,
        costotor: '0',
    };
    const gruposEv = await fetchTorneoGruposEventoAg();
    const esAg = ctx?.rol === 'admingral';
    openModal(
        'Nuevo torneo',
        torneoFieldsHtml(defaults, {
            isNew: true,
            orgNombre,
            orgWarning: orgId === '',
            esAdmingral: esAg,
            gruposEvento: gruposEv,
        }),
        async () => {
            const inner = document.getElementById('admin-modal-inner');
            if (!inner) return;
            const fa = inner.querySelector('#tor-afiche-file');
            const fi = inner.querySelector('#tor-inv-file');
            const hasFiles = (fa && fa.files && fa.files[0]) || (fi && fi.files && fi.files[0]);
            if (hasFiles) {
                const fd = new FormData();
                appendTorneoScalarsToFormData(fd, inner);
                if (fa && fa.files && fa.files[0]) fd.append('afiche', fa.files[0]);
                if (fi && fi.files && fi.files[0]) fd.append('invitacion', fi.files[0]);
                const res = await fetch('api/crud_torneos.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.ok) {
                    showGlobalMsg(data.message || 'Creado.', true);
                    closeModal();
                    loadTorneosPanel();
                } else showGlobalMsg(data.message || 'Error', false);
                return;
            }
            const body = collectTorneoScalarFields(inner);
            const { res, data } = await fetchJson('api/crud_torneos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            if (res.ok && data.ok) {
                showGlobalMsg(data.message || 'Creado.', true);
                closeModal();
                loadTorneosPanel();
            } else showGlobalMsg(data.message || 'Error', false);
        },
        modalTorneoCrud({
            afterRender(inner) {
                wireTorneoMediaPreviews(inner, null);
                wireTorneoGrupoPick(inner);
                wireTorneoCampeonatoMode(inner);
            },
        })
    );
}

function syncTorneoSelects(r) {
    const inner = document.getElementById('admin-modal-inner');
    if (!inner || !r) return;
    const t = inner.querySelector('select[name="tipo"]');
    if (t != null && r.tipo != null && r.tipo !== '') t.value = String(r.tipo);
    const c = inner.querySelector('select[name="clase"]');
    if (c != null && r.clase != null && r.clase !== '') c.value = String(r.clase);
    const rk = inner.querySelector('select[name="ranking"]');
    if (rk != null && r.ranking != null && r.ranking !== '') rk.value = String(r.ranking);
    const es = inner.querySelector('select[name="estatus"]');
    if (es != null && r.estatus != null && r.estatus !== '') es.value = String(r.estatus);
}

async function openTorneoEditor(r, opts = {}) {
    const ro = !!(opts && opts.readOnly);
    const tid = r.torneo;
    let orgNombre = '—';
    const oid = r.organizacion_id;
    if (oid != null && String(oid) !== '') {
        const d = await fetchJson(`api/crud_organizacion.php?id=${encodeURIComponent(String(oid))}`);
        if (d.res.ok && d.data.ok && d.data.item && d.data.item.nombre != null) orgNombre = String(d.data.item.nombre);
    } else {
        const d0 = await fetchJson('api/crud_organizacion.php');
        if (d0.res.ok && d0.data.ok && d0.data.items && d0.data.items[0] && d0.data.items[0].nombre != null) {
            orgNombre = String(d0.data.items[0].nombre);
        }
    }
    const gruposEv = await fetchTorneoGruposEventoAg();
    const esAg = ctx?.rol === 'admingral';
    openModal(
        ro ? 'Ver torneo' : 'Editar torneo',
        torneoFieldsHtml(r, { isNew: false, orgNombre, esAdmingral: esAg, gruposEvento: gruposEv }),
        ro
            ? null
            : async () => {
                  const inner = document.getElementById('admin-modal-inner');
                  if (!inner) return;
                  const body = { torneo: tid, ...collectTorneoScalarFields(inner) };
                  const fa = inner.querySelector('#tor-afiche-file');
                  if (fa && fa.files && fa.files[0]) {
                      const fd = new FormData();
                      fd.append('entidad', 'torneo');
                      fd.append('id', String(tid));
                      fd.append('campo', 'afiche');
                      fd.append('archivo', fa.files[0]);
                      const { res, data: up } = await uploadPanelAsset(fd);
                      if (!res.ok || !up.ok) {
                          showGlobalMsg(up.message || 'No se pudo subir el afiche.', false);
                          return;
                      }
                      body.afiche = up.path;
                  }
                  const fi = inner.querySelector('#tor-inv-file');
                  if (fi && fi.files && fi.files[0]) {
                      const fd = new FormData();
                      fd.append('entidad', 'torneo');
                      fd.append('id', String(tid));
                      fd.append('campo', 'invitacion');
                      fd.append('archivo', fi.files[0]);
                      const { res, data: up } = await uploadPanelAsset(fd);
                      if (!res.ok || !up.ok) {
                          showGlobalMsg(up.message || 'No se pudo subir la invitación.', false);
                          return;
                      }
                      body.invitacion = up.path;
                  }
                  const { res, data } = await fetchJson('api/crud_torneos.php', {
                      method: 'PATCH',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify(body),
                  });
                  if (res.ok && data.ok) {
                      showGlobalMsg(data.message || 'Guardado.', true);
                      closeModal();
                      loadTorneosPanel();
                  } else showGlobalMsg(data.message || 'Error', false);
              },
        modalTorneoCrud({
            readOnly: ro,
            hideSave: ro,
            afterRender(inner) {
                wireTorneoMediaPreviews(inner, r);
                wireTorneoGrupoPick(inner);
                wireTorneoCampeonatoMode(inner);
            },
        })
    );
    syncTorneoSelects(r);
}

async function loadAsociacionesPanel() {
    const el = document.getElementById('panel-aso');
    if (!el) return;
    const p = listState.aso;
    const per = REPORTE_FILAS_POR_PAGINA;
    const estQ = listState._asoEstatus === null || listState._asoEstatus === undefined ? '' : `&estatus=${listState._asoEstatus}`;
    const { res, data } = await fetchJson(`api/crud_asociaciones.php?page=${p}&perPage=${per}${estQ}`);
    if (!res.ok || !data.ok) {
        el.innerHTML = `<p class="error">${esc(data.message || 'Error')}</p>`;
        return;
    }
    const cap = ctx.capabilities?.asociaciones || {};
    const items = data.items || [];
    const total = typeof data.total === 'number' ? data.total : items.length;
    const pager = reporteHtmlPaginador('aso', p, total, per);
    const optAll = listState._asoEstatus === null || listState._asoEstatus === undefined ? ' selected' : '';
    el.innerHTML = wrapReporte(
        `<h2 class="admin-section-title">Asociaciones</h2>
        <div class="admin-toolbar">
            ${cap.create ? '<button type="button" class="btn-primary" id="btn-new-aso">Nueva asociación</button>' : ''}
            <label class="admin-toolbar-filter"><span>Estatus</span><select id="aso-filter-estatus" class="admin-search">
                <option value=""${optAll}>Todos</option>
                <option value="0"${listState._asoEstatus === 0 ? ' selected' : ''}>Activo (0)</option>
                <option value="9"${listState._asoEstatus === 9 ? ' selected' : ''}>Inactivo (9)</option>
            </select></label>
        </div>
        ${pager}
        <div class="admin-asoc-pane"><table class="fvd-table admin-crud-table admin-crud-table--compact text-sm"><thead><tr><th class="reporte-th-img">Logo</th><th>Nombre</th><th>Delegado</th><th class="admin-col-narrow">Est.</th><th>Acciones</th></tr></thead><tbody>` +
        items
            .map((r) => {
                const acc = reporteHtmlAcciones({
                    prefijo: 'aso',
                    id: r.id,
                    campoEstado: 'estatus',
                    valorEstado: r.estatus,
                    puedeEscribir: !!cap.write,
                });
                return `<tr><td class="reporte-td-img">${reporteThumbImg(r.logo || '', r.nombre || '')}</td><td class="admin-cell-truncate" title="${esc(r.nombre)}">${esc(r.nombre)}</td><td class="admin-cell-truncate" title="${esc(r.delegado)}">${esc(r.delegado)}</td><td class="admin-col-narrow">${esc(r.estatus)}</td><td class="reporte-td-acciones">${acc}</td></tr>`;
            })
            .join('') +
        '</tbody></table></div>'
    );
    document.getElementById('btn-new-aso')?.addEventListener('click', () => openAsociacionEditor(null));
    document.getElementById('aso-filter-estatus')?.addEventListener('change', (ev) => {
        const v = /** @type {HTMLSelectElement} */ (ev.target).value;
        listState._asoEstatus = v === '' ? null : parseInt(v, 10);
        listState.aso = 1;
        loadAsociacionesPanel();
    });
    reporteLigarPaginador('aso', p, total, (np) => {
        listState.aso = np;
        loadAsociacionesPanel();
    }, per);
    reporteDelegarAcciones(el, 'aso', {
        onVer: (id) => {
            const row = items.find((x) => String(x.id) === id);
            if (row) openAsociacionEditor(row, { readOnly: true });
        },
        onEditar: (id) => {
            const row = items.find((x) => String(x.id) === id);
            if (row && cap.write) openAsociacionEditor(row);
        },
        onToggle: async (id, _campo, valorActual) => {
            if (!cap.write) return;
            const next = reporteToggleEstatusActivoInactivoFvd(valorActual);
            const d = await fetchJson('api/crud_asociaciones.php', {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: parseInt(id, 10), estatus: next }),
            });
            if (d.res.ok && d.data.ok) {
                showGlobalMsg('Estatus actualizado.', true);
                loadAsociacionesPanel();
            } else showGlobalMsg(d.data.message || 'Error', false);
        },
    });
}

function asociacionFieldsHtml(r, delegado) {
    const o = r || {};
    /** @type {Array<[string, string, string]>} */
    const fields = [];
    if (!delegado) {
        fields.push(['nombre', 'Nombre *', 'text']);
    }
    fields.push(
        ['direccion', 'Dirección', 'text'],
        ['telefono', 'Teléfono', 'text'],
        ['email', 'Email', 'email'],
        ['numreg', 'Nº registro', 'text'],
        ['providencia', 'Providencia', 'text'],
        ['delegado', 'Delegado contacto', 'text']
    );
    if (!delegado) {
        fields.push(
            ['indica', 'Indica', 'number'],
            ['estatus', 'Estatus', 'number'],
            ['fechreg', 'Fecha registro (AAAA-MM-DD)', 'date'],
            ['fechprovi', 'Fecha provi (AAAA-MM-DD)', 'date'],
            ['ultelECC', 'Últ. ECC (AAAA-MM-DD)', 'date']
        );
    }
    const gridFields = fields
        .map(([k, lab, typ]) => {
            const ph = esc(lab);
            const v = esc(o[k] ?? '');
            const full = k === 'nombre' ? ' col-span-full' : '';
            return `<label class="afiliacion-field${full}"><span class="text-sm">${esc(lab)}</span><input type="${esc(typ)}" name="${esc(k)}" value="${v}" placeholder="${ph}" title="${ph}"></label>`;
        })
        .join('');

    const showLogoFile = r !== null || (!delegado && r === null);
    let logoCol = '';
    if (showLogoFile) {
        const logoVal = r !== null && o.logo ? String(o.logo).trim() : '';
        logoCol = `
        <div class="admin-logo-block admin-logo-block--org-right">
            <span class="admin-logo-block-title">Logo</span>
            <input type="hidden" name="logo" value="${esc(logoVal)}">
            <input type="file" id="aso-logo-file" class="admin-org-logo-file" accept="image/jpeg,image/png,image/webp,image/x-icon,.ico,.jpg,.jpeg,.png,.webp" aria-label="Archivo de imagen del logo">
            <div class="admin-org-logo-frame">
                <img id="aso-logo-preview" class="admin-org-logo-img" alt="Vista previa del logo">
            </div>
        </div>`;
    }

    if (!showLogoFile) {
        return `<div class="admin-org-form admin-org-form--aso-solo"><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 gap-y-3">${gridFields}</div></div>`;
    }
    return `<div class="admin-org-form admin-org-form--org-editor admin-org-form--split"><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 gap-y-3 admin-org-form__fields">${gridFields}</div>${logoCol}</div>`;
}

function openAsociacionEditor(row, opts = {}) {
    const delegado = ctx.rol === 'delegado';
    const isNew = row === null;
    const ro = !isNew && !!(opts && opts.readOnly);
    openModal(
        isNew ? 'Nueva asociación' : ro ? 'Ver asociación' : 'Editar asociación',
        asociacionFieldsHtml(row, delegado),
        ro
            ? null
            : async () => {
                  const inner = document.getElementById('admin-modal-inner');
                  if (!inner) return;
                  const body = {};
                  inner.querySelectorAll('input').forEach((inp) => {
                      const n = inp.getAttribute('name');
                      if (n) body[n] = inp.value;
                  });
                  const fileInp = inner.querySelector('#aso-logo-file');
                  if (!isNew && fileInp && fileInp.files && fileInp.files[0]) {
                      const fd = new FormData();
                      fd.append('entidad', 'asociacion');
                      fd.append('id', String(row.id));
                      fd.append('logo', fileInp.files[0]);
                      const { res: ures, data: udata } = await uploadPanelAsset(fd);
                      if (!ures.ok || !udata.ok) {
                          showGlobalMsg(udata.message || 'No se pudo subir el logo.', false);
                          return;
                      }
                      body.logo = udata.path;
                  }
                  if (isNew) {
                      const { res, data } = await fetchJson('api/crud_asociaciones.php', {
                          method: 'POST',
                          headers: { 'Content-Type': 'application/json' },
                          body: JSON.stringify(body),
                      });
                      if (!res.ok || !data.ok) {
                          showGlobalMsg(data.message || 'Error', false);
                          return;
                      }
                      const newId = data.id;
                      if (fileInp && fileInp.files && fileInp.files[0] && newId) {
                          const fd = new FormData();
                          fd.append('entidad', 'asociacion');
                          fd.append('id', String(newId));
                          fd.append('logo', fileInp.files[0]);
                          const { res: ures, data: udata } = await uploadPanelAsset(fd);
                          if (!ures.ok || !udata.ok) {
                              showGlobalMsg(udata.message || 'No se pudo subir el logo.', false);
                              return;
                          }
                          const patch = await fetchJson('api/crud_asociaciones.php', {
                              method: 'PATCH',
                              headers: { 'Content-Type': 'application/json' },
                              body: JSON.stringify({ id: newId, logo: udata.path }),
                          });
                          if (!patch.res.ok || !patch.data.ok) {
                              showGlobalMsg(patch.data.message || 'Logo subido pero no se guardó la ruta.', false);
                              return;
                          }
                      }
                      showGlobalMsg(data.message || 'Guardado.', true);
                      closeModal();
                      loadAsociacionesPanel();
                      return;
                  }
                  const { res, data } = await fetchJson('api/crud_asociaciones.php', {
                      method: 'PATCH',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ ...body, id: row.id }),
                  });
                  if (res.ok && data.ok) {
                      showGlobalMsg(data.message || 'Guardado.', true);
                      closeModal();
                      loadAsociacionesPanel();
                  } else showGlobalMsg(data.message || 'Error', false);
              },
        modalForm60({
            readOnly: ro,
            hideSave: ro,
            afterRender(inner) {
                if (inner.querySelector('#aso-logo-file')) {
                    wireEntidadLogoPreview(inner, 'aso-logo-file', 'aso-logo-preview', 'input[name="logo"]');
                }
            },
        })
    );
}

async function loadAtletasReportPanel() {
    const el = document.getElementById('panel-atl');
    if (!el) return;
    const per = REPORTE_FILAS_POR_PAGINA;
    const p = listState.atl;
    if (ctx.rol === 'delegado') {
        const my = Number(ctx.asociacion_id) || 0;
        if (my > 0) {
            listState._atlAsocId = my;
        }
    }
    const qsParts = [];
    if (ctx.rol === 'delegado') {
        const my = Number(ctx.asociacion_id) || 0;
        if (my > 0) {
            qsParts.push(`asociacion_id=${encodeURIComponent(String(my))}`);
        }
    } else if (Number(listState._atlAsocId) > 0) {
        qsParts.push(`asociacion_id=${encodeURIComponent(String(listState._atlAsocId))}`);
    }
    if (listState._atlUsrStatus !== '' && listState._atlUsrStatus !== null && listState._atlUsrStatus !== undefined) {
        qsParts.push(`status=${encodeURIComponent(String(listState._atlUsrStatus))}`);
    }
    const qs = qsParts.length ? `?${qsParts.join('&')}` : '';
    const r = await fetch(`api/get_atletas.php${qs}`, { credentials: 'same-origin' });
    const raw = await r.text();
    let datos;
    try {
        datos = JSON.parse(raw);
    } catch {
        datos = {};
    }
    if (!r.ok) {
        el.innerHTML = `<p class="error">${esc(datos.message || 'Error al cargar atletas')}</p>`;
        return;
    }
    if (!Array.isArray(datos)) {
        el.innerHTML = datos.message ? `<p class="ag-muted">${esc(datos.message)}</p>` : `<p class="error">Respuesta inválida.</p>`;
        return;
    }
    const total = datos.length;
    const slice = reporteSliceCliente(datos, p);
    const pager = reporteHtmlPaginador('atlp', p, total, per);
    let optsAsoc = '<option value="0">Todas</option>';
    if (ctx.rol === 'admingral') {
        const ar = await fetchJson('api/crud_asociaciones.php?page=1&perPage=500');
        if (ar.res.ok && ar.data.ok && Array.isArray(ar.data.items)) {
            optsAsoc =
                '<option value="0">Todas</option>' +
                ar.data.items
                    .map(
                        (a) =>
                            `<option value="${esc(String(a.id))}"${Number(listState._atlAsocId) === Number(a.id) ? ' selected' : ''}>${esc(a.nombre)}</option>`
                    )
                    .join('');
        }
    } else {
        optsAsoc = `<option value="${esc(String(ctx.asociacion_id || 0))}" selected>Mi asociación</option>`;
    }
    const stSel = String(listState._atlUsrStatus);
    const selTodos = stSel === '' ? ' selected' : '';
    const sel9 = stSel === '9' ? ' selected' : '';
    const sel0 = stSel === '0' ? ' selected' : '';
    const capAf = !!(ctx.capabilities?.usuarios?.create);
    const encCed = (c) => encodeURIComponent(String(c || '').trim());
    const filas = slice
        .map((a) => {
            const c = encCed(a.cedula);
            const verHref = `afiliar_atleta.html?modo=ver&cedula=${c}`;
            const edHref = `afiliar_atleta.html?modo=editar&cedula=${c}`;
            const acc = `<div class="reporte-acciones">
            <a class="btn-secondary btn-sm reporte-btn-ic" href="${verHref}" title="Ver" aria-label="Ver"><span class="reporte-btn-ic-sym" aria-hidden="true">👁</span></a>
            <a class="btn-secondary btn-sm reporte-btn-ic" href="${edHref}" title="Editar" aria-label="Editar"><span class="reporte-btn-ic-sym" aria-hidden="true">✎</span></a>
            <button type="button" class="btn-secondary btn-sm reporte-btn-ic" disabled title="Estatus en usuarios portal" aria-label="Estatus"><span class="reporte-btn-ic-sym" aria-hidden="true">⏻</span></button>
        </div>`;
            const sx = a.sexo === 1 ? 'M' : a.sexo === 2 ? 'F' : '—';
            const asoc = a.asociacion_nombre && String(a.asociacion_nombre).trim() !== '' ? String(a.asociacion_nombre) : '—';
            const st = a.usuario_status !== null && a.usuario_status !== undefined ? String(a.usuario_status) : '—';
            return `<tr>
                <td>${esc(String(a.numfvd))}</td>
                <td>${esc(a.cedula)}</td>
                <td>${esc(a.nombre)}</td>
                <td>${esc(asoc)}</td>
                <td>${esc(sx)}</td>
                <td>${esc(st)}</td>
                <td class="reporte-td-acciones">${acc}</td>
            </tr>`;
        })
        .join('');
    const btnNuevo = capAf
        ? `<a href="afiliar_atleta.html?modo=nuevo" class="btn-primary btn-sm">Nueva afiliación</a>`
        : '';
    el.innerHTML = wrapReporte(
        `<div class="admin-toolbar">
            ${btnNuevo}
            <label class="admin-toolbar-filter"><span>Asociación</span><select id="atl-filter-asoc" class="admin-search"${ctx.rol === 'delegado' ? ' disabled' : ''}>${optsAsoc}</select></label>
            <label class="admin-toolbar-filter"><span>Estatus portal</span><select id="atl-filter-status" class="admin-search">
                <option value=""${selTodos}>Todos</option>
                <option value="0"${sel0}>Activo (0)</option>
                <option value="9"${sel9}>Inactivo (9)</option>
            </select></label>
        </div>
        ${pager}
        <table class="fvd-table admin-crud-table"><thead><tr><th>FVD #</th><th>Cédula</th><th>Nombre</th><th>Asociación</th><th>Sexo</th><th>Estatus</th><th>Acciones</th></tr></thead><tbody>${filas}</tbody></table>`
    );
    const aplicarFiltrosAtl = () => {
        if (ctx.rol === 'admingral') {
            listState._atlAsocId = parseInt(String(document.getElementById('atl-filter-asoc')?.value || '0'), 10) || 0;
        }
        const sv = document.getElementById('atl-filter-status')?.value;
        listState._atlUsrStatus = sv === '' ? '' : String(sv);
        listState.atl = 1;
        void loadAtletasReportPanel();
    };
    document.getElementById('atl-filter-asoc')?.addEventListener('change', aplicarFiltrosAtl);
    document.getElementById('atl-filter-status')?.addEventListener('change', aplicarFiltrosAtl);
    reporteLigarPaginador('atlp', p, total, (np) => {
        listState.atl = np;
        loadAtletasReportPanel();
    }, per);
}

async function loadUsuariosPanel() {
    listState._usrQ = '';
    listState._usrStatus = null;
    listState._usrHideStatusFilter = false;
    await loadUsuariosPanelCore(false);
}

async function loadUsuariosSolicitudesPanel() {
    listState._usrQ = '';
    listState._usrStatus = 9;
    listState.usr = 1;
    listState._usrHideStatusFilter = true;
    await loadUsuariosPanelCore(false);
}

async function loadUsuariosPanelCore(useQ) {
    const el = document.getElementById('panel-usr');
    if (!el) return;
    const p = listState.usr;
    const q = useQ ? listState._usrQ || '' : '';
    const qs = q ? `&q=${encodeURIComponent(q)}` : '';
    const st = listState._usrStatus !== null && listState._usrStatus !== undefined ? `&status=${listState._usrStatus}` : '';
    const per = REPORTE_FILAS_POR_PAGINA;
    const { res, data } = await fetchJson(`api/crud_usuarios.php?page=${p}&perPage=${per}${qs}${st}`);
    if (!res.ok || !data.ok) {
        el.innerHTML = `<p class="error">${esc(data.message || 'Error')}</p>`;
        return;
    }
    const cap = ctx.capabilities?.usuarios || {};
    const items = data.items || [];
    const total = typeof data.total === 'number' ? data.total : items.length;
    const title =
        Number(listState._usrStatus) === 9 ? 'Solicitudes de acceso (inactivo 9)' : 'Atletas / usuarios portal';
    el.innerHTML = wrapReporte(buildUsuariosTableHtml(items, cap, p, q, title, total, listState._usrHideStatusFilter));
    wireUsuariosPanelEvents(items, cap, useQ, total);
}

async function loadUsuariosPanelWithQ(q) {
    listState._usrQ = q;
    await loadUsuariosPanelCore(true);
}

function buildUsuariosTableHtml(items, cap, p, q, sectionTitle = 'Usuarios portal', total, hideStatusFilter = false) {
    const per = REPORTE_FILAS_POR_PAGINA;
    const tot = typeof total === 'number' ? total : items.length;
    const pager = reporteHtmlPaginador('usr', p, tot, per);
    const st = listState._usrStatus;
    const selTodos = st === null || st === undefined ? ' selected' : '';
    const sel9 = Number(st) === 9 ? ' selected' : '';
    const sel0 = Number(st) === 0 ? ' selected' : '';
    const statusFilter =
        hideStatusFilter === true
            ? ''
            : `<label class="admin-toolbar-filter"><span>Estatus</span><select id="usr-filter-status" class="admin-search">
                <option value=""${selTodos}>Todos</option>
                <option value="0"${sel0}>Activo (0)</option>
                <option value="9"${sel9}>Inactivo (9)</option>
            </select></label>`;
    return (
        `<h2 class="admin-section-title">${esc(sectionTitle)}</h2>
        <div class="admin-toolbar">
            ${cap.create ? '<button type="button" class="btn-primary" id="btn-new-usr">Nuevo usuario</button>' : ''}
            ${statusFilter}
            <input type="search" id="usr-q" placeholder="Buscar…" class="admin-search" value="${esc(q)}">
            <button type="button" class="btn-secondary" id="btn-usr-search">Buscar</button>
        </div>
        ${pager}
        <table class="fvd-table admin-crud-table"><thead><tr><th class="reporte-th-img">Foto</th><th>Nombre</th><th>Cédula</th><th>Nº FVD</th><th>Rol</th><th>Asociación</th><th>Acciones</th></tr></thead><tbody>` +
        items
            .map((r) => {
                const acc = reporteHtmlAcciones({
                    prefijo: 'usr',
                    id: r.id,
                    campoEstado: 'status',
                    valorEstado: r.status,
                    puedeEscribir: !!cap.write,
                });
                const nomAsoc = r.asociacion_nombre != null && String(r.asociacion_nombre).trim() !== '' ? String(r.asociacion_nombre) : '—';
                return `<tr><td class="reporte-td-img">${reporteThumbImg(r.urlimgfoto || '', r.nombre || '')}</td><td>${esc(r.nombre)}</td><td>${esc(r.cedula)}</td><td>${esc(r.numfvd)}</td><td>${esc(r.role)}</td><td>${esc(nomAsoc)}</td><td class="reporte-td-acciones">${acc}</td></tr>`;
            })
            .join('') +
        '</tbody></table>'
    );
}

function wireUsuariosPanelEvents(items, cap, useQ, total) {
    const el = document.getElementById('panel-usr');
    if (!el) return;
    const per = REPORTE_FILAS_POR_PAGINA;
    const tot = typeof total === 'number' ? total : items.length;
    document.getElementById('btn-new-usr')?.addEventListener('click', () => openUsuarioEditor(null));
    document.getElementById('btn-usr-search')?.addEventListener('click', () => {
        listState.usr = 1;
        loadUsuariosPanelWithQ(document.getElementById('usr-q')?.value || '');
    });
    if (!listState._usrHideStatusFilter) {
        document.getElementById('usr-filter-status')?.addEventListener('change', (ev) => {
            const v = /** @type {HTMLSelectElement} */ (ev.target).value;
            listState._usrStatus = v === '' ? null : parseInt(v, 10);
            listState.usr = 1;
            loadUsuariosPanelCore(useQ);
        });
    }
    reporteLigarPaginador('usr', listState.usr, tot, (np) => {
        listState.usr = np;
        loadUsuariosPanelCore(useQ);
    }, per);
    reporteDelegarAcciones(el, 'usr', {
        onVer: (id) => {
            const row = items.find((x) => String(x.id) === id);
            if (row) openUsuarioEditor(row, { readOnly: true });
        },
        onEditar: (id) => {
            const row = items.find((x) => String(x.id) === id);
            if (row && cap.write) openUsuarioEditor(row);
        },
        onToggle: async (id, _campo, valorActual) => {
            if (!cap.write) return;
            const next = usuarioSiguienteStatusToggle(valorActual);
            const d = await fetchJson('api/crud_usuarios.php', {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: parseInt(id, 10), status: next }),
            });
            if (d.res.ok && d.data.ok) {
                showGlobalMsg('Estatus actualizado.', true);
                loadUsuariosPanelCore(useQ);
            } else showGlobalMsg(d.data.message || 'Error', false);
        },
    });
}

function usuarioFieldsHtml(r, isNew, uCap) {
    const o = r || {};
    const delegado = ctx && ctx.rol === 'delegado';
    const canNum = !!(uCap && uCap.assign_numfvd);
    const canApprove = !!(uCap && uCap.approve_status);
    const f = (k, lab, type = 'text') =>
        `<label class="admin-field admin-field--grid"><span>${esc(lab)}</span><input type="${type}" name="${k}" value="${esc(o[k] ?? '')}"></label>`;
    let h = '<div class="admin-org-form">';
    h += f('cedula', 'Cédula *') + f('nombre', 'Nombre *') + f('email', 'Email *') + f('username', 'Usuario *');
    if (isNew) h += f('password', 'Contraseña *', 'password');
    else h += f('password', 'Nueva contraseña (opcional)', 'password');
    h += f('celular', 'Celular') + f('fechnac', 'Fecha nac.', 'date');
    if (!delegado) {
        h +=
            `<label class="admin-field admin-field--grid"><span>Rol</span><select name="role"><option value="usuario">usuario</option><option value="delegado">delegado</option><option value="admingral">admingral</option></select></label>` +
            f('asociacion_id', 'Asociación id', 'number') +
            f('sexo', 'Sexo', 'number');
        if (canApprove) {
            h += f('status', 'Estatus (9 pendiente, 1 aprobado)', 'number');
        }
        if (canNum) {
            h += f('numfvd', 'Nº FVD', 'number');
        }
        h += f('posirnk', 'Pos ranking', 'number');
    } else {
        h += f('sexo', 'Sexo', 'number') + f('posirnk', 'Pos ranking', 'number');
        if (!isNew) {
            h += `<p class="admin-readonly-hint">Nº FVD (solo asigna administración general): <strong>${esc(
                o.numfvd ?? '—'
            )}</strong></p>`;
        }
    }
    const fotoVal = (o.urlimgfoto && String(o.urlimgfoto).trim()) || '';
    const cedVal = (o.urlimgcedula && String(o.urlimgcedula).trim()) || '';
    h += `<div class="admin-logo-block">
            <span class="admin-logo-block-title">Foto del usuario</span>
            <input type="hidden" name="urlimgfoto" value="${esc(fotoVal)}">
            <label class="admin-field"><span>Archivo de imagen</span>
                <input type="file" id="usr-foto-file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
            </label>
            <div class="admin-logo-preview-wrap">
                <img id="usr-foto-preview" alt="Vista previa foto" class="admin-logo-preview-img" width="160" height="160">
            </div>
        </div>
        <div class="admin-logo-block">
            <span class="admin-logo-block-title">Imagen de cédula</span>
            <input type="hidden" name="urlimgcedula" value="${esc(cedVal)}">
            <label class="admin-field"><span>Archivo de imagen</span>
                <input type="file" id="usr-ced-file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
            </label>
            <div class="admin-logo-preview-wrap">
                <img id="usr-ced-preview" alt="Vista previa cédula" class="admin-logo-preview-img" width="200" height="120">
            </div>
        </div>`;
    h += '</div>';
    return h;
}

function openUsuarioEditor(row, opts = {}) {
    const uCap = ctx.capabilities?.usuarios || {};
    const isNew = row === null;
    const ro = !isNew && !!(opts && opts.readOnly);
    openModal(
        isNew ? 'Nuevo usuario' : ro ? 'Ver usuario' : 'Editar usuario',
        usuarioFieldsHtml(row, isNew, uCap),
        ro
            ? null
            : async () => {
            const inner = document.getElementById('admin-modal-inner');
            if (!inner) return;
            const body = {};
            inner.querySelectorAll('input,select').forEach((inp) => {
                const n = inp.getAttribute('name');
                if (!n || inp.type === 'file') return;
                if (n === 'password' && inp.value === '' && !isNew) return;
                body[n] = inp.value;
            });
            const mergeUploads = async (userId) => {
                const extra = {};
                const fotoInp = inner.querySelector('#usr-foto-file');
                if (fotoInp && fotoInp.files && fotoInp.files[0]) {
                    const fd = new FormData();
                    fd.append('entidad', 'usuario');
                    fd.append('id', String(userId));
                    fd.append('campo', 'foto');
                    fd.append('archivo', fotoInp.files[0]);
                    const { res, data } = await uploadPanelAsset(fd);
                    if (!res.ok || !data.ok) {
                        throw new Error(data.message || 'Error al subir la foto.');
                    }
                    extra.urlimgfoto = data.path;
                }
                const cedInp = inner.querySelector('#usr-ced-file');
                if (cedInp && cedInp.files && cedInp.files[0]) {
                    const fd = new FormData();
                    fd.append('entidad', 'usuario');
                    fd.append('id', String(userId));
                    fd.append('campo', 'cedula');
                    fd.append('archivo', cedInp.files[0]);
                    const { res, data } = await uploadPanelAsset(fd);
                    if (!res.ok || !data.ok) {
                        throw new Error(data.message || 'Error al subir la imagen de cédula.');
                    }
                    extra.urlimgcedula = data.path;
                }
                return extra;
            };
            try {
                if (isNew) {
                    const { res, data } = await fetchJson('api/crud_usuarios.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(body),
                    });
                    if (!res.ok || !data.ok) {
                        showGlobalMsg(data.message || 'Error', false);
                        return;
                    }
                    const uid = data.id;
                    const extra = await mergeUploads(uid);
                    if (Object.keys(extra).length > 0) {
                        const p2 = await fetchJson('api/crud_usuarios.php', {
                            method: 'PATCH',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: uid, ...extra }),
                        });
                        if (!p2.res.ok || !p2.data.ok) {
                            showGlobalMsg(p2.data.message || 'Usuario creado pero no se guardaron las imágenes.', false);
                            return;
                        }
                    }
                } else {
                    const extra = await mergeUploads(row.id);
                    const { res, data } = await fetchJson('api/crud_usuarios.php', {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ ...body, ...extra, id: row.id }),
                    });
                    if (!res.ok || !data.ok) {
                        showGlobalMsg(data.message || 'Error', false);
                        return;
                    }
                }
                showGlobalMsg('Guardado.', true);
                closeModal();
                if (listState._usrQ) loadUsuariosPanelWithQ(listState._usrQ);
                else loadUsuariosPanelCore(false);
            } catch (e) {
                showGlobalMsg(e.message || 'Error de subida', false);
            }
            },
        modalForm60({
            readOnly: ro,
            hideSave: ro,
            afterRender(inner) {
                wireUsuarioImgPreviews(inner, row);
            },
        })
    );
    if (!isNew && row) {
        const inner = document.getElementById('admin-modal-inner');
        const sel = inner?.querySelector('select[name="role"]');
        if (sel) sel.value = String(row.role || 'usuario');
    }
}
