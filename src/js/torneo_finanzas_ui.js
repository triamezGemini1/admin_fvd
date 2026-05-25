/**
 * Barra compartida: selector de torneo (nómina) + actualizar deudas desde movimiento_torneo.
 */

export const FVD_FIN_TORNEO_STORAGE = 'fvd_fin_torneo_id';

export function leerTorneoFinanzasGuardado() {
    try {
        const v = parseInt(localStorage.getItem(FVD_FIN_TORNEO_STORAGE) || '0', 10);
        return v > 0 ? v : 0;
    } catch (_) {
        return 0;
    }
}

export function guardarTorneoFinanzas(id) {
    try {
        if (id > 0) {
            localStorage.setItem(FVD_FIN_TORNEO_STORAGE, String(id));
        } else {
            localStorage.removeItem(FVD_FIN_TORNEO_STORAGE);
        }
    } catch (_) {}
}

export function torneoFinanzasQs(torneoId) {
    return torneoId > 0 ? `&torneo_id=${encodeURIComponent(String(torneoId))}` : '';
}

/**
 * @param {Record<string, unknown>|null} meta torneo_filtro | torneo_informe | torneo_activo
 */
export function metaTorneoFinanzas(meta) {
    if (meta == null || typeof meta !== 'object') {
        return { id: 0, nombre: '', fechator: null, finalizado_en: null, enCurso: false };
    }
    const id = meta.id != null ? parseInt(String(meta.id), 10) : parseInt(String(meta.torneo_id ?? '0'), 10) || 0;
    return {
        id,
        nombre: meta.nombre != null ? String(meta.nombre) : '',
        fechator: meta.fechator ?? null,
        finalizado_en: meta.finalizado_en ?? null,
        enCurso: !!meta.es_torneo_activo || !meta.finalizado_en,
    };
}

/**
 * Banner visible: nombre del torneo al que corresponden las cuentas del resumen.
 * @param {object} opts
 * @param {string} [opts.nombre]
 * @param {number} [opts.torneoId]
 * @param {string} [opts.alcance] línea secundaria (ej. asociación, vista general)
 * @param {boolean} [opts.multiple]
 */
export function htmlBannerTorneoCuentas(opts) {
    const o = opts || {};
    const multiple = !!o.multiple;
    const tid = Number(o.torneoId) > 0 ? Number(o.torneoId) : 0;
    const nomRaw = (o.nombre || '').trim();
    const nombre = multiple
        ? nomRaw || 'Varios torneos'
        : nomRaw || (tid > 0 ? `Torneo ${tid}` : 'Seleccione un torneo');
    const partes = [];
    if (!multiple && tid > 0) partes.push(`Referencia ${tid}`);
    if (o.fechator) partes.push(String(o.fechator).slice(0, 10));
    if (o.finalizado_en) partes.push('Torneo finalizado');
    else if (o.enCurso) partes.push('En curso');
    if (o.alcance) partes.push(String(o.alcance));
    const metaLine = partes.length > 0 ? partes.join(' · ') : 'Las cifras de este resumen corresponden al torneo indicado.';
    const kicker = multiple ? 'Resumen financiero — varios torneos' : 'Cuentas correspondientes al torneo';
    return `<div class="ag-fin-resumen-torneo" role="status" aria-live="polite">
        <p class="ag-fin-resumen-torneo-kicker">${kicker}</p>
        <p class="ag-fin-resumen-torneo-nombre">${nombre}</p>
        <p class="ag-fin-resumen-torneo-meta">${metaLine}</p>
    </div>`;
}

