/**
 * Resumen finanzas FVD — listado por torneo; detalle en página aparte.
 */
import { initFvdReportPage } from './fvd_report_page.js';
import { initDelegadoTorneosBar, persistJornadaDesdeAuth } from './delegado_torneos_bar.js';
import {
    hrefFinanzasAsociacion,
    hrefGastosTorneo,
    hrefResultadoFinancieroTorneo,
    puedeFinanzasOperativasFvd,
} from './finanzas_reporte_nav.js';
import {
    htmlTablaResumenFinanzas,
    htmlFilaTotalesResumen,
    rfvdEsc,
    rfvdFmtEur,
} from './resumen_finanzas_tabla.js';

const API = 'api/finanza_resumen_periodo.php';

/** @type {Record<string, unknown>|null} */
let authCtx = null;

let selectorPeriodoListo = false;

function esVistaAsociacion() {
    return !!(authCtx && (authCtx.vista_asociacion || authCtx.rol === 'delegado'));
}

function hrefDetalleTorneo(torneoId) {
    return `resumen_finanzas_fvd_detalle.html?torneo_id=${encodeURIComponent(String(torneoId))}`;
}

function qsApiListado() {
    const sel = document.getElementById('rfvd-periodo');
    const v = sel ? String(sel.value) : 'completo';
    const u = new URL(window.location.href);
    u.searchParams.delete('grupo_evento_id');
    let api = API;
    if (v.startsWith('g:')) {
        const gid = parseInt(v.slice(2), 10) || 0;
        if (gid > 0) {
            u.searchParams.set('grupo_evento_id', String(gid));
            api += `?grupo_evento_id=${encodeURIComponent(String(gid))}`;
        }
    } else {
        api = API;
    }
    history.replaceState(null, '', u.toString());
    return api;
}

function poblarSelectorPeriodo(data) {
    const sel = document.getElementById('rfvd-periodo');
    if (!sel || esVistaAsociacion()) {
        if (sel) sel.closest('.ag-fin-periodo-field')?.classList.add('is-hidden');
        return;
    }
    const u = new URL(window.location.href);
    const curGrupo = parseInt(u.searchParams.get('grupo_evento_id') || '0', 10) || 0;
    const opts = ['<option value="completo">Periodo completo (todos los torneos)</option>'];
    const estruct = data.torneos_estructurado || {};
    (estruct.campeonatos || []).forEach((c) => {
        const gid = parseInt(String(c.grupo_evento_id ?? 0), 10);
        if (gid < 1) return;
        opts.push(
            `<option value="g:${gid}"${curGrupo === gid ? ' selected' : ''}>${rfvdEsc(String(c.etiqueta || `Campeonato #${gid}`))}</option>`
        );
    });
    sel.innerHTML = opts.join('');
    if (curGrupo < 1) sel.value = 'completo';
}

function renderListado(data) {
    const el = document.getElementById('rfvd-global');
    if (!el) return;
    const listado = data.listado || {};
    const filas = listado.filas_torneos || [];
    const tot = listado.totales || {};
    const periodo = listado.periodo || {};

    const lead = document.getElementById('rfvd-lead');
    if (lead) {
        let txt = `${periodo.etiqueta || 'Periodo'} — ${filas.length} torneo(s). Total nómina: ${rfvdFmtEur(tot.total_nomina_eur)}.`;
        if (data.estado_asociacion) {
            const e = data.estado_asociacion;
            txt += ` Su cuenta: saldo ${rfvdFmtEur(e.saldo_eur)}.`;
        } else if (data.integral) {
            txt += ` Integral FVD: saldo ${rfvdFmtEur(data.integral.saldo_eur)}.`;
        }
        lead.textContent = txt;
    }

    if (filas.length < 1) {
        el.innerHTML = '<p class="ag-muted">Sin movimiento en el ámbito seleccionado.</p>';
        return;
    }

    const aid = authCtx?.asociacion_id != null ? parseInt(String(authCtx.asociacion_id), 10) : 0;
    const tablaOpts = {
        tipo: 'torneo',
        linkDetalle: (row) => {
            if (esVistaAsociacion() && aid > 0) {
                return hrefFinanzasAsociacion(aid, { torneoId: row.torneo_id });
            }
            return hrefDetalleTorneo(row.torneo_id);
        },
    };
    if (puedeFinanzasOperativasFvd(authCtx)) {
        tablaOpts.linkGastos = (row) => hrefGastosTorneo(row.torneo_id);
        tablaOpts.linkResultado = (row) => hrefResultadoFinancieroTorneo(row.torneo_id);
    }
    const tabla = htmlTablaResumenFinanzas(filas, tablaOpts);
    el.innerHTML = `
        <p class="ag-fin-periodo-periodo-lbl"><strong>${rfvdEsc(periodo.etiqueta || '')}</strong></p>
        ${tabla.replace('</tbody>', `${htmlFilaTotalesResumen(tot)}</tbody>`)}`;
}

