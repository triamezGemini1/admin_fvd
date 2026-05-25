/**
 * Reporte participación en columnas (totales globales por asociación + detalle por torneo).
 */
import { reporteEsc, reporteLigarPaginador, REPORTE_FILAS_POR_PAGINA } from './reporte_tabla.js';
import { mountPortalPerfilHeader } from './portal_perfil_header.js';
import {
    hrefInformeConsolidado,
    hrefReporteAsociacionesTorneo,
    hrefReporteParticipacion,
} from './finanzas_reporte_nav.js';
import {
    htmlReporteParticipacionColumnas,
    resetPaginasDetalleFinanza,
    wireReporteParticipacionSeleccionExclusiva,
} from './finanzas_detalle_ui.js';

const REP_PART_PREFIX = 'rep-part';
const FETCH_TIMEOUT_MS = 90000;

/** @type {Record<string, unknown>|null} */
let authCtx = null;
/** @type {Record<string, unknown>|null} */
let cachePayload = null;
let repPartPag = 1;
/** @type {{ selectAsoc: (n: number) => void, selectedId: () => number }|null} */
let seleccionCtrl = null;

async function fetchJson(url, options = {}) {
    const ctrl = new AbortController();
    const timer = window.setTimeout(() => ctrl.abort(), FETCH_TIMEOUT_MS);
    try {
        const res = await fetch(url, { credentials: 'same-origin', ...options, signal: ctrl.signal });
        const data = await res.json().catch(() => ({}));
        return { res, data };
    } catch (e) {
        const aborted = e && e.name === 'AbortError';
        return {
            res: { ok: false, status: aborted ? 408 : 0 },
            data: {
                ok: false,
                message: aborted
                    ? 'La consulta tardó demasiado. Intente de nuevo.'
                    : 'No se pudo conectar con el servidor.',
            },
        };
    } finally {
        window.clearTimeout(timer);
    }
}

function setChrome(title, lead) {
    const h = document.getElementById('rep-part-title');
    const p = document.getElementById('rep-part-lead');
    if (h) h.textContent = title;
    if (p) {
        const t = lead != null ? String(lead) : '';
        p.textContent = t;
        p.style.display = t.trim() === '' ? 'none' : '';
    }
}

function setError(msg) {
    const p = document.getElementById('rep-part-lead');
    if (p) {
        p.style.display = 'block';
        p.textContent = msg;
        p.classList.add('error');
    }
}

function htmlBarraEnlacesInformes() {
    if (!authCtx || authCtx.rol !== 'admingral') {
        return '';
    }
    return `<div class="ag-inf-vista-switch admin-toolbar" role="navigation" aria-label="Otros reportes">
        <a class="btn-secondary btn-sm" href="${hrefReporteAsociacionesTorneo()}">Por asociación y torneo</a>
        <a class="btn-secondary btn-sm" href="${hrefInformeConsolidado()}">Consolidado (un torneo)</a>
        <a class="btn-primary btn-sm" href="${hrefReporteParticipacion()}">Participación (columnas)</a>
    </div>`;
}

