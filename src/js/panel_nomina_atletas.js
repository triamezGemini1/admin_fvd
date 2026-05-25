/**
 * Panel admin. gral. — Generar movimiento_torneo desde atletas.
 * Exige elegir torneo (o variante de campeonato); filtra atletas por género/categoría del torneo.
 */

import {
    reporteEsc,
    htmlResumenRenglonesCards,
    RENGLONES_ESTADISTICA,
} from './reporte_tabla.js';
import { htmlOptionsTorneosAgrupado } from './torneo_finanzas_ui.js';

const FVD_NOM_TORNEO_STORAGE = 'fvd_nom_torneo_id';

function esc(v) {
    return reporteEsc(v);
}

function guardarTorneoNomina(id) {
    try {
        if (id > 0) sessionStorage.setItem(FVD_NOM_TORNEO_STORAGE, String(id));
        else sessionStorage.removeItem(FVD_NOM_TORNEO_STORAGE);
    } catch (_) {}
}

function leerTorneoNomina() {
    try {
        const v = parseInt(sessionStorage.getItem(FVD_NOM_TORNEO_STORAGE) || '0', 10);
        return v > 0 ? v : 0;
    } catch (_) {
        return 0;
    }
}

function limpiarTorneoNomina() {
    guardarTorneoNomina(0);
}

function htmlRenglonesTabla(renglones, montos, titulo) {
    const rows = RENGLONES_ESTADISTICA.map((r) => {
        const n = renglones && renglones[r.n] != null ? parseInt(String(renglones[r.n]), 10) || 0 : 0;
        const m =
            montos && montos[r.monto] != null
                ? Math.round((Number(montos[r.monto]) || 0) * 100) / 100
                : 0;
        return `<tr>
            <td><strong>${esc(r.etiqueta)}</strong></td>
            <td class="ag-num">${esc(String(n))}</td>
            <td class="ag-num">${esc(m.toFixed(2))} €</td>
        </tr>`;
    }).join('');
    return `<div class="ag-nom-bloque">
        <h4 class="ag-nom-bloque-tit">${esc(titulo)}</h4>
        <table class="fvd-table admin-crud-table admin-crud-table--compact text-sm ag-nom-tabla-ren">
            <thead><tr><th>Renglón</th><th class="ag-num">Cantidad</th><th class="ag-num">Monto €</th></tr></thead>
            <tbody>${rows}</tbody>
        </table>
    </div>`;
}

function htmlBannerTorneoProceso(ev) {
    const tor = ev?.torneo || ev?.meta_procesamiento || null;
    if (!tor) return '';
    const tid = tor.torneo_id != null ? parseInt(String(tor.torneo_id), 10) : 0;
    const nom = tor.nombre ? String(tor.nombre) : '';
    const varLab = tor.variante_etiqueta ? String(tor.variante_etiqueta) : '';
    const gid = tor.grupo_evento_id != null ? String(tor.grupo_evento_id) : '';
    const partes = [`<strong>Torneo #${esc(String(tid))}</strong> — ${esc(nom)}`];
    if (varLab) partes.push(`Variante: <strong>${esc(varLab)}</strong>`);
    if (gid) partes.push(`Campeonato grupo <strong>#${esc(gid)}</strong>`);
    return `<div class="ag-fin-resumen-torneo ag-nom-torneo-banner" role="status">
        <p class="ag-fin-resumen-torneo-kicker">Procesando nómina para</p>
        <p class="ag-fin-resumen-torneo-nombre">${partes.join(' · ')}</p>
        <p class="ag-muted ag-fin-resumen-torneo-meta">Solo atletas que correspondan a este torneo (género o categoría) generarán <code>movimiento_torneo</code>.</p>
    </div>`;
}

