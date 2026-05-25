/**
 * UI compartida: detalle de finanzas por asociación (acordeón campeonato / torneos).
 */

import {
    REPORTE_FILAS_POR_PAGINA,
    RENGLONES_ESTADISTICA,
    reporteEsc,
    reporteHtmlPaginador,
    reporteLigarPaginador,
    reporteSliceCliente,
    htmlFvdTableShell,
    htmlCeldaEstadisticaRenglon,
    htmlThEstadisticaRenglon,
    filaTieneEstadisticaRenglon,
} from './reporte_tabla.js';
import {
    hrefFinanzasAsociacion,
    hrefInformeAsociacion,
    htmlLinkDetalleRenglon,
    navContextoFinanzas,
} from './finanzas_reporte_nav.js';
import { reporteThumbImg } from './reporte_tabla.js';

/** @type {Record<string, Record<string, number>>} */
const paginasPorPrefijo = {};

/**
 * @param {string} prefix
 */
export function resetPaginasDetalleFinanza(prefix) {
    if (prefix) paginasPorPrefijo[prefix] = {};
}

function pagMap(prefix) {
    const p = prefix || 'fin-aso-inf';
    if (!paginasPorPrefijo[p]) paginasPorPrefijo[p] = {};
    return paginasPorPrefijo[p];
}

export function etiquetaEstadoTorneo(tor) {
    if (!tor) return '';
    if (tor.finalizado_en) return ' (finalizado)';
    return ' (en curso)';
}

export function htmlCeldaMontoRenglon(r) {
    if (r.codigo === 'total_cuenta') {
        const deu = r.monto_eur != null ? reporteEsc(String(r.monto_eur)) : '—';
        const pag = r.pagado_eur != null ? reporteEsc(String(r.pagado_eur)) : '—';
        const sal = r.saldo_eur != null ? reporteEsc(String(r.saldo_eur)) : '—';
        return `<td class="ag-num"><strong>${deu} €</strong> <span class="ag-muted ag-fin-cuenta-sub">deuda</span><br><span class="ag-muted text-xs">Pagado ${pag} € · Saldo ${sal} €</span></td>`;
    }
    return `<td class="ag-num">${reporteEsc(String(r.monto_eur))} €</td>`;
}

/**
 * @param {Array} ren
 * @param {string} statKey
 * @param {number|null} idx
 * @param {string} pagPrefix
 * @param {{ asocId?: number, torneoId?: number, grupoId?: number }} [nav]
 */
export function htmlTablaRenglonesInforme(ren, statKey, idx, pagPrefix = 'fin-aso-inf', nav = null) {
    const per = REPORTE_FILAS_POR_PAGINA;
    const sk = statKey || (idx != null ? `i${idx}` : '0');
    const pag = pagMap(pagPrefix);
    if (pag[sk] == null) pag[sk] = 1;
    const pg = pag[sk];
    const slice = reporteSliceCliente(ren, pg, per);
    const domKey = (k) => String(k).replace(/[^a-zA-Z0-9]/g, '_');
    const pref = `${pagPrefix}-${domKey(sk)}`;
    const pager = reporteHtmlPaginador(pref, pg, ren.length, per);
    const ctx = navContextoFinanzas(nav || {});
    const conDetalle = ctx.asocId > 0;
    const rows = slice
        .map((r) => {
            const cod = r.codigo || '';
            const det =
                conDetalle && cod && cod !== 'total_cuenta' && cod !== 'otros'
                    ? htmlLinkDetalleRenglon(
                          ctx.asocId,
                          cod,
                          { torneoId: ctx.torneoId, grupoId: ctx.grupoId },
                          { iconos: true, etiqueta: r.etiqueta || cod }
                      )
                    : cod === 'total_cuenta' && ctx.asocId > 0
                      ? `<a class="btn-secondary btn-sm" href="finanzas_asociacion.html?id=${encodeURIComponent(String(ctx.asocId))}${ctx.grupoId > 0 ? `&grupo_evento_id=${ctx.grupoId}&consolidar_campeonato=1` : ctx.torneoId > 0 ? `&torneo_id=${ctx.torneoId}` : ''}#fin-aso-cargos">Cuenta</a>`
                      : '—';
            return `<tr>
                <td><strong>${reporteEsc(r.etiqueta || cod)}</strong></td>
                <td class="ag-num">${r.cantidad != null ? reporteEsc(String(r.cantidad)) : '—'}</td>
                ${htmlCeldaMontoRenglon(r)}
                ${conDetalle ? `<td class="reporte-td-acciones">${det}</td>` : ''}
            </tr>`;
        })
        .join('');
    const colDet = conDetalle ? '<th class="reporte-th-acciones">Detalle</th>' : '';
    const colspan = conDetalle ? 4 : 3;
    return {
        html: `${pager}${htmlFvdTableShell(`<table class="fvd-table admin-crud-table admin-crud-table--compact text-sm">
            <thead><tr><th>Concepto</th><th class="ag-num">Cantidad</th><th class="ag-num">Monto €</th>${colDet}</tr></thead>
            <tbody>${rows || `<tr><td colspan="${colspan}" class="ag-muted">Sin renglones</td></tr>`}</tbody>
        </table>`)}`,
        statKey: sk,
        ren,
        pagerPref: pref,
    };
}

