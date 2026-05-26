/**
 * Tablas resumen finanzas: filas = torneo o asociación; columnas = eventos (cantidad + monto).
 */
import {
    RENGLONES_ESTADISTICA,
    htmlFvdTableShell,
    reporteEsc,
    htmlThEstadisticaRenglon,
    claseColumnaEstadisticaRenglon,
} from './reporte_tabla.js';

export function rfvdEsc(v) {
    return reporteEsc(v);
}

export function rfvdFmtEur(n) {
    const m = Math.round((Number(n) || 0) * 100) / 100;
    return `${m.toFixed(2)} €`;
}

/**
 * @param {object[]} renglones
 * @returns {Record<string, {cantidad: number, monto_eur: number}>}
 */
export function rfvdMapRenglones(renglones) {
    /** @type {Record<string, {cantidad: number, monto_eur: number}>} */
    const m = {};
    (renglones || []).forEach((r) => {
        const c = String(r.codigo || '').trim();
        if (c) {
            m[c] = {
                cantidad: parseInt(String(r.cantidad ?? 0), 10) || 0,
                monto_eur: Number(r.monto_eur) || 0,
            };
        }
    });
    return m;
}

/**
 * @param {object} r
 * @param {string} codigo
 */
function celdaEvento(r, codigo) {
    const map = rfvdMapRenglones(r.renglones || []);
    const x = map[codigo] || { cantidad: 0, monto_eur: 0 };
    const meta = RENGLONES_ESTADISTICA.find((e) => e.codigo === codigo);
    const narrow = meta ? claseColumnaEstadisticaRenglon(meta) : '';
    return `<td class="ag-num ag-stat-cell${narrow}">
        <span class="ag-stat-qty">${rfvdEsc(String(x.cantidad))}</span>
        <span class="ag-stat-eur">${rfvdEsc(rfvdFmtEur(x.monto_eur))}</span>
    </td>`;
}

/**
 * @param {object[]} filas
 * @param {{ tipo: 'torneo'|'asociacion', torneoId?: number, linkDetalle?: (row: object) => string, linkAsociacion?: (row: object) => string }} opts
 */
export function htmlTablaResumenFinanzas(filas, opts) {
    const rows = Array.isArray(filas) ? filas : [];
    const tipo = opts.tipo === 'asociacion' ? 'asociacion' : 'torneo';
    const labelCol = tipo === 'torneo' ? 'Torneo' : 'Asociación';
    const thEventos = RENGLONES_ESTADISTICA.map((r) => htmlThEstadisticaRenglon(r)).join('');

    const body = rows
        .map((row) => {
            const nom =
                tipo === 'torneo'
                    ? String(row.nombre || `Torneo ${row.torneo_id || ''}`)
                    : String(row.nombre || `Asociación ${row.id || ''}`);
            const celdas = RENGLONES_ESTADISTICA.map((ev) => celdaEvento(row, ev.codigo)).join('');
            const tot = rfvdFmtEur(row.total_nomina_eur);
            let acc = '';
            if (tipo === 'torneo' && typeof opts.linkDetalle === 'function') {
                const href = opts.linkDetalle(row);
                acc = `<a class="btn-secondary btn-sm" href="${rfvdEsc(href)}">Ver detalles</a>`;
                if (typeof opts.linkGastos === 'function') {
                    const hrefG = opts.linkGastos(row);
                    acc += ` <a class="btn-secondary btn-sm" href="${rfvdEsc(hrefG)}">Gastos</a>`;
                }
                if (typeof opts.linkResultado === 'function') {
                    const hrefR = opts.linkResultado(row);
                    acc += ` <a class="btn-secondary btn-sm" href="${rfvdEsc(hrefR)}">Resultado</a>`;
                }
            } else if (tipo === 'asociacion' && typeof opts.linkAsociacion === 'function') {
                const href = opts.linkAsociacion(row);
                const hrefFin =
                    typeof opts.linkFinanzasAsociacion === 'function' ? opts.linkFinanzasAsociacion(row) : '';
                acc = `<a class="btn-secondary btn-sm" href="${rfvdEsc(href)}">Informe</a>`;
                if (hrefFin) {
                    acc += ` <a class="btn-secondary btn-sm" href="${rfvdEsc(hrefFin)}">Cuenta</a>`;
                }
            }
            return `<tr>
            <th scope="row" class="ag-fin-periodo-nom">${rfvdEsc(nom)}</th>
            ${celdas}
            <td class="ag-num ag-fin-periodo-tot-cell"><strong>${rfvdEsc(tot)}</strong></td>
            <td class="ag-fin-periodo-acc">${acc}</td>
        </tr>`;
        })
        .join('');

    const table = `<table class="fvd-table ag-fin-periodo-matrix">
        <thead><tr>
            <th scope="col">${rfvdEsc(labelCol)}</th>
            ${thEventos}
            <th scope="col" class="ag-num">Total €</th>
            <th scope="col">Acciones</th>
        </tr></thead>
        <tbody>${body}</tbody>
    </table>`;
    return htmlFvdTableShell(table, 'ag-fin-periodo-shell ag-fin-periodo-matrix-wrap');
}

/**
 * @param {object} totales — { renglones, total_nomina_eur }
 */
export function htmlFilaTotalesResumen(totales) {
    const t = totales && typeof totales === 'object' ? totales : {};
    const pseudo = { renglones: t.renglones || [], total_nomina_eur: t.total_nomina_eur };
    const celdas = RENGLONES_ESTADISTICA.map((ev) => celdaEvento(pseudo, ev.codigo)).join('');
    return `<tr class="ag-fin-periodo-total">
        <th scope="row">Total general</th>
        ${celdas}
        <td class="ag-num ag-fin-periodo-tot-cell"><strong>${rfvdEsc(rfvdFmtEur(t.total_nomina_eur))}</strong></td>
        <td></td>
    </tr>`;
}