function renderReporte(body, data) {
    const list = Array.isArray(data.asociaciones) ? data.asociaciones : [];
    const esDel = authCtx && authCtx.rol === 'delegado';
    const nAsoc = data.n_asociaciones != null ? parseInt(String(data.n_asociaciones), 10) : list.length;
    const totales = data.totales_columnas || null;
    cachePayload = data;

    setChrome(
        esDel ? 'Participación — mi asociación' : 'Reporte participación — columnas',
        esDel
            ? 'Totales de movimiento en todos sus torneos. Pulse la fila para el desglose por torneo.'
            : `${nAsoc} asociación(es). Tabla con totales globales; al elegir otra fila se cierra el detalle anterior.`
    );

    const integ = data.integral || null;
    const pieInt =
        integ && !esDel
            ? `<p class="ag-muted ag-inf-stat-hint">Integral FVD — Deuda: <strong>${reporteEsc(String(integ.deuda_eur ?? 0))} €</strong> · Pagado: ${reporteEsc(String(integ.pagado_eur ?? 0))} € · Saldo: ${reporteEsc(String(integ.saldo_eur ?? 0))} €</p>`
            : '';

    const tablaHtml = htmlReporteParticipacionColumnas(list, totales, repPartPag, REP_PART_PREFIX);

    body.innerHTML = `
        ${htmlBarraEnlacesInformes()}
        <div class="ag-fin-shell ag-fin-shell--informe ag-rep-part-shell ag-fvd-reporte-datos">
        ${pieInt}
        <p class="ag-muted ag-inf-stat-hint">Columnas con cantidades y montos en <strong>todos los torneos</strong>. La columna <strong>Participación total</strong> resume inscripciones. Seleccione una fila: el detalle por torneo se muestra abajo (solo una asociación abierta).</p>
        ${tablaHtml}
        </div>
        <p class="ag-inf-actions">
        <a class="btn-secondary" href="informes.html">Informes</a>
        ${!esDel ? `<a class="btn-secondary" href="${hrefReporteAsociacionesTorneo()}">Vista acordeón por asociación</a>` : ''}
        </p>`;

    const shell = body.querySelector('.ag-rep-part-shell') || body;
    resetPaginasDetalleFinanza(REP_PART_PREFIX);
    const prevSel = seleccionCtrl ? seleccionCtrl.selectedId() : 0;
    seleccionCtrl = wireReporteParticipacionSeleccionExclusiva(shell, list, REP_PART_PREFIX, () => {
        if (cachePayload) {
            const sid = seleccionCtrl ? seleccionCtrl.selectedId() : 0;
            renderReporte(body, cachePayload);
            if (sid > 0 && seleccionCtrl) {
                seleccionCtrl.selectAsoc(sid);
            }
        }
    }, { autoPrimera: esDel || list.length === 1 });

    if (prevSel > 0 && seleccionCtrl) {
        const still = list.some((it) => {
            const a = it.asociacion || {};
            return (parseInt(String(a.id), 10) || 0) === prevSel;
        });
        if (still) {
            seleccionCtrl.selectAsoc(prevSel);
        }
    }

    reporteLigarPaginador(`${REP_PART_PREFIX}-tab`, repPartPag, list.length, (np) => {
        repPartPag = np;
        if (cachePayload) {
            renderReporte(body, cachePayload);
        }
    }, REPORTE_FILAS_POR_PAGINA);
}

async function loadReporte(body) {
    if (!body) return;
    setChrome('Reporte participación — columnas', 'Cargando…');
    body.innerHTML = '<p class="ag-muted">Cargando…</p>';

    let url = 'api/finanza_reporte_asociaciones.php';
    if (authCtx && authCtx.rol === 'delegado') {
        const mine = authCtx.asociacion_id != null ? Number(authCtx.asociacion_id) : 0;
        if (mine < 1) {
            setError('Su usuario no tiene asociación asignada.');
            body.innerHTML = '<p class="error">Sin asociación asignada.</p>';
            return;
        }
        url = `api/finanza_reporte_asociaciones.php?asociacion_id=${encodeURIComponent(String(mine))}`;
    }

    const r = await fetchJson(url);
    if (!r.res.ok || !r.data.ok) {
        setError(r.data.message || 'No se pudo cargar el reporte.');
        body.innerHTML = `<p class="error">${reporteEsc(r.data.message || 'Error')}</p>`;
        return;
    }
    repPartPag = 1;
    renderReporte(body, r.data);
}

document.addEventListener('DOMContentLoaded', async () => {
    const gate = document.getElementById('rep-part-gate');
    const app = document.getElementById('rep-part-app');
    const body = document.getElementById('rep-part-body');
    try {
        setChrome('Reporte participación — columnas', 'Verificando sesión…');
        const { res, data } = await fetchJson('api/auth_context.php');
        authCtx = data;
        const puede =
            res.ok && data.logged && data.puede_panel_admin && (data.rol === 'admingral' || data.rol === 'delegado');
        if (!puede) {
            if (gate) {
                gate.style.display = 'block';
                gate.innerHTML =
                    '<p class="error">Debe iniciar sesión como administración general o delegado de asociación.</p><p><a href="index.php" class="btn-secondary">Inicio</a></p>';
            }
            setError('Sin acceso. Inicie sesión de nuevo.');
            return;
        }
        if (gate) gate.style.display = 'none';
        if (app) app.style.display = 'block';
        if (!body) return;

        await loadReporte(body);
        document.getElementById('btn-rep-part-print')?.addEventListener('click', () => window.print());
        mountPortalPerfilHeader(document.getElementById('main-nav'));
    } catch (e) {
        console.error('reporte_participacion init', e);
        setError('No se pudo iniciar la página.');
        if (body) {
            body.innerHTML = '<p class="error">Error al iniciar. <a href="panel.html">Volver al panel</a></p>';
        }
    }
});