function htmlBloqueTorneoInterno(b, idx, pagPrefix, nav, opts = {}) {
    const tor = b.torneo_activo || null;
    const tid = tor && tor.id != null ? parseInt(String(tor.id), 10) : 0;
    const nom = tor && tor.nombre ? String(tor.nombre) : 'Torneo';
    const ren = b.renglones || [];
    const statKey = tid > 0 ? `t${tid}` : `i${idx}`;
    const navTor = navContextoFinanzas(nav || {});
    const tab = htmlTablaRenglonesInforme(ren, statKey, idx, pagPrefix, {
        asocId: navTor.asocId,
        torneoId: tid > 0 ? tid : navTor.torneoId,
        grupoId: navTor.grupoId,
    });
    const tot = b.totales || {};
    const nomEur =
        tot.monto_nomina_eur != null
            ? reporteEsc(String(tot.monto_nomina_eur))
            : tot.monto_total_eur != null
              ? reporteEsc(String(tot.monto_total_eur))
              : '—';
    const accName = opts.acordeonName ? String(opts.acordeonName) : '';
    const nameAttr = accName ? ` name="${reporteEsc(accName)}"` : '';
    const openFirst = opts.openFirst === true && idx === 0 ? ' open' : '';
    return `<details class="ag-fin-acordeon ag-fin-acordeon--torneo"${nameAttr}${openFirst}>
        <summary class="ag-fin-acordeon-sum">
            <span class="ag-fin-acordeon-tit">${reporteEsc(nom)}${reporteEsc(etiquetaEstadoTorneo(tor))}</span>
            <span class="ag-fin-acordeon-meta">id ${tid > 0 ? reporteEsc(String(tid)) : '—'} · Nómina <strong>${nomEur} €</strong></span>
        </summary>
        <div class="ag-fin-acordeon-body">
            <div class="reporte-vista">${tab.html}</div>
        </div>
    </details>`;
}

/**
 * Detalle por torneo en acordeón (un torneo abierto a la vez si se pasa acordeonName).
 * @param {Array} bloques
 * @param {string} [pagPrefix]
 * @param {{ asocId?: number }} [nav]
 * @param {{ acordeonName?: string, openFirst?: boolean }} [opts]
 */
export function htmlBloquesInformePorTorneoAcordeon(bloques, pagPrefix = 'fin-aso-inf', nav = null, opts = {}) {
    const list = Array.isArray(bloques) ? bloques : [];
    if (list.length < 1) {
        return '<p class="ag-muted">Sin datos de informe por torneo.</p>';
    }
    return `<div class="ag-fin-acordeon-variantes">${list
        .map((b, idx) => htmlBloqueTorneoInterno(b, idx, pagPrefix, nav, opts))
        .join('')}</div>`;
}

/**
 * @param {Array} grupos
 * @param {string} [pagPrefix]
 * @param {{ asocId?: number, torneoId?: number, grupoId?: number }} [nav]
 */