function renderResumenRegeneracion(resumen, resultado) {
    if (!resumen && !resultado) return '';
    const r = resumen || {};
    const f1 = r.fase_1_nomina_movimiento_torneo || r.fase_1_nomina || resultado || {};
    const f2 = r.fase_2_cuentas_asociaciones || r.fase_2_cuentas || null;
    const val = r.validacion || {};
    const cmp = val.renglones_por_comparacion || {};
    const filasCmp = RENGLONES_ESTADISTICA.map((ren) => {
        const c = cmp[ren.n] || {};
        const ok = c.coincide_volcado_vs_movimiento ? 'ok' : 'error';
        return `<tr>
            <td><strong>${esc(ren.etiqueta)}</strong></td>
            <td class="ag-num">${esc(String(c.atletas_tabla_referencia ?? '—'))}</td>
            <td class="ag-num">${esc(String(c.nomina_volcada_torneo ?? '—'))}</td>
            <td class="ag-num">${esc(String(c.movimiento_torneo ?? '—'))}</td>
            <td class="${ok}">${c.coincide_volcado_vs_movimiento ? '✓' : '≠'}</td>
        </tr>`;
    }).join('');
    const durTotal = r.duracion_total_ms != null ? esc(String(r.duracion_total_ms)) + ' ms' : '—';
    const durF1 = f1.duracion_ms != null ? esc(String(f1.duracion_ms)) + ' ms' : '—';
    const durF2 = f2 && f2.duracion_ms != null ? esc(String(f2.duracion_ms)) + ' ms' : '—';
    const cuentaGenEur =
        f2 && f2.cuenta_general_campeonato
            ? f2.cuenta_general_campeonato.nomina_eur
            : f2 && f2.cuenta_general_torneo
              ? f2.cuenta_general_torneo.nomina_eur ?? f2.deuda_torneo_total_eur
              : f2
                ? f2.deuda_torneo_total_eur ?? f2.deuda_total_eur
                : null;
    const cuentaGen =
        cuentaGenEur != null
            ? `<p><strong>Cuenta general (nómina €):</strong> ${esc(String(cuentaGenEur))}</p>`
            : '';
    const valOk = val.coincide_renglones_nomina_vs_movimiento;
    const avisoVal = valOk
        ? '<p class="ok">Renglones volcados coinciden con agregados en movimiento_torneo.</p>'
        : '<p class="error">Hay diferencias entre renglones volcados y movimiento_torneo; revise el detalle.</p>';
    return `<div class="ag-nom-resumen-regen">
        <h3 class="ag-subtitle ag-fin-section-title">Resumen de regeneración</h3>
        <p class="ag-muted">${esc(r.criterio_filas_atletas || '')}</p>
        <p><strong>Duración total:</strong> ${durTotal} (fase 1: ${durF1}${f2 ? ` · fase 2: ${durF2}` : ''})</p>
        <ul class="ag-nom-stats-list">
            <li>Eliminados: <strong>${esc(String(f1.movimiento_eliminados ?? 0))}</strong></li>
            <li>Insertados: <strong>${esc(String(f1.movimiento_insertados ?? 0))}</strong></li>
            <li>Procesados (torneo): <strong>${esc(String(f1.procesados ?? 0))}</strong></li>
            <li>Deudas recalculadas: <strong>${esc(String(f2 ? f2.asociaciones_recalculadas ?? 0 : 0))}</strong> asoc.</li>
        </ul>
        ${cuentaGen}
        ${avisoVal}
        <table class="fvd-table admin-crud-table admin-crud-table--compact text-sm ag-nom-tabla-ren">
            <thead><tr>
                <th>Renglón</th>
                <th class="ag-num">Atletas (ref.)</th>
                <th class="ag-num">Nómina torneo</th>
                <th class="ag-num">movimiento_torneo</th>
                <th>OK</th>
            </tr></thead>
            <tbody>${filasCmp}</tbody>
        </table>
        <p class="ag-muted ag-nom-nota-val">${esc(val.nota_torneo || '')}</p>
    </div>`;
}

