/**
 * Resultado financiero por torneo: ingresos (nómina) vs gastos operativos con %.
 */
import { initFvdReportPage } from './fvd_report_page.js';
import { initDelegadoTorneosBar, persistJornadaDesdeAuth } from './delegado_torneos_bar.js';
import { hrefGastosTorneo, puedeFinanzasOperativasFvd } from './finanzas_reporte_nav.js';
import { rfvdEsc, rfvdFmtEur } from './resumen_finanzas_tabla.js';

const API = 'api/finanza_resultado_torneos.php';

/** @type {Record<string, unknown>|null} */
let authCtx = null;

let selectorPeriodoListo = false;

/** @type {Set<number>} */
const desgloseAbierto = new Set();

function fmtPct(v) {
    if (v === null || v === undefined || Number.isNaN(Number(v))) {
        return '—';
    }
    return `${Number(v).toFixed(1)} %`;
}

function claseResultado(n) {
    const x = Number(n) || 0;
    if (x > 0) return 'ag-fin-resultado-pos';
    if (x < 0) return 'ag-fin-resultado-neg';
    return '';
}

function qsTorneoId() {
    const n = parseInt(new URL(window.location.href).searchParams.get('torneo_id') || '0', 10);
    return n > 0 ? n : 0;
}

function esVistaAsociacion() {
    return !!(authCtx && (authCtx.vista_asociacion || authCtx.rol === 'delegado'));
}

function qsApiListado() {
    const sel = document.getElementById('rft-periodo');
    const v = sel ? String(sel.value) : 'completo';
    const u = new URL(window.location.href);
    u.searchParams.delete('grupo_evento_id');
    u.searchParams.delete('torneo_id');
    let api = API;
    if (v.startsWith('g:')) {
        const gid = parseInt(v.slice(2), 10) || 0;
        if (gid > 0) {
            u.searchParams.set('grupo_evento_id', String(gid));
            api += `?grupo_evento_id=${encodeURIComponent(String(gid))}`;
        }
    }
    history.replaceState(null, '', u.toString());
    return api;
}