export function htmlGruposInformeAcordeon(grupos, pagPrefix = 'fin-aso-inf', nav = null) {
    const list = Array.isArray(grupos) ? grupos : [];
    if (list.length < 1) {
        return '<p class="ag-muted">Sin datos de informe por torneo.</p>';
    }
    return `<div class="ag-fin-acordeon-root">${list
        .map((g, gIdx) => {
            const esCamp = g.tipo === 'campeonato';
            const tit = g.etiqueta ? String(g.etiqueta) : esCamp ? 'Campeonato' : 'Torneo';
            const nTor = g.n_torneos != null ? parseInt(String(g.n_torneos), 10) : 0;
            const totC = g.totales_consolidados || {};
            const nomCons =
                totC.monto_nomina_eur != null
                    ? reporteEsc(String(totC.monto_nomina_eur))
                    : totC.monto_total_eur != null
                      ? reporteEsc(String(totC.monto_total_eur))
                      : '—';
            const modo =
                g.modo_campeonato === 'campeonato_genero'
                    ? ' · por género'
                    : g.modo_campeonato === 'campeonato_categoria'
                      ? ' · por categoría'
                      : '';
            const gid = g.grupo_evento_id != null ? parseInt(String(g.grupo_evento_id), 10) : 0;
            const renCons = Array.isArray(g.renglones_consolidados) ? g.renglones_consolidados : [];
            const statCons = gid > 0 ? `g${gid}` : `gc${gIdx}`;
            const navCtx = navContextoFinanzas(nav || {});
            const tabCons =
                renCons.length > 0
                    ? htmlTablaRenglonesInforme(renCons, statCons, gIdx, pagPrefix, {
                          asocId: navCtx.asocId,
                          torneoId: navCtx.torneoId,
                          grupoId: gid > 0 ? gid : navCtx.grupoId,
                      })
                    : null;
            const subs = Array.isArray(g.torneos) ? g.torneos : [];
            const innerTorneos = subs.map((b, i) => htmlBloqueTorneoInterno(b, i, pagPrefix, nav)).join('');
            const openAttr = esCamp && list.length <= 3 ? ' open' : '';
            const clase = esCamp ? 'ag-fin-acordeon ag-fin-acordeon--campeonato' : 'ag-fin-acordeon ag-fin-acordeon--suelto';
            const resumenCons =
                tabCons != null
                    ? `<div class="ag-fin-acordeon-consolidado">
                <p class="ag-muted ag-fin-acordeon-cons-kicker">Totales consolidados del campeonato (${nTor} torneo(s))</p>
                <div class="reporte-vista">${tabCons.html}</div>
            </div>`
                    : '';
            return `<details class="${clase}"${openAttr}>
                <summary class="ag-fin-acordeon-sum ag-fin-acordeon-sum--camp">
                    <span class="ag-fin-acordeon-tit">${reporteEsc(tit)}${reporteEsc(modo)}</span>
                    <span class="ag-fin-acordeon-meta">${esCamp ? `Campeonato · ${nTor} variantes` : 'Torneo'} · Nómina consolidada <strong>${nomCons} €</strong></span>
                </summary>
                <div class="ag-fin-acordeon-body">
                    ${resumenCons}
                    <div class="ag-fin-acordeon-variantes">${innerTorneos || '<p class="ag-muted">Sin variantes.</p>'}</div>
                </div>
            </details>`;
        })
        .join('')}</div>`;
}

/**
 * @param {Array} bloques
 * @param {string} [pagPrefix]
 * @param {{ asocId?: number, torneoId?: number, grupoId?: number }} [nav]
 */
export function htmlBloquesInformePorTorneo(bloques, pagPrefix = 'fin-aso-inf', nav = null) {
    const list = Array.isArray(bloques) ? bloques : [];
    if (list.length < 1) {
        return '<p class="ag-muted">Sin datos de informe por torneo.</p>';
    }
    return list
        .map((b, idx) => {
            const tor = b.torneo_activo || null;
            const tid = tor && tor.id != null ? parseInt(String(tor.id), 10) : 0;
            const nom = tor && tor.nombre ? String(tor.nombre) : 'Torneo';
            const ren = b.renglones || [];
            const statKey = tid > 0 ? String(tid) : `i${idx}`;
            const navCtx = navContextoFinanzas(nav || {});
            const tab = htmlTablaRenglonesInforme(ren, statKey, idx, pagPrefix, {
                asocId: navCtx.asocId,
                torneoId: tid > 0 ? tid : navCtx.torneoId,
                grupoId: navCtx.grupoId,
            });
            const tot = b.totales || {};
            const nomEur =
                tot.monto_nomina_eur != null
                    ? reporteEsc(String(tot.monto_nomina_eur))
                    : tot.monto_total_eur != null
                      ? reporteEsc(String(tot.monto_total_eur))
                      : '—';
            const ec = tot.estado_cuenta || null;
            const pieCuenta =
                ec != null
                    ? `<p class="ag-fin-torneo-cuenta"><strong>Total cuenta asociación</strong> — ${reporteEsc(String(ec.deuda_eur))} € (nómina todos los torneos: ${reporteEsc(String(ec.nomina_eur ?? 0))} € + cargos: ${reporteEsc(String(ec.cargos_manuales_eur ?? 0))} €) · Pagado: ${reporteEsc(String(ec.pagado_eur))} € · Saldo: ${reporteEsc(String(ec.saldo_eur))} €</p>`
                    : '';
            const anchor = tid > 0 ? `id="fin-torneo-${tid}"` : '';
            return `<section class="ag-fin-torneo-bloque" ${anchor}>
                <h2 class="ag-subtitle ag-fin-section-title">${reporteEsc(nom)}${reporteEsc(etiquetaEstadoTorneo(tor))}</h2>
                <p class="ag-muted ag-fin-torneo-meta">Torneo id: <code>${tid > 0 ? reporteEsc(String(tid)) : '—'}</code></p>
                <div class="reporte-vista">${tab.html}</div>
                <p class="ag-fin-torneo-total"><strong>Total nómina (este torneo)</strong>: ${nomEur} €</p>
                ${pieCuenta}
            </section>`;
        })
        .join('');
}