function renderEvaluacionNomina(ev) {
    if (!ev) return '<p class="ag-muted">Sin datos de evaluación.</p>';
    const atl = ev.atletas || {};
    const ex = ev.movimiento_torneo_existente || {};
    const sim = ev.simulacion_sin_borrar || {};
    const bloq = ev.bloqueo || {};
    const desc = atl.descartados || {};
    let avisoBloq = '';
    if (bloq.bloqueado) {
        avisoBloq = `<p class="error ag-nom-aviso">Torneo bloqueado: ${esc(bloq.motivo || '')}. Puede marcar «Forzar» al regenerar.</p>`;
    }
    const cards = htmlResumenRenglonesCards(ev.totales_renglones || null);
    const noTor = desc.no_coincide_torneo != null ? parseInt(String(desc.no_coincide_torneo), 10) : 0;
    return `${htmlBannerTorneoProceso(ev)}
        ${avisoBloq}
        <div class="ag-nom-resumen-grid">
            <p><strong>Atletas leídos (cédula única):</strong> ${esc(String(atl.leidos_canon ?? 0))}</p>
            <p><strong>Procesables para este torneo:</strong> ${esc(String(atl.procesables ?? 0))}</p>
            <p><strong>Descartados:</strong> sin usuario ${esc(String(desc.sin_usuario ?? 0))},
                sin asociación ${esc(String(desc.sin_asociacion ?? 0))},
                cédula vacía ${esc(String(desc.cedula_vacia ?? 0))},
                no corresponden al torneo ${esc(String(noTor))}</p>
            <p><strong>movimiento_torneo actual en torneo:</strong> ${esc(String(ex.filas ?? 0))} filas
                (se eliminarán antes de regenerar)</p>
            <p class="ag-muted ag-nom-sim">Simulación sin borrar: ${esc(String(sim.movimiento_insertados ?? 0))} altas,
                ${esc(String(sim.movimiento_actualizados ?? 0))} actualizaciones,
                ${esc(String(sim.movimiento_sin_cambio ?? 0))} sin cambio.</p>
        </div>
        ${cards}
        <div class="ag-nom-ren-grid">
            ${htmlRenglonesTabla(atl.renglones, atl.montos_eur, 'Fuente: atletas (filtrado por torneo)')}
            ${htmlRenglonesTabla(ex.renglones, null, 'Nómina actual en el torneo (antes de borrar)')}
        </div>`;
}

/**
 * @param {number} tidSel
 * @param {object|null} estructurado
 * @param {'campeonato_genero'|'campeonato_categoria'} modo
 */
function campeonatoGrupoDesdeTorneo(tidSel, estructurado, modo) {
    if (tidSel < 1 || !estructurado || !Array.isArray(estructurado.campeonatos)) {
        return null;
    }
    for (const g of estructurado.campeonatos) {
        if (g.modo_campeonato !== modo) continue;
        const vars = Array.isArray(g.torneos) ? g.torneos : [];
        for (const v of vars) {
            const id = v.torneo_id != null ? parseInt(String(v.torneo_id), 10) : 0;
            if (id === tidSel) {
                return {
                    grupo_evento_id: parseInt(String(g.grupo_evento_id ?? '0'), 10) || 0,
                    etiqueta: g.etiqueta ? String(g.etiqueta) : '',
                    modo_campeonato: modo,
                };
            }
        }
    }
    return null;
}

function campeonatoGeneroDesdeTorneo(tidSel, estructurado) {
    return campeonatoGrupoDesdeTorneo(tidSel, estructurado, 'campeonato_genero');
}

function campeonatoCategoriaDesdeTorneo(tidSel, estructurado) {
    return campeonatoGrupoDesdeTorneo(tidSel, estructurado, 'campeonato_categoria');
}

function renderResumenRegeneracionGrupo(resumen) {
    if (!resumen) return '';
    const base = renderResumenRegeneracion(resumen, resumen.fase_1_nomina || null);
    const val = resumen.validacion || {};
    const porTorneo = val.por_torneo || {};
    const items = Object.entries(porTorneo)
        .map(([tid, v]) => {
            const ok = v.coincide_renglones && v.coincide_filas;
            return `<li>Torneo #${esc(tid)}: <strong>${esc(String(v.procesados ?? 0))}</strong> procesados,
                <strong>${esc(String(v.filas_movimiento_torneo ?? 0))}</strong> filas MT
                <span class="${ok ? 'ok' : 'error'}">${ok ? ' ✓' : ' ≠'}</span></li>`;
        })
        .join('');
    const valOk = val.coincide_todos_los_torneos;
    return `${base}
        <h4 class="ag-nom-bloque-tit">Validación por torneo del campeonato</h4>
        <ul class="ag-nom-stats-list">${items || '<li class="ag-muted">Sin detalle</li>'}</ul>
        ${valOk ? '<p class="ok">Todos los torneos del campeonato validados.</p>' : '<p class="error">Revise torneos con discrepancias.</p>'}`;
}