function escSel(v) {
    if (v === null || v === undefined) return '';
    return String(v)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Selector con optgroups: campeonatos (2 o 3 variantes) y torneos sueltos.
 * @param {object|null} estructurado { campeonatos, sueltos, torneos_plano }
 * @param {number} torneoSeleccionado
 * @param {{ requerirEleccion?: boolean, showCampeonatoConsolidado?: boolean, grupoSeleccionado?: number }} [opts]
 */
export function htmlOptionsTorneosAgrupado(estructurado, torneoSeleccionado, opts = {}) {
    const tidSel = torneoSeleccionado > 0 ? torneoSeleccionado : 0;
    const gidSel = opts.grupoSeleccionado > 0 ? opts.grupoSeleccionado : 0;
    const requerir = opts.requerirEleccion !== false;
    const showCampCons = !!opts.showCampeonatoConsolidado;
    let html = '';
    if (requerir) {
        html += `<option value=""${tidSel === 0 ? ' selected' : ''}>— Seleccione torneo a procesar —</option>`;
    }
    const camps = estructurado && Array.isArray(estructurado.campeonatos) ? estructurado.campeonatos : [];
    for (const g of camps) {
        const gid = g.grupo_evento_id != null ? String(g.grupo_evento_id) : '';
        const tit = g.etiqueta ? String(g.etiqueta) : `Campeonato #${gid}`;
        const modo =
            g.modo_campeonato === 'campeonato_genero'
                ? ' (por género)'
                : g.modo_campeonato === 'campeonato_categoria'
                  ? ' (por categoría)'
                  : '';
        html += `<optgroup label="Campeonato #${escSel(gid)} — ${escSel(tit)}${escSel(modo)}">`;
        if (showCampCons) {
            const selG = gidSel > 0 && String(gidSel) === gid ? ' selected' : '';
            html += `<option value="g-${escSel(gid)}"${selG}>${escSel(tit)} — cuenta consolidada</option>`;
        }
        const vars = Array.isArray(g.torneos) ? g.torneos : [];
        for (const v of vars) {
            const id = v.torneo_id != null ? parseInt(String(v.torneo_id), 10) : 0;
            if (id < 1) continue;
            const sel = tidSel === id ? ' selected' : '';
            const varLab = v.variante_etiqueta ? String(v.variante_etiqueta) : '';
            const nom = v.nombre ? String(v.nombre) : `Torneo ${id}`;
            const mov =
                v.filas_movimiento != null && Number(v.filas_movimiento) > 0
                    ? ` · ${v.filas_movimiento} mov.`
                    : '';
            html += `<option value="${id}"${sel} data-grupo-evento="${escSel(gid)}">${escSel(varLab)} — ${escSel(nom)} (#${id})${escSel(mov)}</option>`;
        }
        html += '</optgroup>';
    }
    const sueltos = estructurado && Array.isArray(estructurado.sueltos) ? estructurado.sueltos : [];
    if (sueltos.length > 0) {
        html += '<optgroup label="Torneos individuales">';
        for (const t of sueltos) {
            const id = t.torneo_id != null ? parseInt(String(t.torneo_id), 10) : 0;
            if (id < 1) continue;
            const sel = tidSel === id ? ' selected' : '';
            html += `<option value="${id}"${sel}>${escSel(etiquetaTorneoSelector(t))}</option>`;
        }
        html += '</optgroup>';
    }
    if (html === '' || (!requerir && tidSel === 0)) {
        const plano =
            estructurado && Array.isArray(estructurado.torneos_plano) ? estructurado.torneos_plano : [];
        for (const t of plano) {
            const id = t.torneo_id != null ? parseInt(String(t.torneo_id), 10) : 0;
            if (id < 1) continue;
            const sel = tidSel === id ? ' selected' : '';
            html += `<option value="${id}"${sel}>${escSel(etiquetaTorneoSelector(t))}</option>`;
        }
    }
    return html;
}

export function etiquetaTorneoSelector(t) {
    const nom = t.nombre ? String(t.nombre) : '';
    const tid = t.torneo_id != null ? parseInt(String(t.torneo_id), 10) : 0;
    const base = nom.trim() !== '' ? nom : `Torneo ${tid}`;
    const mov =
        t.filas_movimiento != null && Number(t.filas_movimiento) > 0
            ? ` (${String(t.filas_movimiento)} mov.)`
            : '';
    const fin = t.finalizado_en ? ' · cerrado' : t.es_torneo_activo ? ' · en curso' : '';
    return `${base}${mov}${fin}`;
}

/**
 * @param {object} opts
 * @param {string} opts.prefix id prefix for DOM
 * @param {Array} opts.torneos
 * @param {number} opts.torneoSeleccionado
 * @param {boolean} [opts.showActualizar]
 * @param {boolean} [opts.showConsolidar]
 * @param {boolean} [opts.consolidarChecked]
 * @param {boolean} [opts.showOpcionGeneral]
 * @param {boolean} [opts.requerirTorneo]
 * @param {object|null} [opts.torneosEstructurado]
 * @param {boolean} [opts.showCampeonatoConsolidado]
 * @param {number} [opts.grupoSeleccionado]
 * @param {string} [opts.leadHtml]
 */
export function htmlBarraTorneoFinanzas(opts) {
    const prefix = opts.prefix || 'tf';
    const torneos = Array.isArray(opts.torneos) ? opts.torneos : [];
    const tidSel = opts.torneoSeleccionado > 0 ? opts.torneoSeleccionado : 0;
    const showAct = opts.showActualizar !== false;
    const showCons = !!opts.showConsolidar;
    const consOn = !!opts.consolidarChecked;
    const showGen = !!opts.showOpcionGeneral;

    const estruct = opts.torneosEstructurado || null;
    const requerirTor = !!opts.requerirTorneo && !showGen && !showCons;
    let optHtml = '';
    const nCamp = estruct && Array.isArray(estruct.campeonatos) ? estruct.campeonatos.length : 0;
    const nSuel = estruct && Array.isArray(estruct.sueltos) ? estruct.sueltos.length : 0;
    if (estruct && (nCamp > 0 || nSuel > 0)) {
        optHtml = htmlOptionsTorneosAgrupado(estruct, consOn ? 0 : tidSel, {
            requerirEleccion: requerirTor && !consOn,
            showCampeonatoConsolidado: !!opts.showCampeonatoConsolidado,
            grupoSeleccionado: opts.grupoSeleccionado > 0 ? opts.grupoSeleccionado : 0,
        });
    } else {
        if (showCons) {
            optHtml += `<option value=""${tidSel === 0 && !consOn ? ' selected' : ''}>Torneo en curso (activo)</option>`;
        } else if (showGen) {
            optHtml += `<option value=""${tidSel === 0 ? ' selected' : ''}>Todos los torneos (vista general)</option>`;
        } else if (requerirTor) {
            optHtml += `<option value=""${tidSel === 0 ? ' selected' : ''}>— Seleccione torneo a procesar —</option>`;
        }
        for (const t of torneos) {
            const id = t.torneo_id != null ? parseInt(String(t.torneo_id), 10) : 0;
            if (id < 1) continue;
            const sel = !consOn && tidSel === id ? ' selected' : '';
            optHtml += `<option value="${id}"${sel}>${etiquetaTorneoSelector(t)}</option>`;
        }
    }

    const actBtn = showAct
        ? `<button type="button" class="btn-primary btn-sm" id="${prefix}-btn-deuda">Actualizar deudas</button>
           <span id="${prefix}-deuda-fb" class="ag-muted" role="status"></span>`
        : '';

    const consHtml = showCons
        ? `<label class="ag-fin-otros-check">
            <input type="checkbox" id="${prefix}-consolidar" ${consOn ? 'checked' : ''} />
            <span>Consolidar por torneo</span>
           </label>`
        : '';

    const lead = opts.leadHtml ? String(opts.leadHtml) : '';

    return `<div class="ag-torneo-fin-bar fvd-form fvd-form--inline" role="region" aria-label="Torneo y actualización de deudas">
        <label class="ag-fin-otros-field">Torneo (nómina)
            <select id="${prefix}-sel-torneo" class="ag-fin-input" ${consOn ? 'disabled' : ''}>${optHtml}</select>
        </label>
        ${consHtml}
        ${actBtn}
    </div>${lead}`;
}

/**
 * @param {HTMLElement|null} root
 * @param {object} handlers
 * @param {(torneoId: number) => void} [handlers.onTorneoChange]
 * @param {() => void|Promise<void>} [handlers.onActualizar]
 * @param {(checked: boolean) => void} [handlers.onConsolidarChange]
 */
export function wireBarraTorneoFinanzas(root, prefix, handlers = {}) {
    if (!root) return;
    const sel = root.querySelector(`#${prefix}-sel-torneo`);
    const btn = root.querySelector(`#${prefix}-btn-deuda`);
    const cons = root.querySelector(`#${prefix}-consolidar`);

    sel?.addEventListener('change', () => {
        const v = parseInt(String(/** @type {HTMLSelectElement} */ (sel).value || '0'), 10) || 0;
        guardarTorneoFinanzas(v);
        handlers.onTorneoChange?.(v);
    });

    cons?.addEventListener('change', () => {
        handlers.onConsolidarChange?.(!!/** @type {HTMLInputElement} */ (cons).checked);
    });

    btn?.addEventListener('click', () => {
        void handlers.onActualizar?.();
    });
}

export async function postActualizarDeudas(fetchJson, torneoId) {
    const body = torneoId > 0 ? { torneo_id: torneoId } : {};
    return fetchJson('api/informe_actualizar_deudas.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
}