/**
 * @param {(url: string, options?: object) => Promise<{res: Response, data: object}>} fetchJson
 * @param {number} asocId
 * @param {{ torneoId?: number, grupoId?: number }} scope
 */
/**
 * Reporte: cada asociación con lista de torneos y resumen por renglón.
 * @param {Array} asociaciones
 * @param {string} [pagPrefix]
 * @param {{ soloUna?: boolean }} [opts]
 */
export function htmlReporteAsociacionesPorTorneo(asociaciones, pagPrefix = 'rep-asoc-tor', opts = {}) {
    const list = Array.isArray(asociaciones) ? asociaciones : [];
    if (list.length < 1) {
        return '<p class="ag-muted">Ninguna asociación tiene movimiento en torneos registrados.</p>';
    }
    return `<div class="ag-fin-reporte-asoc-tor-root ag-fvd-reporte-datos">${list
        .map((item, idx) => {
            const a = item.asociacion || {};
            const aid = a.id != null ? parseInt(String(a.id), 10) : 0;
            const bloques = item.informes_por_torneo || [];
            const ec = item.estado_cuenta || null;
            const nTor = item.n_torneos != null ? parseInt(String(item.n_torneos), 10) : bloques.length;
            const nom = a.nombre ? String(a.nombre) : `Asociación ${aid}`;
            const deu = ec != null ? reporteEsc(String(ec.deuda_eur ?? 0)) : '—';
            const sal = ec != null ? reporteEsc(String(ec.saldo_eur ?? 0)) : '—';
            const openAttr = opts.soloUna || list.length <= 4 ? ' open' : idx === 0 ? ' open' : '';
            const pieCuenta =
                ec != null
                    ? `<div class="ag-fin-recibo-sum ag-fin-recibo-sum--fvd ag-fin-asoc-resumen-cuenta">
                <span><strong>Deuda total</strong> ${deu} €</span>
                <span><strong>Nómina</strong> ${reporteEsc(String(ec.nomina_eur ?? 0))} €</span>
                <span><strong>Pagado</strong> ${reporteEsc(String(ec.pagado_eur ?? 0))} €</span>
                <span><strong>Saldo</strong> ${sal} €</span>
            </div>`
                    : '';
            const prefAsoc = `${pagPrefix}-a${aid > 0 ? aid : idx}`;
            const bloquesHtml = htmlBloquesInformePorTorneo(bloques, prefAsoc, { asocId: aid });
            const linkInf =
                aid > 0
                    ? `<a class="btn-secondary btn-sm" href="${hrefInformeAsociacion(aid)}">Desglose por renglón</a>`
                    : '';
            return `<details class="ag-fin-acordeon ag-fin-acordeon--asociacion"${openAttr}>
                <summary class="ag-fin-acordeon-sum ag-fin-acordeon-sum--asoc">
                    <span class="reporte-thumb-wrap">${reporteThumbImg(a.logo || '', nom)}</span>
                    <span class="ag-fin-acordeon-tit">${reporteEsc(nom)}</span>
                    <span class="ag-fin-acordeon-meta">${nTor} torneo(s) · Deuda <strong>${deu} €</strong> · Saldo <strong>${sal} €</strong></span>
                </summary>
                <div class="ag-fin-acordeon-body ag-fin-acordeon-body--asoc">
                    ${pieCuenta}
                    <p class="ag-muted ag-fin-hint-copy">Cada torneo muestra cantidades y montos por concepto. Use <strong>🔍 Detalle</strong> para ver atletas del renglón.</p>
                    ${bloquesHtml}
                    ${linkInf ? `<p class="ag-fin-panel-det-links">${linkInf}</p>` : ''}
                </div>
            </details>`;
        })
        .join('')}</div>`;
}