function renderEvaluacionCampeonatoCategoria(evCamp) {
    if (!evCamp) return '<p class="ag-muted">Sin evaluación de campeonato.</p>';
    const dist = evCamp.distribucion_automatica || {};
    const gid = evCamp.grupo_evento_id != null ? String(evCamp.grupo_evento_id) : '';
    const map = evCamp.torneos_por_categoria || dist.torneos_por_categoria || {};
    const asig = dist.asignados_por_categoria || {};
    const torneosTxt = [12, 15, 18]
        .map((lim) => {
            const tid = map[lim] != null ? String(map[lim]) : '—';
            const n = asig[lim] != null ? String(asig[lim]) : '0';
            return `Sub ${lim} (#${tid}): ${n}`;
        })
        .join(' · ');
    let html = `<div class="ag-fin-resumen-torneo ag-nom-torneo-banner" role="status">
        <p class="ag-fin-resumen-torneo-kicker">Campeonato por categoría — asignación automática</p>
        <p class="ag-fin-resumen-torneo-nombre">Grupo <strong>#${esc(gid)}</strong></p>
        <p class="ag-muted ag-fin-resumen-torneo-meta">${esc(torneosTxt)}</p>
        <p class="ag-muted">Sin categoría aplicable: ${esc(String(dist.sin_categoria_aplicable ?? 0))}</p>
    </div>`;
    const porCat = evCamp.por_categoria || {};
    for (const key of Object.keys(porCat)) {
        html += `<h3 class="ag-subtitle ag-fin-section-title">Detalle — ${esc(key)}</h3>${renderEvaluacionNomina(porCat[key])}`;
    }
    return html;
}

function renderEvaluacionCampeonatoGenero(evCamp) {
    if (!evCamp) return '<p class="ag-muted">Sin evaluación de campeonato.</p>';
    const dist = evCamp.distribucion_automatica || {};
    const gid = evCamp.grupo_evento_id != null ? String(evCamp.grupo_evento_id) : '';
    const map = evCamp.torneos_por_sexo || dist.torneos_por_sexo || {};
    const tidM = map[1] != null ? String(map[1]) : '—';
    const tidF = map[2] != null ? String(map[2]) : '—';
    const desc = dist.descartados || {};
    let html = `<div class="ag-fin-resumen-torneo ag-nom-torneo-banner" role="status">
        <p class="ag-fin-resumen-torneo-kicker">Campeonato por género — asignación automática</p>
        <p class="ag-fin-resumen-torneo-nombre">Grupo <strong>#${esc(gid)}</strong> · Torneo M <strong>#${esc(tidM)}</strong> · Torneo F <strong>#${esc(tidF)}</strong></p>
        <p class="ag-muted ag-fin-resumen-torneo-meta">Cada atleta se asigna al torneo según sexo (usuario/atleta). Las estadísticas de participación quedan separadas por modalidad.</p>
    </div>
    <div class="ag-nom-resumen-grid">
        <p><strong>Asignación automática (simulación):</strong> masculino ${esc(String(dist.asignados_masculino ?? 0))},
            femenino ${esc(String(dist.asignados_femenino ?? 0))},
            sin sexo definido ${esc(String(dist.sin_sexo_definido ?? 0))}</p>
        <p><strong>Descartados:</strong> sin usuario ${esc(String(desc.sin_usuario ?? 0))},
            sin asociación ${esc(String(desc.sin_asociacion ?? 0))},
            cédula vacía ${esc(String(desc.cedula_vacia ?? 0))},
            no aplican ${esc(String(desc.no_aplica_filtro_torneo ?? 0))}</p>
        <p class="ag-muted ag-nom-sim">Simulación: ${esc(String(dist.movimiento_insertados ?? 0))} altas,
            ${esc(String(dist.movimiento_actualizados ?? 0))} actualizaciones,
            ${esc(String(dist.movimiento_sin_cambio ?? 0))} sin cambio.</p>
    </div>`;
    if (evCamp.masculino) {
        html += `<h3 class="ag-subtitle ag-fin-section-title">Detalle — Masculino</h3>${renderEvaluacionNomina(evCamp.masculino)}`;
    }
    if (evCamp.femenino) {
        html += `<h3 class="ag-subtitle ag-fin-section-title">Detalle — Femenino</h3>${renderEvaluacionNomina(evCamp.femenino)}`;
    }
    return html;
}