async function cargarListado() {
    const el = document.getElementById('rfvd-global');
    if (el) el.innerHTML = '<p class="ag-muted">Cargando…</p>';
    try {
        const res = await fetch(qsApiListado(), { credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
            const msg = data.message || 'No se pudo cargar el resumen.';
            if (el) el.innerHTML = `<p class="error">${rfvdEsc(msg)}</p>`;
            return;
        }
        authCtx = { ...authCtx, ...data };
        if (!selectorPeriodoListo) {
            poblarSelectorPeriodo(data);
            selectorPeriodoListo = true;
        }
        renderListado(data);
    } catch (e) {
        console.error(e);
        if (el) el.innerHTML = '<p class="error">Error de conexión.</p>';
    }
}

function puedeAcceder(ctx) {
    return puedeFinanzasOperativasFvd(ctx);
}

document.addEventListener('DOMContentLoaded', async () => {
    const gate = document.getElementById('rfvd-gate');
    const app = document.getElementById('rfvd-app');
    try {
        const res = await fetch('api/auth_context.php', { credentials: 'same-origin' });
        const ctx = await res.json().catch(() => ({}));
        if (!puedeAcceder(ctx)) {
            const aid =
                ctx.asociacion_id != null ? parseInt(String(ctx.asociacion_id), 10) : 0;
            const finHref =
                aid > 0
                    ? `finanzas_asociacion.html?id=${encodeURIComponent(String(aid))}`
                    : 'panel.html';
            if (gate) {
                gate.style.display = 'block';
                gate.innerHTML =
                    ctx.logged && ctx.rol === 'delegado'
                        ? '<p class="error">El resumen consolidado FVD (ingresos, gastos y margen) es solo para administración general.</p>' +
                          `<p><a href="${finHref}" class="btn-secondary">Estado de cuentas</a> · <a href="panel.html" class="btn-secondary">Panel</a></p>`
                        : '<p class="error">Debe iniciar sesión como administración general.</p>' +
                          '<p><a href="index.php" class="btn-secondary">Inicio</a> · <a href="panel.html" class="btn-secondary">Panel</a></p>';
            }
            return;
        }
        authCtx = ctx;
        initFvdReportPage({ nav: '.main-nav-actions' });
        persistJornadaDesdeAuth(ctx);
        void initDelegadoTorneosBar({
            showWhen: (a) => a.logged && (a.rol === 'admingral' || a.rol === 'delegado'),
        });
        if (app) app.style.display = '';
        const h1 = document.querySelector('.ag-fin-periodo-head h1');
        if (h1 && esVistaAsociacion()) {
            const nom = ctx.asociacion_activa?.nombre || 'Mi asociación';
            h1.textContent = `Resumen por torneo — ${nom}`;
        }
        document.getElementById('rfvd-btn-cargar')?.addEventListener('click', () => void cargarListado());
        document.getElementById('rfvd-periodo')?.addEventListener('change', () => void cargarListado());
        document.getElementById('rfvd-btn-print')?.addEventListener('click', () => window.print());
        await cargarListado();
    } catch (e) {
        console.error('resumen_finanzas_fvd init', e);
        if (gate) {
            gate.style.display = 'block';
            gate.innerHTML =
                '<p class="error">Error al iniciar.</p><p><a href="panel.html" class="btn-secondary">Panel</a></p>';
        }
    }
});