/**
 * @param {HTMLElement} root
 * @param {Array} asociaciones
 * @param {string} pagPrefix
 * @param {() => void} onRerender
 */
function resumenColumnasItem(item) {
    const r = item && item.resumen_columnas;
    if (r && typeof r === 'object' && filaTieneEstadisticaRenglon(r)) {
        return r;
    }
    const ec = item && item.estado_cuenta ? item.estado_cuenta : {};
    return {
        n_afiliacion: 0,
        n_anualidad: 0,
        n_carnet: 0,
        n_traspaso: 0,
        n_inscripcion: 0,
        monto_afiliacion_eur: 0,
        monto_anualidad_eur: 0,
        monto_carnet_eur: 0,
        monto_traspaso_eur: 0,
        monto_inscripcion_eur: 0,
        monto_participacion_eur: 0,
        monto_nomina_total_eur: ec.nomina_eur != null ? ec.nomina_eur : 0,
        deuda_eur: ec.deuda_eur != null ? ec.deuda_eur : 0,
        pagado_eur: ec.pagado_eur != null ? ec.pagado_eur : 0,
        saldo_eur: ec.saldo_eur != null ? ec.saldo_eur : 0,
        n_movimiento_torneo: 0,
    };
}

/**
 * @param {Array} asociaciones
 * @param {object|null} totales
 * @param {number} pag
 * @param {string} pagPrefix
 */