/**
 * @param {HTMLElement} el
 * @param {(url: string, options?: object) => Promise<{res: Response, data: object}>} fetchJson
 */
export async function loadNominaDesdeAtletasPanel(el, fetchJson) {
    if (!el) return;
    limpiarTorneoNomina();
    el.innerHTML = '<p class="ag-muted">Cargando…</p>';

    let torneosEstruct = null;
    let tidSel = 0;
    let lastEval = null;
    let lastEvalCampGenero = null;
    let lastEvalCampCategoria = null;

    const paint = () => {
        const campGen = campeonatoGeneroDesdeTorneo(tidSel, torneosEstruct);
        const campCat = campeonatoCategoriaDesdeTorneo(tidSel, torneosEstruct);
        const opt =
            torneosEstruct != null
                ? htmlOptionsTorneosAgrupado(torneosEstruct, tidSel, { requerirEleccion: true })
                : '<option value="">— Cargando torneos —</option>';
        let evalHtml = '<p class="ag-muted ag-nom-pendiente">Seleccione un torneo del campeonato y pulse evaluar.</p>';
        if (lastEvalCampGenero) {
            evalHtml = renderEvaluacionCampeonatoGenero(lastEvalCampGenero);
        } else if (lastEvalCampCategoria) {
            evalHtml = renderEvaluacionCampeonatoCategoria(lastEvalCampCategoria);
        } else if (lastEval) {
            evalHtml = renderEvaluacionNomina(lastEval);
        }
        const puedeRegUnTorneo = tidSel > 0 && lastEval != null && !lastEvalCampGenero && !lastEvalCampCategoria;
        const avisoCamp =
            campGen || campCat
                ? `<p class="ag-fin-aviso-torneo ag-muted" role="status">Campeonato <strong>${esc(
                      (campGen || campCat).etiqueta || '#' + (campGen || campCat).grupo_evento_id
                  )}</strong>: use los botones de <strong>campeonato completo</strong> (misma lógica: fase 1 nómina, fase 2 cuentas).</p>`
                : '';
        el.innerHTML = `<div class="ag-fin-shell ag-nom-shell">
            <p class="ag-muted ag-nom-lead">Proceso homologado: <strong>fase 1</strong> genera <code>movimiento_torneo</code> solo con atletas con indicadores activos;
            <strong>fase 2</strong> actualiza cuentas por asociación y total del torneo/campeonato.</p>
            ${avisoCamp}
            <div class="ag-nom-bar fvd-form fvd-form--inline">
                <label class="ag-nom-torneo-lbl">Torneo a procesar *
                    <select id="ag-nom-torneo" class="ag-fin-input ag-nom-torneo-sel" required>${opt}</select>
                </label>
                <button type="button" class="btn-secondary" id="ag-nom-eval" ${tidSel < 1 ? 'disabled' : ''}>1. Evaluar (un torneo)</button>
                <button type="button" class="btn-primary" id="ag-nom-run" ${puedeRegUnTorneo ? '' : 'disabled'}>2. Regenerar (un torneo)</button>
                ${
                    campGen
                        ? `<button type="button" class="btn-secondary" id="ag-nom-eval-gen" ${tidSel < 1 ? 'disabled' : ''}>Evaluar M+F</button>
                <button type="button" class="btn-primary" id="ag-nom-run-gen" ${lastEvalCampGenero ? '' : 'disabled'}>Regenerar M+F</button>`
                        : ''
                }
                ${
                    campCat
                        ? `<button type="button" class="btn-secondary" id="ag-nom-eval-cat" ${tidSel < 1 ? 'disabled' : ''}>Evaluar Sub 12/15/18</button>
                <button type="button" class="btn-primary" id="ag-nom-run-cat" ${lastEvalCampCategoria ? '' : 'disabled'}>Regenerar Sub 12/15/18</button>`
                        : ''
                }
                <label class="ag-nom-check"><input type="checkbox" id="ag-nom-force" /> Forzar (torneo cerrado)</label>
            </div>
            <div id="ag-nom-out" class="ag-nom-out">${evalHtml}</div>
        </div>`;
        const sel = el.querySelector('#ag-nom-torneo');
        sel?.addEventListener('change', (e) => {
            tidSel = parseInt(String(e.target.value), 10) || 0;
            guardarTorneoNomina(tidSel);
            lastEval = null;
            lastEvalCampGenero = null;
            lastEvalCampCategoria = null;
            paint();
        });
        el.querySelector('#ag-nom-eval')?.addEventListener('click', () => void doEval());
        el.querySelector('#ag-nom-run')?.addEventListener('click', () => void doRegenerar());
        el.querySelector('#ag-nom-eval-gen')?.addEventListener('click', () => void doEvalCampeonatoGenero());
        el.querySelector('#ag-nom-run-gen')?.addEventListener('click', () => void doRegenerarCampeonatoGenero());
        el.querySelector('#ag-nom-eval-cat')?.addEventListener('click', () => void doEvalCampeonatoCategoria());
        el.querySelector('#ag-nom-run-cat')?.addEventListener('click', () => void doRegenerarCampeonatoCategoria());
    };

    const doEval = async () => {
        if (tidSel < 1) {
            window.alert('Seleccione el torneo a procesar (variante del campeonato si aplica).');
            return;
        }
        const out = el.querySelector('#ag-nom-out');
        const btnEval = el.querySelector('#ag-nom-eval');
        if (out) {
            out.innerHTML =
                '<p class="ag-muted">Evaluando atletas…</p><p class="ag-muted ag-nom-eval-hint">Puede tardar unos segundos si hay muchos registros.</p>';
        }
        if (btnEval) btnEval.disabled = true;
        try {
            const r = await fetchJson(
                `api/carga_movimiento_torneo.php?torneo_id=${encodeURIComponent(String(tidSel))}`
            );
            if (!r.res.ok || !r.data.ok) {
                const msg =
                    r.data.message ||
                    (r.res.status === 504 || r.res.status === 0
                        ? 'La evaluación tardó demasiado (timeout). Intente de nuevo; si persiste, contacte al administrador.'
                        : `Error al evaluar (HTTP ${r.res.status}).`);
                if (out) out.innerHTML = `<p class="error">${esc(msg)}</p>`;
                return;
            }
            lastEval = r.data.evaluacion || null;
            lastEvalCampGenero = null;
            lastEvalCampCategoria = null;
            paint();
        } catch (err) {
            const msg =
                err && err.name === 'TypeError'
                    ? 'No se pudo conectar con el servidor. Compruebe que Apache/PHP sigue activo.'
                    : 'Error de red al evaluar.';
            if (out) out.innerHTML = `<p class="error">${esc(msg)}</p>`;
        } finally {
            const b = el.querySelector('#ag-nom-eval');
            if (b) b.disabled = tidSel < 1;
        }
    };

    const doEvalCampeonatoGenero = async () => {
        const camp = campeonatoGeneroDesdeTorneo(tidSel, torneosEstruct);
        if (!camp || camp.grupo_evento_id < 1) {
            window.alert('Seleccione un torneo del campeonato por género.');
            return;
        }
        const out = el.querySelector('#ag-nom-out');
        if (out) out.innerHTML = '<p class="ag-muted">Evaluando campeonato (asignación M/F automática)…</p>';
        try {
            const r = await fetchJson(
                `api/carga_movimiento_torneo.php?campeonato_genero=1&torneo_id=${encodeURIComponent(String(tidSel))}`
            );
            if (!r.res.ok || !r.data.ok) {
                if (out) out.innerHTML = `<p class="error">${esc(r.data.message || 'Error')}</p>`;
                return;
            }
            lastEvalCampGenero = r.data.evaluacion_campeonato_genero || null;
            lastEval = null;
            lastEvalCampCategoria = null;
            paint();
        } catch (_) {
            if (out) out.innerHTML = '<p class="error">Error de red al evaluar el campeonato.</p>';
        }
    };

    const doEvalCampeonatoCategoria = async () => {
        const camp = campeonatoCategoriaDesdeTorneo(tidSel, torneosEstruct);
        if (!camp || camp.grupo_evento_id < 1) {
            window.alert('Seleccione un torneo del campeonato por categoría.');
            return;
        }
        const out = el.querySelector('#ag-nom-out');
        if (out) out.innerHTML = '<p class="ag-muted">Evaluando campeonato (Sub 12/15/18)…</p>';
        try {
            const r = await fetchJson(
                `api/carga_movimiento_torneo.php?campeonato_categoria=1&torneo_id=${encodeURIComponent(String(tidSel))}`
            );
            if (!r.res.ok || !r.data.ok) {
                if (out) out.innerHTML = `<p class="error">${esc(r.data.message || 'Error')}</p>`;
                return;
            }
            lastEvalCampCategoria = r.data.evaluacion_campeonato_categoria || null;
            lastEval = null;
            lastEvalCampGenero = null;
            paint();
        } catch (_) {
            if (out) out.innerHTML = '<p class="error">Error de red al evaluar el campeonato.</p>';
        }
    };

    const doRegenerarCampeonatoGenero = async () => {
        const camp = campeonatoGeneroDesdeTorneo(tidSel, torneosEstruct);
        if (!camp || camp.grupo_evento_id < 1) return;
        if (!lastEvalCampGenero) {
            window.alert('Evalúe primero el campeonato (M+F automático).');
            return;
        }
        const dist = lastEvalCampGenero.distribucion_automatica || {};
        const force = !!el.querySelector('#ag-nom-force')?.checked;
        const msg =
            `Campeonato grupo #${camp.grupo_evento_id}\n\n` +
            `Asignación automática: M ${dist.asignados_masculino ?? 0}, F ${dist.asignados_femenino ?? 0}\n\n` +
            `Se borrarán ambas nóminas (masculino y femenino) y se reconstruirán según el sexo de cada atleta.\n¿Continuar?`;
        if (!window.confirm(msg)) return;
        const out = el.querySelector('#ag-nom-out');
        if (out) out.innerHTML = '<p class="ag-muted">Regenerando campeonato (M + F)…</p>';
        const r = await fetchJson('api/carga_movimiento_torneo.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                accion: 'regenerar_campeonato_genero',
                grupo_evento_id: camp.grupo_evento_id,
                torneo_id: tidSel,
                confirmar: true,
                force,
                recalcular_deuda: true,
            }),
        });
        if (!r.res.ok || !r.data.ok) {
            if (out) out.innerHTML = `<p class="error">${esc(r.data.message || 'Error')}</p>`;
            return;
        }
        const resumen = r.data.resumen_regeneracion || null;
        if (out) {
            out.innerHTML = `<p class="ok ag-nom-ok">${esc(r.data.message || 'Listo')}</p>
                ${renderResumenRegeneracionGrupo(resumen)}`;
        }
        paint();
    };

    const doRegenerarCampeonatoCategoria = async () => {
        const camp = campeonatoCategoriaDesdeTorneo(tidSel, torneosEstruct);
        if (!camp || camp.grupo_evento_id < 1) return;
        if (!lastEvalCampCategoria) {
            window.alert('Evalúe primero el campeonato (Sub 12/15/18).');
            return;
        }
        const dist = lastEvalCampCategoria.distribucion_automatica || {};
        const force = !!el.querySelector('#ag-nom-force')?.checked;
        const msg =
            `Campeonato grupo #${camp.grupo_evento_id}\n\n` +
            `Simulación: ${JSON.stringify(dist.asignados_por_categoria || {})}\n\n` +
            `Se borrarán las nóminas de todas las variantes Sub y se reconstruirán por categoría/edad.\n¿Continuar?`;
        if (!window.confirm(msg)) return;
        const out = el.querySelector('#ag-nom-out');
        if (out) out.innerHTML = '<p class="ag-muted">Regenerando campeonato por categoría…</p>';
        const r = await fetchJson('api/carga_movimiento_torneo.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                accion: 'regenerar_campeonato_categoria',
                grupo_evento_id: camp.grupo_evento_id,
                torneo_id: tidSel,
                confirmar: true,
                force,
                recalcular_deuda: true,
            }),
        });
        if (!r.res.ok || !r.data.ok) {
            if (out) out.innerHTML = `<p class="error">${esc(r.data.message || 'Error')}</p>`;
            return;
        }
        const resumen = r.data.resumen_regeneracion || null;
        if (out) {
            out.innerHTML = `<p class="ok ag-nom-ok">${esc(r.data.message || 'Listo')}</p>
                ${renderResumenRegeneracionGrupo(resumen)}`;
        }
        paint();
    };

    const doRegenerar = async () => {
        if (tidSel < 1) {
            window.alert('Seleccione el torneo a procesar.');
            return;
        }
        if (!lastEval) {
            window.alert('Evalúe primero los atletas para este torneo.');
            return;
        }
        const meta = lastEval.meta_procesamiento || lastEval.torneo || {};
        const varLab = meta.variante_etiqueta ? String(meta.variante_etiqueta) : '';
        const proc = lastEval.atletas?.procesables ?? 0;
        const exFilas = lastEval.movimiento_torneo_existente?.filas ?? 0;
        const force = !!el.querySelector('#ag-nom-force')?.checked;
        const msg =
            `Torneo id ${tidSel}${varLab ? ` (${varLab})` : ''}\n\n` +
            `Atletas procesables para este torneo: ${proc}\n` +
            `Registros movimiento_torneo a eliminar: ${exFilas}\n\n` +
            `Se borrará la nómina de ESTE torneo y se reconstruirá solo con atletas que correspondan.\n¿Continuar?`;
        if (!window.confirm(msg)) return;
        const out = el.querySelector('#ag-nom-out');
        if (out) out.innerHTML = '<p class="ag-muted">Regenerando nómina…</p>';
        const r = await fetchJson('api/carga_movimiento_torneo.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                accion: 'regenerar',
                torneo_id: tidSel,
                confirmar: true,
                force,
                recalcular_deuda: true,
            }),
        });
        if (!r.res.ok || !r.data.ok) {
            if (out) {
                out.innerHTML = `<p class="error">${esc(r.data.message || 'Error')}</p>`;
                if (r.data.evaluacion) {
                    lastEval = r.data.evaluacion;
                    out.innerHTML += renderEvaluacionNomina(lastEval);
                }
            }
            return;
        }
        const resumen = r.data.resumen_regeneracion || null;
        const res = r.data.resultado || {};
        if (out) {
            out.innerHTML = `<p class="ok ag-nom-ok">${esc(r.data.message || 'Listo')}</p>
                ${renderResumenRegeneracion(resumen, res)}`;
        }
    };

    const boot = await fetchJson('api/carga_movimiento_torneo.php');
    if (boot.res.ok && boot.data.ok) {
        torneosEstruct = boot.data.torneos_estructurado || null;
        if (!torneosEstruct && Array.isArray(boot.data.torneos_selector)) {
            torneosEstruct = { campeonatos: [], sueltos: boot.data.torneos_selector, torneos_plano: boot.data.torneos_selector };
        }
    }
    tidSel = 0;
    lastEval = null;
    lastEvalCampGenero = null;
    lastEvalCampCategoria = null;
    paint();
}
