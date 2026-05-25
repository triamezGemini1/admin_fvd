/**
 * Paginación y acciones estándar para reportes y listados (máx. 10 filas por página).
 */

export const REPORTE_FILAS_POR_PAGINA = 10;

/** Contenedor estándar tabla FVD (14", sticky header, scroll interno). Ver .fvd-table-shell en main.css */
export const FVD_TABLE_SHELL_CLASS = 'fvd-table-shell';

/**
 * Envuelve HTML de tabla en el contenedor corporativo (overflow-x + max-h 70vh + thead sticky vía CSS).
 * @param {string} innerHtml — normalmente `<table class="fvd-table ...">...</table>`
 * @param {string} [extraClass]
 */
export function htmlFvdTableShell(innerHtml, extraClass = '') {
    const cls = extraClass ? `${FVD_TABLE_SHELL_CLASS} ${extraClass}` : FVD_TABLE_SHELL_CLASS;
    return `<div class="${cls}" role="region" aria-label="Tabla de datos">${innerHtml}</div>`;
}

/** Orden canónico de renglones en resúmenes por asociación. */
export const RENGLONES_ESTADISTICA = [
    { codigo: 'afiliacion', etiqueta: 'Afiliación', n: 'n_afiliacion', monto: 'monto_afiliacion_eur', estrecho: true },
    { codigo: 'anualidad', etiqueta: 'Anualidad', n: 'n_anualidad', monto: 'monto_anualidad_eur', estrecho: true },
    { codigo: 'carnet', etiqueta: 'Carnet', n: 'n_carnet', monto: 'monto_carnet_eur', estrecho: true },
    { codigo: 'traspaso', etiqueta: 'Traspaso', n: 'n_traspaso', monto: 'monto_traspaso_eur', estrecho: true },
    { codigo: 'inscripcion', etiqueta: 'Inscritos', n: 'n_inscripcion', monto: 'monto_inscripcion_eur', estrecho: false },
];

/**
 * @param {{ estrecho?: boolean }} renglon
 */
export function claseColumnaEstadisticaRenglon(renglon) {
    return renglon && renglon.estrecho ? ' ag-stat-col--qty4' : '';
}

/**
 * @param {{ etiqueta: string, estrecho?: boolean }} renglon
 */
export function htmlThEstadisticaRenglon(renglon) {
    const narrow = claseColumnaEstadisticaRenglon(renglon);
    return `<th class="ag-num ag-stat-th${narrow}" scope="col" title="${reporteEsc(renglon.etiqueta)}">${reporteEsc(renglon.etiqueta)}</th>`;
}

/**
 * Celda de tabla: cantidad + monto € del renglón.
 * @param {number|string} cantidad
 * @param {number|string} montoEur
 * @param {{ estrecho?: boolean, codigo?: string, etiqueta?: string }|null} [renglon]
 * @param {{ asocId?: number, torneoId?: number, grupoId?: number }|null} [nav]
 */
export function htmlCeldaEstadisticaRenglon(cantidad, montoEur, renglon = null, nav = null) {
    const n = parseInt(String(cantidad ?? 0), 10) || 0;
    const m = Math.round((Number(montoEur) || 0) * 100) / 100;
    const narrow = claseColumnaEstadisticaRenglon(renglon);
    const inner = `<span class="ag-stat-qty">${reporteEsc(String(n))}</span>
        <span class="ag-stat-eur">${reporteEsc(m.toFixed(2))} €</span>`;
    const asocId = nav && nav.asocId != null ? parseInt(String(nav.asocId), 10) : 0;
    const cod = renglon && renglon.codigo ? String(renglon.codigo) : '';
    if (asocId > 0 && cod && n > 0) {
        const torneoId = nav.torneoId > 0 ? nav.torneoId : 0;
        const grupoId = nav.grupoId > 0 ? nav.grupoId : 0;
        let q = '';
        if (grupoId > 0) {
            q = `&grupo_evento_id=${encodeURIComponent(String(grupoId))}&consolidar_campeonato=1`;
        } else if (torneoId > 0) {
            q = `&torneo_id=${encodeURIComponent(String(torneoId))}`;
        }
        const href = `informes.html?asoc=${encodeURIComponent(String(asocId))}&renglon=${encodeURIComponent(cod)}${q}`;
        const tit = renglon.etiqueta ? String(renglon.etiqueta) : cod;
        return `<td class="ag-num ag-stat-cell ag-stat-cell--link${narrow}">
        <a class="ag-stat-cell-link" href="${href}" title="Ver detalle: ${reporteEsc(tit)}">${inner}</a>
    </td>`;
    }
    return `<td class="ag-num ag-stat-cell${narrow}">${inner}</td>`;
}

/**
 * @param {object} fila
 */