export function htmlReporteParticipacionColumnas(asociaciones, totales, pag, pagPrefix = 'rep-part') {
    const list = Array.isArray(asociaciones) ? asociaciones : [];
    const per = REPORTE_FILAS_POR_PAGINA;
    const pg = pag > 0 ? pag : 1;
    const slice = reporteSliceCliente(list, pg, per);
    const thStats = RENGLONES_ESTADISTICA.map((r) => htmlThEstadisticaRenglon(r)).join('');
    const pref = `${pagPrefix}-tab`;
    const pager = reporteHtmlPaginador(pref, pg, list.length, per);
    const tr = slice
        .map((item) => {
            const a = item.asociacion || {};
            const aid = a.id != null ? parseInt(String(a.id), 10) : 0;
            const nom = a.nombre ? String(a.nombre) : `Asociación ${aid}`;
            const r = resumenColumnasItem(item);
            const nav = { asocId: aid };
            const celdasStat = RENGLONES_ESTADISTICA.map((ren) =>
                htmlCeldaEstadisticaRenglon(r[ren.n], r[ren.monto], ren, nav)
            ).join('');
            const nPart = parseInt(String(r.n_inscripcion ?? 0), 10) || 0;
            const mPart = Math.round((Number(r.monto_participacion_eur ?? r.monto_inscripcion_eur) || 0) * 100) / 100;
            const nTor = item.n_torneos != null ? parseInt(String(item.n_torneos), 10) : (item.informes_por_torneo || []).length;
            return `<tr class="ag-rep-part-row" data-asoc-id="${aid}" role="button" tabindex="0" aria-expanded="false" title="Ver detalle por torneo (${nTor})">
            <td class="reporte-td-img">${reporteThumbImg(a.logo || '', nom)}</td>
            <td class="ag-rep-part-asoc"><strong>${reporteEsc(nom)}</strong><span class="ag-muted ag-rep-part-meta">${nTor} torneo(s)</span></td>
            ${celdasStat}
            <td class="ag-num ag-rep-part-col-part">
                <span class="ag-stat-qty">${reporteEsc(String(nPart))}</span>
                <span class="ag-stat-eur ag-rep-part-part-eur">${reporteEsc(mPart.toFixed(2))} €</span>
            </td>
            <td class="ag-num">${reporteEsc(String(Math.round((Number(r.monto_nomina_total_eur) || 0) * 100) / 100))} €</td>
            <td class="ag-num">${reporteEsc(String(r.deuda_eur ?? 0))} €</td>
            <td class="ag-num">${reporteEsc(String(r.pagado_eur ?? 0))} €</td>
            <td class="ag-num">${reporteEsc(String(r.saldo_eur ?? 0))} €</td>
            <td class="reporte-td-acciones" onclick="event.stopPropagation()">
                <div class="reporte-acciones">
                <a class="btn-secondary btn-sm reporte-btn-ic" href="${hrefInformeAsociacion(aid)}" title="Desglose por renglón" aria-label="Desglose"><span class="reporte-btn-ic-sym" aria-hidden="true">📊</span></a>
                <a class="btn-secondary btn-sm reporte-btn-ic" href="${hrefFinanzasAsociacion(aid)}" title="Estado de cuenta" aria-label="Cuenta"><span class="reporte-btn-ic-sym" aria-hidden="true">📋</span></a>
                </div>
            </td>
        </tr>`;
        })
        .join('');
    const tot = totales && typeof totales === 'object' ? totales : null;
    const tfootTot =
        tot && list.length > 0
            ? `<tfoot class="ag-stat-tfoot"><tr>
            <td colspan="2"><strong>Total general</strong></td>
            ${RENGLONES_ESTADISTICA.map((ren) => htmlCeldaEstadisticaRenglon(tot[ren.n], tot[ren.monto], ren)).join('')}
            <td class="ag-num ag-rep-part-col-part"><span class="ag-stat-qty">${reporteEsc(String(tot.n_inscripcion ?? 0))}</span>
                <span class="ag-stat-eur">${reporteEsc(String(Number(tot.monto_participacion_eur ?? 0).toFixed(2)))} €</span></td>
            <td class="ag-num">${reporteEsc(String(Number(tot.monto_nomina_total_eur ?? 0).toFixed(2)))} €</td>
            <td class="ag-num">${reporteEsc(String(tot.deuda_eur ?? 0))} €</td>
            <td class="ag-num">${reporteEsc(String(tot.pagado_eur ?? 0))} €</td>
            <td class="ag-num">${reporteEsc(String(tot.saldo_eur ?? 0))} €</td>
            <td></td>
            </tr></tfoot>`
            : '';
    return `${pager}
    <div class="reporte-vista ag-rep-part-tabla-wrap ag-fvd-reporte-datos">
    ${htmlFvdTableShell(`<table class="fvd-table admin-crud-table admin-crud-table--compact text-sm ag-rep-part-table" id="ag-rep-part-table">
        <caption class="ag-inf-table-caption">Totales de movimiento en todos los torneos. Seleccione una fila para el desglose por torneo.</caption>
        <thead>
        <tr class="ag-inf-thead-groups">
            <th colspan="2" class="ag-inf-th-grupo">Identificación</th>
            <th colspan="5" class="ag-inf-th-grupo ag-inf-th-grupo--qty">Estadísticas por renglón (cant. / €)</th>
            <th colspan="1" class="ag-inf-th-grupo ag-inf-th-grupo--part">Participación</th>
            <th colspan="4" class="ag-inf-th-grupo ag-inf-th-grupo--eur">Euros cuenta</th>
            <th class="ag-inf-th-grupo ag-inf-th-grupo--doc">Doc.</th>
        </tr>
        <tr>
            <th class="reporte-th-img" scope="col">Logo</th>
            <th scope="col">Asociación</th>
            ${thStats}
            <th class="ag-num ag-rep-part-th-part" scope="col" title="Inscripciones / participación (todos los torneos)">Participación total</th>
            <th class="ag-num" scope="col">Total nómina</th>
            <th class="ag-num" scope="col">Deuda</th>
            <th class="ag-num" scope="col">Pagado</th>
            <th class="ag-num" scope="col">Saldo</th>
            <th scope="col">Acciones</th>
        </tr>
        </thead>
        <tbody id="ag-rep-part-tbody">${list.length > 0 ? tr : '<tr><td colspan="13" class="ag-muted">Ninguna asociación con movimiento.</td></tr>'}</tbody>
        ${tfootTot}
    </table>`)}
    </div>
    <div id="ag-rep-part-detalle-wrap" class="ag-rep-part-detalle-wrap ag-fvd-reporte-datos" aria-live="polite">
        <p class="ag-muted ag-rep-part-detalle-placeholder">Seleccione una asociación en la tabla para ver el detalle por torneo.</p>
    </div>`;
}

