/**
 * Rutas de navegación del reporte financiero (torneo → asociación → renglón → detalle).
 */

import { reporteEsc } from './reporte_tabla.js';

/**
 * @param {{ torneoId?: number, grupoId?: number }} [opts]
 */
export function qsTorneoGrupoFinanzas(opts = {}) {
    const torneoId = opts.torneoId > 0 ? opts.torneoId : 0;
    const grupoId = opts.grupoId > 0 ? opts.grupoId : 0;
    if (grupoId > 0) {
        return `&grupo_evento_id=${encodeURIComponent(String(grupoId))}&consolidar_campeonato=1`;
    }
    if (torneoId > 0) {
        return `&torneo_id=${encodeURIComponent(String(torneoId))}`;
    }
    return '';
}

/**
 * @param {{ torneoId?: number, grupoId?: number }} [opts]
 */
export function hrefReporteAsociacionesTorneo(opts = {}) {
    const asocId = opts.asocId > 0 ? opts.asocId : 0;
    if (asocId > 0) {
        return `informes.html?vista=asoc-torneos&asoc=${encodeURIComponent(String(asocId))}`;
    }
    return 'informes.html?vista=asoc-torneos';
}

/** Reporte en columnas (totales globales + detalle por torneo en acordeón exclusivo). */
export function hrefReporteParticipacion() {
    return 'reporte_participacion.html';
}

export function hrefInformeConsolidado(opts = {}) {
    const torneoId = opts.torneoId > 0 ? opts.torneoId : 0;
    const grupoId = opts.grupoId > 0 ? opts.grupoId : 0;
    if (grupoId > 0) {
        return `informes.html?grupo_evento_id=${encodeURIComponent(String(grupoId))}&consolidar_campeonato=1`;
    }
    if (torneoId > 0) {
        return `informes.html?torneo_id=${encodeURIComponent(String(torneoId))}`;
    }
    return 'informes.html';
}

/**
 * @param {number|string} asocId
 * @param {{ torneoId?: number, grupoId?: number }} [opts]
 */
export function hrefInformeAsociacion(asocId, opts = {}) {
    const id = parseInt(String(asocId), 10) || 0;
    if (id < 1) return 'informes.html';
    return `informes.html?asoc=${encodeURIComponent(String(id))}${qsTorneoGrupoFinanzas(opts)}`;
}

/**
 * @param {number|string} asocId
 * @param {string} codigo
 * @param {{ torneoId?: number, grupoId?: number }} [opts]
 */
export function hrefInformeRenglon(asocId, codigo, opts = {}) {
    const id = parseInt(String(asocId), 10) || 0;
    const cod = String(codigo || '').trim().toLowerCase();
    if (id < 1 || !cod) return hrefInformeAsociacion(id, opts);
    return `informes.html?asoc=${encodeURIComponent(String(id))}&renglon=${encodeURIComponent(cod)}${qsTorneoGrupoFinanzas(opts)}`;
}

/**
 * @param {number|string} asocId
 * @param {{ torneoId?: number, grupoId?: number }} [opts]
 */
export function hrefFinanzasAsociacion(asocId, opts = {}) {
    const id = parseInt(String(asocId), 10) || 0;
    if (id < 1) return 'finanzas_asociacion.html';
    return `finanzas_asociacion.html?id=${encodeURIComponent(String(id))}${qsTorneoGrupoFinanzas(opts)}`;
}

/**
 * @param {number|string} asocId
 * @param {string} codigo
 * @param {{ torneoId?: number, grupoId?: number }} [opts]
 * @param {{ iconos?: boolean, etiqueta?: string }} [ui]
 */
export function htmlLinkDetalleRenglon(asocId, codigo, opts = {}, ui = {}) {
    const cod = String(codigo || '').trim().toLowerCase();
    if (!cod || cod === 'total_cuenta' || cod === 'otros') {
        return '—';
    }
    const id = parseInt(String(asocId), 10) || 0;
    if (id < 1) return '—';
    const href = hrefInformeRenglon(id, cod, opts);
    const tit = ui.etiqueta ? String(ui.etiqueta) : cod;
    if (ui.iconos !== false) {
        return `<a class="btn-secondary btn-sm reporte-btn-ic ag-fin-renglon-link" href="${href}" title="Detalle: ${reporteEsc(tit)}" aria-label="Detalle ${reporteEsc(tit)}"><span class="reporte-btn-ic-sym" aria-hidden="true">🔍</span></a>`;
    }
    return `<a class="btn-secondary btn-sm ag-fin-renglon-link" href="${href}">Detalle</a>`;
}

/**
 * @param {{ asocId?: number, torneoId?: number, grupoId?: number }} nav
 */
export function navContextoFinanzas(nav) {
    const asocId = nav && nav.asocId != null ? parseInt(String(nav.asocId), 10) : 0;
    const torneoId = nav && nav.torneoId != null ? parseInt(String(nav.torneoId), 10) : 0;
    const grupoId = nav && nav.grupoId != null ? parseInt(String(nav.grupoId), 10) : 0;
    return {
        asocId: asocId > 0 ? asocId : 0,
        torneoId: torneoId > 0 ? torneoId : 0,
        grupoId: grupoId > 0 ? grupoId : 0,
    };
}