export function filaTieneEstadisticaRenglon(fila) {
    if (!fila || typeof fila !== 'object') return false;
    return RENGLONES_ESTADISTICA.some((r) => (parseInt(String(fila[r.n] ?? 0), 10) || 0) > 0);
}

/**
 * Tarjetas resumen global de los 5 renglones.
 * @param {object|null} tot
 */
export function htmlResumenRenglonesCards(tot) {
    if (!tot || typeof tot !== 'object') return '';
    const cards = RENGLONES_ESTADISTICA.map((r) => {
        const n = parseInt(String(tot[r.n] ?? 0), 10) || 0;
        const m = Math.round((Number(tot[r.monto]) || 0) * 100) / 100;
        return `<div class="ag-stat-card">
            <span class="ag-stat-card-lbl">${reporteEsc(r.etiqueta)}</span>
            <span class="ag-stat-card-qty">${reporteEsc(String(n))}</span>
            <span class="ag-stat-card-eur">${reporteEsc(m.toFixed(2))} €</span>
        </div>`;
    }).join('');
    return `<div class="ag-stat-cards" role="group" aria-label="Totales por renglón en el torneo">${cards}</div>`;
}

export function reporteEsc(v) {
    if (v === null || v === undefined) return '';
    return String(v)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/** Miniatura en tablas de reporte (logo o foto). */
export function reporteThumbImg(url, alt = '') {
    const u = reporteEsc(String(url || '').trim());
    const t = reporteEsc(alt);
    if (!u) {
        return `<span class="reporte-thumb reporte-thumb--ph" title="${t}" aria-hidden="true"></span>`;
    }
    return `<img class="reporte-thumb" src="${u}" alt="" width="40" height="40" loading="lazy">`;
}

/**
 * @param {number} total
 * @param {number} pagina 1-based
 * @param {number} [porPagina]
 */
export function reporteMetaPagina(total, pagina, porPagina = REPORTE_FILAS_POR_PAGINA) {
    const t = Math.max(0, Math.floor(Number(total) || 0));
    const per = Math.max(1, Math.min(100, porPagina));
    const totalPaginas = Math.max(1, Math.ceil(t / per));
    const p = Math.min(Math.max(1, Math.floor(pagina) || 1), totalPaginas);
    const from = t === 0 ? 0 : (p - 1) * per + 1;
    const to = Math.min(p * per, t);
    return { totalPaginas, pagina: p, desde: from, hasta: to, total: t, porPagina: per };
}

/**
 * HTML del paginador (Anterior / texto / Siguiente).
 * @param {string} prefijo id único por listado (ej. tor, aso, usr)
 */
export function reporteHtmlPaginador(prefijo, pagina, total, porPagina = REPORTE_FILAS_POR_PAGINA) {
    const m = reporteMetaPagina(total, pagina, porPagina);
    return `<div class="reporte-pager admin-toolbar" role="navigation" aria-label="Paginación">
    <button type="button" class="btn-secondary btn-sm" id="${prefijo}-pg-ant"${m.pagina <= 1 ? ' disabled' : ''}>Anterior</button>
    <span class="reporte-pager-txt" id="${prefijo}-pg-txt">${m.desde}–${m.hasta} de ${m.total} · pág. ${m.pagina}/${m.totalPaginas}</span>
    <button type="button" class="btn-secondary btn-sm" id="${prefijo}-pg-sig"${m.pagina >= m.totalPaginas ? ' disabled' : ''}>Siguiente</button>
</div>`;
}

/**
 * @param {string} prefijo
 * @param {number} pagina actual
 * @param {number} total
 * @param {(nuevaPagina: number) => void} alCambiar
 * @param {number} [porPagina]
 */
export function reporteLigarPaginador(prefijo, pagina, total, alCambiar, porPagina = REPORTE_FILAS_POR_PAGINA) {
    document.getElementById(`${prefijo}-pg-ant`)?.addEventListener('click', () => {
        if (pagina > 1) alCambiar(pagina - 1);
    });
    document.getElementById(`${prefijo}-pg-sig`)?.addEventListener('click', () => {
        const m = reporteMetaPagina(total, pagina, porPagina);
        if (pagina < m.totalPaginas) alCambiar(pagina + 1);
    });
}

/**
 * Estatus binario clásico (p. ej. torneos u organización): 1 ↔ 0.
 * @param {number|string} actual
 */
export function reporteToggleEstatusBinario(actual) {
    const n = Number(actual);
    return n === 1 ? 0 : 1;
}

/**
 * FVD — Activo = 0, Inactivo = 9 (asociaciones, usuarios portal, informes/finanzas por asociación).
 * @param {number|string} actual
 */
export function reporteToggleEstatusActivoInactivoFvd(actual) {
    const n = Number(actual);
    return n === 9 ? 0 : 9;
}

/**
 * HTML de acciones por fila: Ver, Editar, Activar/Desactivar, opcional Eliminar.
 * @param {{ prefijo: string, id: string|number, campoEstado: string, valorEstado: number|string, puedeEscribir: boolean, soloVer?: boolean, iconos?: boolean }} o
 */
export function reporteHtmlAcciones(o) {
    const id = reporteEsc(o.id);
    const pref = reporteEsc(o.prefijo);
    const iconos = o.iconos !== false;
    const ic = (sym, label, btnClass, dataAttrs, disabled) => {
        const dis = disabled ? ' disabled' : '';
        const cls = btnClass || (iconos ? 'btn-secondary btn-sm reporte-btn-ic' : 'btn-secondary btn-sm');
        const lb = reporteEsc(label);
        if (iconos) {
            return `<button type="button" class="${cls}"${dis} title="${lb}" aria-label="${lb}"${dataAttrs}><span class="reporte-btn-ic-sym" aria-hidden="true">${sym}</span></button>`;
        }
        return `<button type="button" class="${cls}"${dis} title="${lb}"${dataAttrs}>${lb}</button>`;
    };
    if (o.soloVer) {
        return `<div class="reporte-acciones">${ic('👁', 'Ver', null, ` data-rp-act="ver" data-rp-id="${id}" data-rp-p="${pref}"`, false)}
            ${ic('✎', 'Editar (no disponible)', null, '', true)}
            ${ic('⏻', 'Activar / desactivar (no aplica)', null, '', true)}</div>`;
    }
    const st = Number(o.valorEstado);
    const pref09 = o.prefijo === 'aso' || o.prefijo === 'usr' || o.prefijo === 'inf-fin' || o.prefijo === 'fin-res';
    let lbl;
    if (pref09 && (o.campoEstado === 'status' || o.campoEstado === 'estatus')) {
        lbl = st === 9 ? 'Activar' : 'Desactivar';
    } else {
        lbl = o.campoEstado === 'status' && st === 9 ? 'Activar' : st === 1 ? 'Desactivar' : 'Activar';
    }
    const ver = ic('👁', 'Ver', null, ` data-rp-act="ver" data-rp-id="${id}" data-rp-p="${pref}"`, false);
    const ed = o.puedeEscribir
        ? ic('✎', 'Editar', null, ` data-rp-act="editar" data-rp-id="${id}" data-rp-p="${pref}"`, false)
        : '';
    const tg = o.puedeEscribir
        ? ic('⏻', lbl, null, ` data-rp-act="toggle" data-rp-id="${id}" data-rp-p="${pref}" data-rp-campo="${reporteEsc(o.campoEstado)}" data-rp-st="${st}"`, false)
        : '';
    return `<div class="reporte-acciones">${ver}${ed}${tg}</div>`;
}

/**
 * Delegación de clicks en contenedor (tabla o wrap).
 * @param {HTMLElement|null} contenedor
 * @param {string} prefijo
 * @param {{ onVer?: (id: string) => void, onEditar?: (id: string) => void, onToggle?: (id: string, campo: string, valorActual: number) => void }} handlers
 */
export function reporteDelegarAcciones(contenedor, prefijo, handlers) {
    if (!contenedor) return;
    if (contenedor._reporteAbort && typeof contenedor._reporteAbort.abort === 'function') {
        contenedor._reporteAbort.abort();
    }
    const ac = new AbortController();
    contenedor._reporteAbort = ac;
    contenedor.addEventListener(
        'click',
        (e) => {
            const t = /** @type {HTMLElement|null} */ (e.target instanceof HTMLElement ? e.target.closest('[data-rp-act]') : null);
            if (!t) return;
            if (t.getAttribute('data-rp-p') !== prefijo) return;
            const id = t.getAttribute('data-rp-id') || '';
            const act = t.getAttribute('data-rp-act') || '';
            if (act === 'ver' && handlers.onVer) handlers.onVer(id);
            else if (act === 'editar' && handlers.onEditar) handlers.onEditar(id);
            else if (act === 'toggle' && handlers.onToggle) {
                const campo = t.getAttribute('data-rp-campo') || 'estatus';
                const st = parseInt(t.getAttribute('data-rp-st') || '0', 10);
                handlers.onToggle(id, campo, st);
            }
        },
        { signal: ac.signal }
    );
}

/**
 * Paginación en cliente: devuelve tramo de array para la página actual.
 */
export function reporteSliceCliente(items, pagina, porPagina = REPORTE_FILAS_POR_PAGINA) {
    const arr = Array.isArray(items) ? items : [];
    const m = reporteMetaPagina(arr.length, pagina, porPagina);
    const start = (m.pagina - 1) * m.porPagina;
    return arr.slice(start, start + m.porPagina);
}