/**
 * @param {object} item
 * @param {string} pagPrefix
 */
export function htmlPanelDetalleParticipacion(item, pagPrefix = 'rep-part') {
    const a = item.asociacion || {};
    const aid = a.id != null ? parseInt(String(a.id), 10) : 0;
    const nom = a.nombre ? String(a.nombre) : `Asociación ${aid}`;
    const bloques = item.informes_por_torneo || [];
    const ec = item.estado_cuenta || null;
    const r = resumenColumnasItem(item);
    const nPart = parseInt(String(r.n_inscripcion ?? 0), 10) || 0;
    const mPart = Math.round((Number(r.monto_participacion_eur ?? r.monto_inscripcion_eur) || 0) * 100) / 100;
    const prefAsoc = `${pagPrefix}-a${aid > 0 ? aid : 0}`;
    const accName = aid > 0 ? `rep-part-tor-${aid}` : 'rep-part-tor';
    const pieCuenta =
        ec != null
            ? `<div class="ag-fin-recibo-sum ag-fin-recibo-sum--fvd ag-fin-asoc-resumen-cuenta">
                <span><strong>Participación total</strong> ${reporteEsc(String(nPart))} · ${reporteEsc(mPart.toFixed(2))} €</span>
                <span><strong>Deuda</strong> ${reporteEsc(String(ec.deuda_eur ?? 0))} €</span>
                <span><strong>Nómina global</strong> ${reporteEsc(String(r.monto_nomina_total_eur ?? ec.nomina_eur ?? 0))} €</span>
                <span><strong>Pagado</strong> ${reporteEsc(String(ec.pagado_eur ?? 0))} €</span>
                <span><strong>Saldo</strong> ${reporteEsc(String(ec.saldo_eur ?? 0))} €</span>
            </div>`
            : '';
    return `<div class="ag-rep-part-detalle-panel" data-asoc-id="${aid}">
        <h3 class="ag-subtitle ag-rep-part-detalle-tit">${reporteEsc(nom)} — detalle por torneo</h3>
        ${pieCuenta}
        <p class="ag-muted ag-fin-hint-copy">Abra un torneo a la vez. Use <strong>Detalle</strong> en cada renglón para ver atletas.</p>
        ${htmlBloquesInformePorTorneoAcordeon(bloques, prefAsoc, { asocId: aid }, { acordeonName: accName, openFirst: bloques.length === 1 })}
        <p class="ag-fin-panel-det-links"><a class="btn-secondary btn-sm" href="${hrefInformeAsociacion(aid)}">Desglose por renglón</a>
        <a class="btn-secondary btn-sm" href="${hrefFinanzasAsociacion(aid)}">Estado de cuenta</a></p>
    </div>`;
}

/**
 * Una asociación seleccionada a la vez; al elegir otra se sustituye el panel de detalle.
 * @param {HTMLElement} root
 * @param {Array} asociaciones
 * @param {string} pagPrefix
 * @param {() => void} onRerender
 * @param {{ autoPrimera?: boolean }} [opts]
 * @returns {{ selectAsoc: (number) => void, selectedId: () => number }}
 */
