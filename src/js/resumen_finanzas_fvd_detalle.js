/**
 * Detalle del resumen finanzas: asociaciones de un torneo (misma matriz de eventos).
 */
import { initFvdReportPage } from './fvd_report_page.js';
import { initDelegadoTorneosBar, persistJornadaDesdeAuth } from './delegado_torneos_bar.js';
import {
    hrefFinanzasAsociacion,
    hrefGastosTorneo,
    hrefInformeAsociacion,
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

function qsTorneoId() {
    const n = parseInt(new URL(window.location.href).searchParams.get('torneo_id') || '0', 10);
    return n > 0 ? n : 0;
}

function puedeAcceder(ctx) {
    return puedeFinanzasOperativasFvd(ctx);
}

function renderDetalle(data) {
    const tid = qsTorneoId();
    const tor = data.torneo || {};
    const resumen = data.resumen || {};
    const asocs = resumen.asociaciones || [];
    const tot = {
        renglones: resumen.renglones || [],
        total_nomina_eur: resumen.total_nomina_eur,
    };

    const title = document.getElementById('rfvd-det-title');
    const lead = document.getElementById('rfvd-det-lead');
    const body = document.getElementById('rfvd-det-body');

    if (title) {
        title.textContent = `Asociaciones — ${tor.nombre || 'Torneo ' + tid}`;
    }
    if (lead) {
        lead.textContent = `Torneo #${tid}. Total nómina del torneo: ${rfvdFmtEur(resumen.total_nomina_eur)}. ${asocs.length} asociación(es) con actividad.`;
    }
    if (!body) return;

    if (asocs.length < 1) {
        body.innerHTML = '<p class="ag-muted">Sin movimiento por asociación en este torneo.</p>';
        return;
    }

    const filas = asocs.map((a) => ({
        id: a.id,
        nombre: a.nombre,
        renglones: a.renglones,
        total_nomina_eur: a.total_nomina_eur,
    }));

    const tabla = htmlTablaResumenFinanzas(filas, {
        tipo: 'asociacion',
        linkAsociacion: (row) => hrefInformeAsociacion(row.id, { torneoId: tid }),
        linkFinanzasAsociacion: (row) => hrefFinanzasAsociacion(row.id, { torneoId: tid }),
    });

    body.innerHTML = `
        <p class="ag-muted ag-fin-periodo-hint">«Ver asociación» abre el informe de la asociación en este torneo (conceptos y detalle por renglón).</p>
        ${tabla.replace('</tbody>', `${htmlFilaTotalesResumen(tot)}</tbody>`)}`;
}

async function cargar() {
    const tid = qsTorneoId();
    const gastosNav = document.getElementById('rfvd-det-gastos');
    if (gastosNav && tid > 0) gastosNav.href = hrefGastosTorneo(tid);
    const resNav = document.getElementById('rfvd-det-resultado');
    if (resNav && tid > 0) resNav.href = hrefResultadoFinancieroTorneo(tid);
    const body = document.getElementById('rfvd-det-body');
    const gate = document.getElementById('rfvd-det-gate');
    if (tid < 1) {
        if (gate) {
            gate.style.display = 'block';
            gate.textContent = 'Indique torneo_id en la URL.';
        }
        return;
    }
    if (body) body.innerHTML = '<p class="ag-muted">Cargando…</p>';
    try {
        const res = await fetch(`${API}?torneo_id=${encodeURIComponent(String(tid))}`, { credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
            const msg = data.message || 'No se pudo cargar el detalle.';
            if (body) body.innerHTML = `<p class="error">${rfvdEsc(msg)}</p>`;
            return;
        }
        renderDetalle(data);
    } catch (e) {
        console.error(e);
        if (body) body.innerHTML = '<p class="error">Error de conexión.</p>';
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    const gate = document.getElementById('rfvd-det-gate');
    const app = document.getElementById('rfvd-det-app');
    try {
        const res = await fetch('api/auth_context.php', { credentials: 'same-origin' });
        const ctx = await res.json().catch(() => ({}));
        if (!puedeAcceder(ctx)) {
            if (gate) {
                gate.style.display = 'block';
                gate.innerHTML =
                    ctx.logged && ctx.rol === 'delegado'
                        ? '<p class="error">Vista reservada a administración general.</p><p><a href="panel.html" class="btn-secondary">Panel</a></p>'
                        : '<p class="error">Debe iniciar sesión como administración general.</p><p><a href="index.php" class="btn-secondary">Inicio</a></p>';
            }
            return;
        }
        initFvdReportPage({ nav: '.main-nav-actions' });
        persistJornadaDesdeAuth(ctx);
        void initDelegadoTorneosBar({
            showWhen: (a) => a.logged && (a.rol === 'admingral' || a.rol === 'delegado'),
        });
        if (app) app.style.display = '';
        document.getElementById('rfvd-det-btn-print')?.addEventListener('click', () => window.print());
        await cargar();
    } catch (e) {
        console.error(e);
        if (gate) {
            gate.style.display = 'block';
            gate.textContent = 'Error al iniciar.';
        }
    }
});