function poblarSelectorPeriodo(data) {
    const sel = document.getElementById('rft-periodo');
    if (!sel || esVistaAsociacion()) {
        if (sel) sel.closest('.ag-fin-periodo-field')?.classList.add('is-hidden');
        return;
    }
    const u = new URL(window.location.href);
    const curGrupo = parseInt(u.searchParams.get('grupo_evento_id') || '0', 10);
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

function htmlTablaIngresos(renglones, totalIng) {
    const rows = Array.isArray(renglones) ? renglones : [];
    const body = rows
        .map(
            (r) => `<tr>
            <th scope="row">${rfvdEsc(r.etiqueta || r.codigo)}</th>
            <td class="ag-num">${rfvdEsc(String(r.cantidad ?? 0))}</td>
            <td class="ag-num">${rfvdFmtEur(r.tarifa_eur)}</td>
            <td class="ag-num"><strong>${rfvdFmtEur(r.monto_eur)}</strong></td>
            <td class="ag-num ag-fin-pct">${rfvdEsc(fmtPct(r.pct_ingreso))}</td>
        </tr>`
        )
        .join('');
    return `<table class="fvd-table ag-fin-resultado-sub">
        <thead><tr>
            <th scope="col">Concepto ingreso</th>
            <th scope="col" class="ag-num">Cant.</th>
            <th scope="col" class="ag-num">Tarifa €</th>
            <th scope="col" class="ag-num">Monto €</th>
            <th scope="col" class="ag-num">% ingresos</th>
        </tr></thead>
        <tbody>${body}
        <tr class="ag-fin-resultado-sub-tot">
            <th scope="row">Total ingresos</th>
            <td colspan="2"></td>
            <td class="ag-num"><strong>${rfvdFmtEur(totalIng)}</strong></td>
            <td class="ag-num ag-fin-pct">100 %</td>
        </tr></tbody>
    </table>`;
}

function htmlLineasGasto(lineas, conceptoKey) {
    const list = Array.isArray(lineas) ? lineas : [];
    if (list.length < 1) return '';
    const rows = list
        .map(
            (ln) => `<tr>
            <td>${rfvdEsc(String(ln.fecha || ''))}</td>
            <td class="ag-num">${Number(ln.monto_bs || 0).toFixed(2)} Bs</td>
            <td class="ag-num">${rfvdFmtEur(ln.monto_eur)}</td>
            <td>${rfvdEsc(String(ln.nro_factura || '—'))}</td>
            <td>${rfvdEsc(String(ln.notas || ''))}</td>
            <td class="ag-num ag-fin-pct">${rfvdEsc(fmtPct(ln.pct_ingreso))}</td>
            <td class="ag-num ag-fin-pct">${rfvdEsc(fmtPct(ln.pct_gastos))}</td>
        </tr>`
        )
        .join('');
    return `<div class="ag-fin-resultado-lineas is-hidden" id="rft-lineas-${conceptoKey}">
        <table class="fvd-table ag-fin-resultado-lineas-tbl">
            <thead><tr>
                <th>Fecha</th>
                <th class="ag-num">Bs</th>
                <th class="ag-num">€</th>
                <th>Factura</th>
                <th>Notas</th>
                <th class="ag-num">% ing.</th>
                <th class="ag-num">% gastos</th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table>
    </div>`;
}

function htmlTablaGastos(conceptos, totalGastos, totalIng) {
    const grupos = Array.isArray(conceptos) ? conceptos : [];
    if (grupos.length < 1) {
        return '<p class="ag-muted">Sin gastos registrados.</p>';
    }
    const body = grupos
        .map((g, i) => {
            const key = `c${i}`;
            const tieneLineas = (g.lineas || []).length > 0;
            const btn = tieneLineas
                ? `<button type="button" class="btn-secondary btn-sm rft-toggle-lineas" data-lineas="${key}" data-n="${rfvdEsc(String(g.n ?? 0))}">Ver ${g.n} reg.</button>`
                : '';
            return `<tr>
            <th scope="row">${rfvdEsc(g.concepto)}</th>
            <td class="ag-num">${rfvdEsc(String(g.n ?? 0))}</td>
            <td class="ag-num"><strong>${rfvdFmtEur(g.total_eur)}</strong></td>
            <td class="ag-num ag-fin-pct">${rfvdEsc(fmtPct(g.pct_ingreso))}</td>
            <td class="ag-num ag-fin-pct">${rfvdEsc(fmtPct(g.pct_gastos))}</td>
            <td>${btn}</td>
        </tr>
        ${tieneLineas ? `<tr class="ag-fin-resultado-lineas-row"><td colspan="6">${htmlLineasGasto(g.lineas, key)}</td></tr>` : ''}`;
        })
        .join('');
    return `<table class="fvd-table ag-fin-resultado-sub">
        <thead><tr>
            <th scope="col">Concepto gasto</th>
            <th scope="col" class="ag-num">Reg.</th>
            <th scope="col" class="ag-num">Monto €</th>
            <th scope="col" class="ag-num">% ingresos</th>
            <th scope="col" class="ag-num">% gastos</th>
            <th scope="col"></th>
        </tr></thead>
        <tbody>${body}
        <tr class="ag-fin-resultado-sub-tot">
            <th scope="row">Total gastos</th>
            <td></td>
            <td class="ag-num"><strong>${rfvdFmtEur(totalGastos)}</strong></td>
            <td class="ag-num ag-fin-pct">${rfvdEsc(fmtPct(totalIng > 0 ? (100 * totalGastos) / totalIng : null))}</td>
            <td class="ag-num ag-fin-pct">100 %</td>
            <td></td>
        </tr></tbody>
    </table>`;
}

function htmlDesglosePanel(detalle, torneoId) {
    const ing = detalle.ingresos || {};
    const gastos = detalle.gastos || {};
    const res = detalle.resultado || {};
    const totalIng = Number(ing.total_nomina_eur || 0);
    const totalGastos = Number(gastos.totales?.total_eur || res.gastos_eur || 0);

    return `<div class="ag-fin-resultado-desglose" data-rft-desglose="${torneoId}">
        <div class="ag-fin-resultado-cajas">
            <div class="ag-fin-resultado-caja">
                <span class="ag-fin-resultado-caja-lbl">Ingresos</span>
                <strong>${rfvdFmtEur(res.ingresos_eur)}</strong>
            </div>
            <div class="ag-fin-resultado-caja">
                <span class="ag-fin-resultado-caja-lbl">Gastos</span>
                <strong>${rfvdFmtEur(res.gastos_eur)}</strong>
                <span class="ag-fin-resultado-caja-pct">${rfvdEsc(fmtPct(res.pct_gastos_ingreso))} s/ingresos</span>
            </div>
            <div class="ag-fin-resultado-caja ${claseResultado(res.resultado_eur)}">
                <span class="ag-fin-resultado-caja-lbl">Resultado (margen)</span>
                <strong>${rfvdFmtEur(res.resultado_eur)}</strong>
                <span class="ag-fin-resultado-caja-pct">${rfvdEsc(fmtPct(res.pct_resultado_ingreso))} s/ingresos</span>
            </div>
        </div>
        <div class="ag-fin-resultado-split">
            <section>
                <h3 class="ag-fin-resultado-h3">Ingresos por concepto</h3>
                ${htmlTablaIngresos(ing.renglones, totalIng)}
            </section>
            <section>
                <h3 class="ag-fin-resultado-h3">Gastos por concepto</h3>
                ${htmlTablaGastos(gastos.conceptos, totalGastos, totalIng)}
                <p class="ag-muted ag-fin-resultado-gastos-link">
                    <a href="${rfvdEsc(hrefGastosTorneo(torneoId))}" class="btn-secondary btn-sm">Registrar / editar gastos</a>
                </p>
            </section>
        </div>
    </div>`;
}

function wireDesglosePanel(container) {
    container.querySelectorAll('.rft-toggle-lineas').forEach((btn) => {
        btn.addEventListener('click', () => {
            const key = btn.getAttribute('data-lineas') || '';
            const panel = container.querySelector(`#rft-lineas-${key}`);
            if (!panel) return;
            const hidden = panel.classList.toggle('is-hidden');
            const n = btn.getAttribute('data-n') || '';
            btn.textContent = hidden ? `Ver ${n} reg.` : 'Ocultar';
        });
    });
}

function htmlFilaListado(row) {
    const tid = parseInt(String(row.torneo_id ?? 0), 10);
    const abierto = desgloseAbierto.has(tid);
    return `<tr class="ag-fin-resultado-row" data-torneo-id="${tid}">
        <th scope="row" class="ag-fin-periodo-nom">${rfvdEsc(row.nombre || `Torneo ${tid}`)}</th>
        <td class="ag-num">${rfvdFmtEur(row.ingresos_eur)}</td>
        <td class="ag-num">${rfvdFmtEur(row.gastos_eur)}</td>
        <td class="ag-num ${claseResultado(row.resultado_eur)}"><strong>${rfvdFmtEur(row.resultado_eur)}</strong></td>
        <td class="ag-num ag-fin-pct">${rfvdEsc(fmtPct(row.pct_gastos_ingreso))}</td>
        <td class="ag-fin-periodo-acc">
            <button type="button" class="btn-secondary btn-sm rft-btn-desglose" data-tid="${tid}" aria-expanded="${abierto}">
                ${abierto ? 'Ocultar' : 'Desglose'}
            </button>
            <a class="btn-secondary btn-sm" href="resultado_financiero_torneo.html?torneo_id=${encodeURIComponent(String(tid))}">Página</a>
        </td>
    </tr>
    <tr class="ag-fin-resultado-expand${abierto ? '' : ' is-hidden'}" id="rft-expand-${tid}">
        <td colspan="6"><div class="rft-expand-slot" data-slot="${tid}">${abierto ? '<p class="ag-muted">Cargando…</p>' : ''}</div></td>
    </tr>`;
}

function htmlTablaListado(filas, totales) {
    const rows = Array.isArray(filas) ? filas : [];
    const t = totales && typeof totales === 'object' ? totales : {};
    const body = rows.map((r) => htmlFilaListado(r)).join('');
    const foot = `<tr class="ag-fin-periodo-total ag-fin-resultado-total">
        <th scope="row">Total general</th>
        <td class="ag-num">${rfvdFmtEur(t.ingresos_eur)}</td>
        <td class="ag-num">${rfvdFmtEur(t.gastos_eur)}</td>
        <td class="ag-num ${claseResultado(t.resultado_eur)}"><strong>${rfvdFmtEur(t.resultado_eur)}</strong></td>
        <td class="ag-num ag-fin-pct">${rfvdEsc(fmtPct(t.pct_gastos_ingreso))}</td>
        <td></td>
    </tr>`;
    return `<div class="ag-fin-periodo-shell ag-fin-resultado-shell">
        <table class="fvd-table ag-fin-periodo-matrix ag-fin-resultado-matrix">
            <thead><tr>
                <th scope="col">Torneo</th>
                <th scope="col" class="ag-num">Ingresos €</th>
                <th scope="col" class="ag-num">Gastos €</th>
                <th scope="col" class="ag-num">Resultado €</th>
                <th scope="col" class="ag-num">% gastos</th>
                <th scope="col">Acciones</th>
            </tr></thead>
            <tbody>${body}${foot}</tbody>
        </table>
    </div>`;
}

async function cargarDesgloseInline(torneoId) {
    const slot = document.querySelector(`.rft-expand-slot[data-slot="${torneoId}"]`);
    if (!slot) return;
    slot.innerHTML = '<p class="ag-muted">Cargando desglose…</p>';
    try {
        const res = await fetch(`${API}?torneo_id=${encodeURIComponent(String(torneoId))}`, {
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok || !data.detalle) {
            slot.innerHTML = `<p class="error">${rfvdEsc(data.message || 'No se pudo cargar.')}</p>`;
            return;
        }
        slot.innerHTML = htmlDesglosePanel(data.detalle, torneoId);
        wireDesglosePanel(slot);
    } catch (e) {
        console.error(e);
        slot.innerHTML = '<p class="error">Error de conexión.</p>';
    }
}

function toggleDesglose(torneoId) {
    const expand = document.getElementById(`rft-expand-${torneoId}`);
    const btn = document.querySelector(`.rft-btn-desglose[data-tid="${torneoId}"]`);
    if (!expand) return;
    if (desgloseAbierto.has(torneoId)) {
        desgloseAbierto.delete(torneoId);
        expand.classList.add('is-hidden');
        if (btn) {
            btn.textContent = 'Desglose';
            btn.setAttribute('aria-expanded', 'false');
        }
    } else {
        desgloseAbierto.add(torneoId);
        expand.classList.remove('is-hidden');
        if (btn) {
            btn.textContent = 'Ocultar';
            btn.setAttribute('aria-expanded', 'true');
        }
        void cargarDesgloseInline(torneoId);
    }
}

function renderListado(data) {
    const el = document.getElementById('rft-listado');
    if (!el) return;
    const listado = data.listado || {};
    const filas = listado.filas_torneos || [];
    const tot = listado.totales || {};
    const periodo = listado.periodo || {};

    const lead = document.getElementById('rft-lead');
    if (lead) {
        let txt = `${periodo.etiqueta || 'Periodo'} — ${filas.length} torneo(s). `;
        txt += `Ingresos ${rfvdFmtEur(tot.ingresos_eur)}, gastos ${rfvdFmtEur(tot.gastos_eur)}, `;
        txt += `resultado ${rfvdFmtEur(tot.resultado_eur)}.`;
        lead.textContent = txt;
    }

    if (filas.length < 1) {
        el.innerHTML = '<p class="ag-muted">Sin movimiento en el ámbito seleccionado.</p>';
        return;
    }

    el.innerHTML = htmlTablaListado(filas, tot);
    el.querySelectorAll('.rft-btn-desglose').forEach((btn) => {
        btn.addEventListener('click', () => {
            const tid = parseInt(btn.getAttribute('data-tid') || '0', 10);
            if (tid > 0) toggleDesglose(tid);
        });
    });
}

function renderDetallePagina(data) {
    const wrap = document.getElementById('rft-det-wrap');
    const listWrap = document.getElementById('rft-list-wrap');
    const el = document.getElementById('rft-detalle');
    const tor = data.torneo || {};
    const det = data.detalle || {};
    const tid = parseInt(String(tor.torneo_id ?? qsTorneoId()), 10);

    if (listWrap) listWrap.classList.add('is-hidden');
    if (wrap) wrap.classList.remove('is-hidden');

    const gastosNav = document.getElementById('rft-nav-gastos');
    if (gastosNav && tid > 0) gastosNav.href = hrefGastosTorneo(tid);

    const lead = document.getElementById('rft-lead');
    if (lead) {
        lead.textContent = `${tor.nombre || 'Torneo'} — desglose ingresos y gastos con porcentajes (base 100 % = ingresos).`;
    }

    if (el) {
        el.innerHTML = `<header class="ag-fin-resultado-det-head">
            <h2>${rfvdEsc(tor.nombre || `Torneo ${tid}`)}</h2>
        </header>${htmlDesglosePanel(det, tid)}`;
        wireDesglosePanel(el);
    }
}

function aplicarNotas(data) {
    const notas = document.getElementById('rft-notas');
    if (!notas) return;
    const parts = [];
    if (data.nota_ingresos) parts.push(String(data.nota_ingresos));
    if (data.nota_gastos) parts.push(String(data.nota_gastos));
    notas.textContent = parts.join(' ');
}

async function cargarListado() {
    const el = document.getElementById('rft-listado');
    if (el) el.innerHTML = '<p class="ag-muted">Cargando…</p>';
    const res = await fetch(qsApiListado(), { credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
        if (el) el.innerHTML = `<p class="error">${rfvdEsc(data.message || 'Error al cargar.')}</p>`;
        return;
    }
    authCtx = { ...authCtx, ...data };
    if (!selectorPeriodoListo) {
        poblarSelectorPeriodo(data);
        selectorPeriodoListo = true;
    }
    aplicarNotas(data);
    renderListado(data);
}

async function cargarDetalle(torneoId) {
    const el = document.getElementById('rft-detalle');
    if (el) el.innerHTML = '<p class="ag-muted">Cargando…</p>';
    const res = await fetch(`${API}?torneo_id=${encodeURIComponent(String(torneoId))}`, {
        credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
        if (el) el.innerHTML = `<p class="error">${rfvdEsc(data.message || 'Error.')}</p>`;
        return;
    }
    aplicarNotas(data);
    renderDetallePagina(data);
}

function puedeAcceder(ctx) {
    return puedeFinanzasOperativasFvd(ctx);
}

document.addEventListener('DOMContentLoaded', async () => {
    const gate = document.getElementById('rft-gate');
    const app = document.getElementById('rft-app');
    const tidUrl = qsTorneoId();

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
                        ? '<p class="error">El resultado ingresos/gastos del torneo es información interna de la FVD.</p>' +
                          `<p><a href="${finHref}" class="btn-secondary">Estado de cuentas</a> · <a href="panel.html" class="btn-secondary">Panel</a></p>`
                        : '<p class="error">Debe iniciar sesión como administración general.</p>' +
                          '<p><a href="panel.html" class="btn-secondary">Panel</a></p>';
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
        document.getElementById('rft-btn-cargar')?.addEventListener('click', () => void cargarListado());
        document.getElementById('rft-periodo')?.addEventListener('change', () => void cargarListado());
        document.getElementById('rft-btn-print')?.addEventListener('click', () => window.print());

        if (tidUrl > 0) {
            await cargarDetalle(tidUrl);
        } else {
            await cargarListado();
        }
    } catch (e) {
        console.error('resultado_financiero_torneo', e);
        if (gate) {
            gate.style.display = 'block';
            gate.innerHTML = '<p class="error">Error al iniciar.</p>';
        }
    }
});