export function wireReporteParticipacionSeleccionExclusiva(root, asociaciones, pagPrefix, onRerender, opts = {}) {
    const list = Array.isArray(asociaciones) ? asociaciones : [];
    let selectedId = 0;

    function findItem(aid) {
        return list.find((it) => {
            const a = it.asociacion || {};
            const id = a.id != null ? parseInt(String(a.id), 10) : 0;
            return id === aid;
        });
    }

    function renderPanel(aid) {
        const wrap = root.querySelector('#ag-rep-part-detalle-wrap');
        if (!wrap) return;
        const item = findItem(aid);
        if (!item || aid < 1) {
            wrap.innerHTML =
                '<p class="ag-muted ag-rep-part-detalle-placeholder">Seleccione una asociación en la tabla para ver el detalle por torneo.</p>';
            return;
        }
        wrap.innerHTML = htmlPanelDetalleParticipacion(item, pagPrefix);
        resetPaginasDetalleFinanza(pagPrefix);
        wirePaginacionReporteAsociacionesTorneo(wrap, [item], pagPrefix, onRerender);
        wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function setRowState(aid) {
        root.querySelectorAll('.ag-rep-part-row').forEach((row) => {
            const rid = parseInt(row.getAttribute('data-asoc-id') || '0', 10) || 0;
            const on = rid === aid && aid > 0;
            row.classList.toggle('ag-rep-part-row--active', on);
            row.setAttribute('aria-expanded', on ? 'true' : 'false');
        });
    }

    function selectAsoc(aid) {
        const id = parseInt(String(aid), 10) || 0;
        if (id < 1) {
            selectedId = 0;
            setRowState(0);
            renderPanel(0);
            return;
        }
        if (selectedId === id) {
            return;
        }
        selectedId = id;
        setRowState(id);
        renderPanel(id);
    }

    function onRowActivate(row) {
        const aid = parseInt(row.getAttribute('data-asoc-id') || '0', 10) || 0;
        if (aid > 0) {
            selectAsoc(aid);
        }
    }

    root.querySelectorAll('.ag-rep-part-row').forEach((row) => {
        row.addEventListener('click', () => onRowActivate(row));
        row.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                onRowActivate(row);
            }
        });
    });

    if (opts.autoPrimera && list.length === 1) {
        const a0 = list[0].asociacion || {};
        const id0 = a0.id != null ? parseInt(String(a0.id), 10) : 0;
        if (id0 > 0) {
            selectAsoc(id0);
        }
    }

    return {
        selectAsoc,
        selectedId: () => selectedId,
    };
}

export function wirePaginacionReporteAsociacionesTorneo(root, asociaciones, pagPrefix, onRerender) {
    (asociaciones || []).forEach((item, idx) => {
        const a = item.asociacion || {};
        const aid = a.id != null ? parseInt(String(a.id), 10) : 0;
        const prefAsoc = `${pagPrefix}-a${aid > 0 ? aid : idx}`;
        const fakeGrupos = [
            {
                torneos: (item.informes_por_torneo || []).map((b) => ({
                    torneo_activo: b.torneo_activo,
                    renglones: b.renglones || [],
                })),
            },
        ];
        wirePaginacionDetalleGrupos(root, fakeGrupos, prefAsoc, onRerender);
    });
}

export async function fetchFinanzaDetalleAsociacion(fetchJson, asocId, scope = {}) {
    const qs = new URLSearchParams({
        id: String(asocId),
        consolidar_campeonato: '1',
    });
    const tid = scope.torneoId > 0 ? scope.torneoId : 0;
    const gid = scope.grupoId > 0 ? scope.grupoId : 0;
    if (gid > 0) qs.set('grupo_evento_id', String(gid));
    else if (tid > 0) qs.set('torneo_id', String(tid));
    return fetchJson(`api/finanza_asociacion.php?${qs}`);
}

/**
 * Enlaza paginadores del detalle (acordeón).
 * @param {HTMLElement} root
 * @param {Array} grupos
 * @param {string} pagPrefix
 * @param {() => void} onRerender
 */
export function wirePaginacionDetalleGrupos(root, grupos, pagPrefix, onRerender) {
    const per = REPORTE_FILAS_POR_PAGINA;
    const pag = pagMap(pagPrefix);
    const domKey = (k) => String(k).replace(/[^a-zA-Z0-9]/g, '_');
    (grupos || []).forEach((g, gIdx) => {
        const gid = g.grupo_evento_id != null ? parseInt(String(g.grupo_evento_id), 10) : 0;
        const sk = gid > 0 ? `g${gid}` : `gc${gIdx}`;
        const ren = g.renglones_consolidados || [];
        const pref = `${pagPrefix}-${domKey(sk)}`;
        reporteLigarPaginador(
            pref,
            pag[sk] ?? 1,
            ren.length,
            (np) => {
                pag[sk] = np;
                onRerender();
            },
            per
        );
        (g.torneos || []).forEach((b, i) => {
            const tor = b.torneo_activo || null;
            const tid = tor && tor.id != null ? parseInt(String(tor.id), 10) : 0;
            const tsk = tid > 0 ? `t${tid}` : `i${i}`;
            const tren = b.renglones || [];
            const tpref = `${pagPrefix}-${domKey(tsk)}`;
            reporteLigarPaginador(
                tpref,
                pag[tsk] ?? 1,
                tren.length,
                (np) => {
                    pag[tsk] = np;
                    onRerender();
                },
                per
            );
        });
    });
}
