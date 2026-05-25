/**
 * Informe consolidado por asociación (cantidades + montos) con desglose en dos niveles.
 * Rutas: informes.html | informes.html?asoc=ID | informes.html?asoc=ID&renglon=codigo
 */
import {
    REPORTE_FILAS_POR_PAGINA,
    reporteEsc,
    reporteHtmlPaginador,
    reporteLigarPaginador,
    reporteMetaPagina,
    reporteSliceCliente,
    reporteThumbImg,
    htmlFvdTableShell,
    htmlCeldaEstadisticaRenglon,
    htmlResumenRenglonesCards,
    htmlThEstadisticaRenglon,
    filaTieneEstadisticaRenglon,
    RENGLONES_ESTADISTICA,
} from './reporte_tabla.js';
import { mountPortalPerfilHeader } from './portal_perfil_header.js';
import {
    guardarTorneoFinanzas,
    htmlBannerTorneoCuentas,
    htmlBarraTorneoFinanzas,
    leerTorneoFinanzasGuardado,
    metaTorneoFinanzas,
    torneoFinanzasQs,
    wireBarraTorneoFinanzas,
} from './torneo_finanzas_ui.js';
import {
    hrefFinanzasAsociacion,
    hrefInformeAsociacion,
    hrefInformeConsolidado,
    hrefInformeRenglon,
    hrefReporteAsociacionesTorneo,
    hrefReporteParticipacion,
    htmlLinkDetalleRenglon,
} from './finanzas_reporte_nav.js';
import {
    htmlReporteAsociacionesPorTorneo,
    resetPaginasDetalleFinanza,
    wirePaginacionReporteAsociacionesTorneo,
} from './finanzas_detalle_ui.js';

/** Contexto de sesión tras auth_context (informes delegado / admin). */
let informesAuthCtx = /** @type {Record<string, unknown>|null} */ (null);

const INF_FETCH_TIMEOUT_MS = 90000;

async function fetchJson(url, options = {}) {
    const ctrl = new AbortController();
    const timer = window.setTimeout(() => ctrl.abort(), INF_FETCH_TIMEOUT_MS);
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
                    ? 'La consulta tardó demasiado. Cierre otras pestañas del panel e intente de nuevo.'
                    : 'No se pudo conectar con el servidor.',
            },
        };
    } finally {
        window.clearTimeout(timer);
    }
}

let infPag = 1;
let infPagAsocDet = 1;
let infPagRengMov = 1;
let infPagRengCar = 1;
let infPagCarnetAff = 1;

/** Último payload API para reenganche de filtros/paginación (vista carnets). */
let informeCarnetLastPayload = /** @type {Record<string, unknown>|null} */ (null);
/** Búsqueda por cédula, Nº FVD o nombre (solo filas de la asociación cargada). */
let infCarnetQuery = '';
/** `todos` | `solicitados` — solo filas con indicador de solicitud de carnet en movimiento_torneo. */
let infCarnetFiltroSol = 'todos';
let infCarnetDebTimer = 0;

/** Cache último payload «asociación × torneos». */
let reporteAsocTorCache = /** @type {Record<string, unknown>|null} */ (null);

const REP_ASOC_TOR_PREFIX = 'inf-rep-asoc-tor';

function qsAsoc() {
    const u = new URL(window.location.href);
    const n = parseInt(u.searchParams.get('asoc') || '0', 10);
    return n > 0 ? n : 0;
}

function qsRenglon() {
    const u = new URL(window.location.href);
    const r = (u.searchParams.get('renglon') || '').trim().toLowerCase();
    return r;
}

/** Torneo cuyos marcadores de movimiento muestra el informe (?torneo_id=). */
function qsTorneoInforme() {
    const u = new URL(window.location.href);
    const n = parseInt(u.searchParams.get('torneo_id') || '0', 10);
    return n > 0 ? n : 0;
}

function setQsTorneoInforme(torneoId) {
    const u = new URL(window.location.href);
    if (torneoId > 0) {
        u.searchParams.set('torneo_id', String(torneoId));
    } else {
        u.searchParams.delete('torneo_id');
    }
    history.replaceState(null, '', u.toString());
}

function setInfChrome(title, lead) {
    const h = document.getElementById('inf-report-title');
    const p = document.getElementById('inf-report-lead');
    if (h) h.textContent = title;
    if (p) {
        const t = lead != null ? String(lead) : '';
        p.textContent = t;
        p.style.display = t.trim() === '' ? 'none' : '';
    }
}

function setInfLoading(msg) {
    const p = document.getElementById('inf-report-lead');
    if (p) {
        p.style.display = 'block';
        p.textContent = msg || 'Cargando…';
        p.classList.remove('error');
    }
}

function setInfError(msg) {
    const p = document.getElementById('inf-report-lead');
    if (p) {
        p.style.display = 'block';
        p.textContent = msg;
        p.classList.add('error');
    }
    const h = document.getElementById('inf-report-title');
    if (h) h.textContent = 'Informe — error';
}

function nombreTorneoActivo(tor) {
    return tor && tor.nombre ? String(tor.nombre) : 'Sin torneo activo';
}

function etiquetaRenglon(codigo) {
    const m = {
        afiliacion: 'Afiliados',
        carnet: 'Carnets',
        traspaso: 'Traspasos',
        anualidad: 'Anualidad',
        inscripcion: 'Inscripciones',
    };
    return m[codigo] || codigo;
}

function movimientoFlagsFromPayload(payload) {
    if (!payload || typeof payload !== 'object') {
        return { bloqueado: false, motivo: '' };
    }
    if (payload.movimiento_torneo_bloqueado != null) {
        return {
            bloqueado: !!payload.movimiento_torneo_bloqueado,
            motivo: String(payload.movimiento_bloqueo_motivo || ''),
        };
    }
    const d = payload.data;
    if (d && d.movimiento_torneo_bloqueado != null) {
        return {
            bloqueado: !!d.movimiento_torneo_bloqueado,
            motivo: String(d.movimiento_bloqueo_motivo || ''),
        };
    }
    return { bloqueado: false, motivo: '' };
}

function qsVista() {
    const u = new URL(window.location.href);
    const v = (u.searchParams.get('vista') || '').trim().toLowerCase();
    if (v === 'consolidado' || v === 'asoc-torneos') {
        return v;
    }
    return '';
}

function vistaPreferidaInforme() {
    const v = qsVista();
    if (v) {
        return v;
    }
    if (informesAuthCtx && informesAuthCtx.rol === 'delegado') {
        return 'asoc-torneos';
    }
    return 'asoc-torneos';
}

function informesHomeHref() {
    if (informesAuthCtx && informesAuthCtx.rol === 'delegado') {
        const a = informesAuthCtx.asociacion_id != null ? Number(informesAuthCtx.asociacion_id) : 0;
        return a > 0 ? hrefReporteAsociacionesTorneo({ asocId: a }) : hrefReporteAsociacionesTorneo();
    }
    const v = vistaPreferidaInforme();
    return v === 'consolidado' ? hrefInformeConsolidado({ torneoId: qsTorneoInforme() }) : hrefReporteAsociacionesTorneo();
}

function informesHomeLabel() {
    if (informesAuthCtx && informesAuthCtx.rol === 'delegado') {
        return 'Mi asociación';
    }
    return vistaPreferidaInforme() === 'consolidado' ? 'Consolidado' : 'Por asociación';
}

function htmlBarraVistasInforme() {
    if (!informesAuthCtx || informesAuthCtx.rol !== 'admingral') {
        return '';
    }
    const v = vistaPreferidaInforme();
    const tq = qsTorneoInforme() > 0 ? `&torneo_id=${encodeURIComponent(String(qsTorneoInforme()))}` : '';
    const clsTor = v === 'asoc-torneos' ? 'btn-primary btn-sm' : 'btn-secondary btn-sm';
    const clsCon = v === 'consolidado' ? 'btn-primary btn-sm' : 'btn-secondary btn-sm';
    return `<div class="ag-inf-vista-switch admin-toolbar" role="navigation" aria-label="Tipo de reporte">
        <a class="${clsTor}" href="informes.html?vista=asoc-torneos${tq}">Por asociación y torneo</a>
        <a class="${clsCon}" href="informes.html?vista=consolidado${tq}">Consolidado (un torneo)</a>
        <a class="btn-secondary btn-sm" href="${hrefReporteParticipacion()}">Participación (columnas)</a>
    </div>`;
}

function renderReporteAsociacionesTorneos(body, data) {
    const list = Array.isArray(data.asociaciones) ? data.asociaciones : [];
    const esDel = informesAuthCtx && informesAuthCtx.rol === 'delegado';
    const nAsoc = data.n_asociaciones != null ? parseInt(String(data.n_asociaciones), 10) : list.length;
    setInfChrome(
        esDel ? 'Reporte de finanzas — mi asociación' : 'Reporte de finanzas — asociaciones y torneos',
        esDel
            ? 'Cada torneo de su asociación con resumen por concepto (afiliación, anualidad, carnet, traspaso, inscritos).'
            : `${nAsoc} asociación(es). Ej.: Anzoátegui → torneo 1, torneo 2… con su resumen en cada bloque.`
    );
    reporteAsocTorCache = data;
    const integ = data.integral || null;
    const pieInt =
        integ && !esDel
            ? `<p class="ag-muted ag-inf-stat-hint">Integral FVD — Deuda: <strong>${reporteEsc(String(integ.deuda_eur ?? 0))} €</strong> · Pagado: ${reporteEsc(String(integ.pagado_eur ?? 0))} € · Saldo: ${reporteEsc(String(integ.saldo_eur ?? 0))} €</p>`
            : '';
    body.innerHTML = `
        ${htmlBarraVistasInforme()}
        <div class="ag-fin-shell ag-fin-shell--informe ag-fvd-reporte-datos">
        ${pieInt}
        <p class="ag-muted ag-inf-stat-hint">Abra cada asociación para ver <strong>todos sus torneos</strong> con cantidades y montos. En cada renglón use <strong>Detalle</strong> o pulse la celda con cantidad para ver atletas.</p>
        ${htmlReporteAsociacionesPorTorneo(list, REP_ASOC_TOR_PREFIX, { soloUna: esDel })}
        </div>
        <p class="ag-inf-actions"><a class="btn-secondary" href="${informesHomeHref()}">${esDel ? 'Inicio' : 'Cambiar vista'}</a>
        ${!esDel ? `<a class="btn-secondary" href="${hrefInformeConsolidado({ torneoId: qsTorneoInforme() })}">Vista consolidada por torneo</a>` : ''}
        <a class="btn-secondary" href="${hrefReporteParticipacion()}">Participación (columnas)</a>
        </p>`;
    resetPaginasDetalleFinanza(REP_ASOC_TOR_PREFIX);
    wirePaginacionReporteAsociacionesTorneo(body, list, REP_ASOC_TOR_PREFIX, () => {
        if (reporteAsocTorCache) {
            renderReporteAsociacionesTorneos(body, reporteAsocTorCache);
        }
    });
}

function renderMovimientoBloqueoYToolbar(data, toolbarEnBarraTorneo = false) {
    const { bloqueado, motivo } = movimientoFlagsFromPayload(data);
    const aviso = bloqueado
        ? `<div class="ag-inf-bloqueo-msg" role="status"><strong>Nómina cerrada.</strong> ${reporteEsc(motivo)}</div>`
        : '';
    const esAdmin = informesAuthCtx && informesAuthCtx.rol === 'admingral';
    const toolbar =
        esAdmin && !toolbarEnBarraTorneo
            ? `<div class="ag-inf-actions ag-inf-toolbar">
            <button type="button" class="btn-primary" id="btn-inf-deuda">Actualizar deudas desde movimiento</button>
            <span id="inf-deuda-feedback" class="ag-muted" role="status"></span>
        </div>`
            : toolbarEnBarraTorneo && esAdmin
              ? `<span id="inf-deuda-feedback" class="ag-muted" role="status"></span>`
              : '';
    return aviso + toolbar;
}

function wireInformeDeudaButton(body, torneoInformeId = 0) {
    const btn = document.getElementById('btn-inf-deuda');
    const fb = document.getElementById('inf-deuda-feedback');
    if (!btn) return;
    btn.addEventListener('click', async () => {
        if (fb) {
            fb.textContent = 'Procesando…';
        }
        btn.disabled = true;
        try {
            const tid = torneoInformeId > 0 ? torneoInformeId : qsTorneoInforme();
            const r = await fetchJson('api/informe_actualizar_deudas.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(tid > 0 ? { torneo_id: tid } : {}),
            });
            if (!r.res.ok || !r.data.ok) {
                if (fb) fb.textContent = r.data.message || 'Error';
            } else {
                if (fb) fb.textContent = r.data.message || 'Listo.';
                await loadInforme(body);
            }
        } catch (e) {
            if (fb) fb.textContent = 'Error de red.';
        } finally {
            btn.disabled = false;
        }
    });
}

function renderNav(asoc, renglon) {
    const home = `<a href="${informesHomeHref()}" class="btn-secondary ag-inf-nav-link">${reporteEsc(informesHomeLabel())}</a>`;
    if (!asoc) return `<p class="ag-inf-breadcrumb">${home}</p>`;
    const as = `<a href="informes.html?asoc=${encodeURIComponent(String(asoc))}" class="btn-secondary ag-inf-nav-link">Asociación</a>`;
    if (!renglon) return `<p class="ag-inf-breadcrumb">${home} <span class="ag-inf-bc-sep" aria-hidden="true">›</span> ${as}</p>`;
    return `<p class="ag-inf-breadcrumb">${home} <span class="ag-inf-bc-sep" aria-hidden="true">›</span> ${as} <span class="ag-inf-bc-sep" aria-hidden="true">›</span> <span class="ag-inf-bc-current">${reporteEsc(etiquetaRenglon(renglon))}</span></p>`;
}

function renderConsolidado(body, data) {
    const torInf = data.torneo_informe || data.torneo_activo || null;
    const torAct = data.torneo_activo || null;
    const tidInf = data.torneo_informe_id != null ? parseInt(String(data.torneo_informe_id), 10) : 0;
    const tn = nombreTorneoActivo(torInf);
    const actNote =
        torAct && torInf && parseInt(String(torAct.id), 10) !== tidInf
            ? ` (torneo en curso: ${nombreTorneoActivo(torAct)})`
            : '';
    const tmInf = metaTorneoFinanzas(torInf);
    setInfChrome(`Reporte de finanzas — ${tn}${actNote}`, '');
    const selectorList = data.torneos_nomina_selector || [];
    const torneosEstructInf = data.torneos_estructurado || null;
    let tidUse = tidInf > 0 ? tidInf : qsTorneoInforme();
    if (tidUse < 1 && leerTorneoFinanzasGuardado() > 0) tidUse = leerTorneoFinanzasGuardado();
    const tqInf = torneoFinanzasQs(tidUse > 0 ? tidUse : tidInf);
    const bannerInf = htmlBannerTorneoCuentas({
        nombre: reporteEsc(tmInf.nombre || tn),
        torneoId: tidInf > 0 ? tidInf : tidUse,
        fechator: tmInf.fechator,
        finalizado_en: tmInf.finalizado_en,
        enCurso: tmInf.enCurso,
        alcance: 'Informe consolidado por asociación',
    });
    const selectorHtml =
        selectorList.length > 0
            ? htmlBarraTorneoFinanzas({
                  prefix: 'inf-con',
                  torneos: selectorList,
                  torneoSeleccionado: tidInf > 0 ? tidInf : tidUse,
                  torneosEstructurado: torneosEstructInf,
                  requerirTorneo: false,
                  showActualizar: !!(informesAuthCtx && informesAuthCtx.rol === 'admingral'),
                  showConsolidar: false,
              })
            : '';
    const rowsAll = (data.filas || []).filter((x) => filaTieneEstadisticaRenglon(x));
    const tot = data.totales_renglones || null;
    const per = REPORTE_FILAS_POR_PAGINA;
    const total = rowsAll.length;
    const slice = reporteSliceCliente(rowsAll, infPag, per);
    const pager = reporteHtmlPaginador('inf-con', infPag, total, per);
    const thStats = RENGLONES_ESTADISTICA.map((r) => htmlThEstadisticaRenglon(r)).join('');
    const tidNav = tidUse > 0 ? tidUse : tidInf;
    const navTor = { torneoId: tidNav, grupoId: 0 };
    const tr = slice
        .map((x) => {
            const navAsoc = { asocId: x.id, torneoId: tidNav, grupoId: 0 };
            const celdasStat = RENGLONES_ESTADISTICA.map((r) =>
                htmlCeldaEstadisticaRenglon(x[r.n], x[r.monto], r, navAsoc)
            ).join('');
            const aid = parseInt(String(x.id), 10) || 0;
            return `<tr>
            <td class="reporte-td-img">${reporteThumbImg(x.logo || '', x.nombre || '')}</td>
            <td class="ag-inf-asoc-cell"><a class="ag-inf-asoc-link" href="${hrefInformeAsociacion(aid, navTor)}">${reporteEsc(x.nombre)}</a></td>
            ${celdasStat}
            <td class="ag-num">${reporteEsc(String(x.monto_total_eur))} €</td>
            <td class="ag-num">${reporteEsc(String(x.deuda_eur))} €</td>
            <td class="ag-num">${reporteEsc(String(x.pagado_eur))} €</td>
            <td class="ag-num">${reporteEsc(String(x.saldo_eur))} €</td>
            <td class="reporte-td-acciones ag-inf-recibo-cell">
                <div class="reporte-acciones">
                <a class="btn-secondary btn-sm reporte-btn-ic" href="${hrefFinanzasAsociacion(aid, navTor)}#fin-aso-pagos" title="Pagos" aria-label="Pagos"><span class="reporte-btn-ic-sym" aria-hidden="true">💶</span></a>
                <a class="btn-secondary btn-sm reporte-btn-ic" href="${hrefFinanzasAsociacion(aid, navTor)}" title="Estado de cuenta" aria-label="Estado de cuenta"><span class="reporte-btn-ic-sym" aria-hidden="true">📋</span></a>
                </div>
            </td>
        </tr>`;
        })
        .join('');
    const tfootTot =
        tot && total > 0
            ? `<tfoot class="ag-stat-tfoot"><tr>
            <td colspan="2"><strong>Total torneo</strong></td>
            ${RENGLONES_ESTADISTICA.map((r) => htmlCeldaEstadisticaRenglon(tot[r.n], tot[r.monto], r)).join('')}
            <td colspan="5"></td>
            </tr></tfoot>`
            : '';
    const cardsTot = htmlResumenRenglonesCards(tot);
    const hintRows =
        total > 0
            ? `<p class="ag-muted ag-inf-stat-hint">${total} asociación(es) con movimiento en la nómina del torneo. Pulse el <strong>nombre de la asociación</strong> para el desglose por renglón, o una <strong>celda de concepto</strong> (cantidad &gt; 0) para ir al detalle de atletas/movimientos.</p>`
            : '<p class="ag-muted ag-inf-stat-hint">Ninguna asociación tiene conceptos en la nómina de este torneo.</p>';
    body.innerHTML = `
        ${htmlBarraVistasInforme()}
        ${renderNav(0, '')}
        <div class="ag-fin-shell ag-fin-shell--informe ag-fvd-reporte-datos">
        ${bannerInf}
        ${selectorHtml}
        ${renderMovimientoBloqueoYToolbar(data, true)}
        ${cardsTot}
        ${hintRows}
        <div class="ag-inf-consolidado ag-fvd-reporte-datos">
        <div class="reporte-vista ag-inf-scroll ag-inf-scroll--consolidado ag-fin-reporte-vista ag-fvd-reporte-datos">
        ${pager}
        ${htmlFvdTableShell(`<table class="fvd-table admin-crud-table admin-crud-table--compact text-sm ag-inf-table-cons" aria-describedby="inf-con-desc">
            <caption id="inf-con-desc" class="ag-inf-table-caption">Resumen por asociación: estadísticas por renglón (afiliación, anualidad, carnet, traspaso, inscritos) y saldos en euros.</caption>
            <thead>
            <tr class="ag-inf-thead-groups">
                <th colspan="2" class="ag-inf-th-grupo">Identificación</th>
                <th colspan="5" class="ag-inf-th-grupo ag-inf-th-grupo--qty">Estadísticas por renglón (cant. / €)</th>
                <th colspan="4" class="ag-inf-th-grupo ag-inf-th-grupo--eur">Euros cuenta</th>
                <th class="ag-inf-th-grupo ag-inf-th-grupo--doc">Doc.</th>
            </tr>
            <tr>
                <th class="reporte-th-img" scope="col">Logo</th>
                <th scope="col">Asociación</th>
                ${thStats}
                <th class="ag-num" scope="col" title="Total nómina del torneo">Total nómina</th>
                <th class="ag-num" scope="col" title="Deuda registrada">Deuda</th>
                <th class="ag-num" scope="col" title="Pagado verificado">Pagado</th>
                <th class="ag-num" scope="col" title="Saldo">Saldo</th>
                <th scope="col">Pagos</th>
            </tr>
            </thead>
            <tbody>${total > 0 ? tr : '<tr><td colspan="12" class="ag-muted">Sin datos para este torneo.</td></tr>'}</tbody>
            ${tfootTot}
        </table>`)}
        </div>
        </div></div>`;
    reporteLigarPaginador('inf-con', infPag, total, (np) => {
        infPag = np;
        renderConsolidado(body, data);
    }, per);
    wireBarraTorneoFinanzas(body.querySelector('.ag-fin-shell') || body, 'inf-con', {
        onTorneoChange: (v) => {
            setQsTorneoInforme(v);
            if (v > 0) guardarTorneoFinanzas(v);
            infPag = 1;
            loadInforme(body);
        },
        onActualizar:
            informesAuthCtx && informesAuthCtx.rol === 'admingral'
                ? async () => {
                      const fb = document.getElementById('inf-deuda-feedback');
                      if (fb) fb.textContent = 'Procesando…';
                      const tid = tidInf > 0 ? tidInf : qsTorneoInforme();
                      const r = await fetchJson('api/informe_actualizar_deudas.php', {
                          method: 'POST',
                          headers: { 'Content-Type': 'application/json' },
                          body: JSON.stringify(tid > 0 ? { torneo_id: tid } : {}),
                      });
                      if (fb) fb.textContent = r.data.message || (r.res.ok && r.data.ok ? 'Listo.' : 'Error');
                      if (r.res.ok && r.data.ok) await loadInforme(body);
                  }
                : undefined,
    });
    if (document.getElementById('btn-inf-deuda')) wireInformeDeudaButton(body, tidInf);
}

function renderDetalleAsociacion(body, data) {
    const d = data.data || {};
    const a = d.asociacion || {};
    const tidInf = d.torneo_activo_id != null ? parseInt(String(d.torneo_activo_id), 10) : qsTorneoInforme();
    const tor = d.torneo_activo || null;
    const tn = nombreTorneoActivo(tor);
    const tmA = metaTorneoFinanzas(tor);
    const titAsoc =
        informesAuthCtx && informesAuthCtx.rol === 'delegado'
            ? `Reporte de finanzas — ${reporteEsc(a.nombre || 'Mi asociación')}`
            : `Reporte por asociación — ${tn}`;
    setInfChrome(titAsoc, '');
    const torQ = tidInf > 0 ? `&torneo_id=${tidInf}` : '';
    const bannerAsoc = htmlBannerTorneoCuentas({
        nombre: reporteEsc(tmA.nombre || tn),
        torneoId: tidInf,
        fechator: tmA.fechator,
        finalizado_en: tmA.finalizado_en,
        enCurso: tmA.enCurso,
        alcance: `Asociación: ${reporteEsc(a.nombre || '')}`,
    });
    const ren = d.renglones || [];
    const totDet = d.totales || {};
    const ecDet = totDet.estado_cuenta || null;
    const asocId = a.id;
    const per = REPORTE_FILAS_POR_PAGINA;
    const totalRen = ren.length;
    const renSlice = reporteSliceCliente(ren, infPagAsocDet, per);
    const pagerAsoc = reporteHtmlPaginador('inf-asoc', infPagAsocDet, totalRen, per);
    const bannerCuenta =
        ecDet != null
            ? `<div class="ag-fin-recibo-sum ag-fin-recibo-sum--fvd ag-inf-cuenta-banner">
            <span><strong>Total cuenta</strong> ${reporteEsc(String(ecDet.deuda_eur))} €</span>
            <span><strong>Nómina (todos los torneos)</strong> ${reporteEsc(String(ecDet.nomina_eur ?? 0))} €</span>
            <span><strong>Cargos manuales</strong> ${reporteEsc(String(ecDet.cargos_manuales_eur ?? 0))} €</span>
            <span><strong>Pagado</strong> ${reporteEsc(String(ecDet.pagado_eur))} €</span>
            <span><strong>Saldo</strong> ${reporteEsc(String(ecDet.saldo_eur))} €</span>
        </div>`
            : '';
    const rows = renSlice
        .map((r) => {
            const cod = r.codigo || '';
            const cant = r.cantidad != null ? reporteEsc(String(r.cantidad)) : '—';
            let m = `${reporteEsc(String(r.monto_eur))} €`;
            if (cod === 'total_cuenta') {
                const pag = r.pagado_eur != null ? reporteEsc(String(r.pagado_eur)) : '—';
                const sal = r.saldo_eur != null ? reporteEsc(String(r.saldo_eur)) : '—';
                const nom =
                    r.nomina_eur != null ? reporteEsc(String(r.nomina_eur)) : null;
                const car =
                    r.cargos_manuales_eur != null ? reporteEsc(String(r.cargos_manuales_eur)) : null;
                const desg =
                    nom != null && car != null
                        ? ` · nómina ${nom} € + cargos ${car} €`
                        : '';
                m = `<strong>${m}</strong> <span class="ag-muted text-xs">(pagado ${pag} · saldo ${sal}${desg})</span>`;
            }
            const navOpts = { torneoId: tidInf, grupoId: 0 };
            const det =
                cod === 'total_cuenta'
                    ? `<a class="btn-secondary btn-sm" href="${hrefFinanzasAsociacion(asocId, navOpts)}#fin-aso-cargos">Cargos / cuenta</a>`
                    : htmlLinkDetalleRenglon(asocId, cod, navOpts, {
                          iconos: false,
                          etiqueta: r.etiqueta || cod,
                      });
            return `<tr>
                <td><strong>${reporteEsc(r.etiqueta || cod)}</strong></td>
                <td class="ag-num">${cant}</td>
                <td class="ag-num">${m}</td>
                <td class="reporte-td-acciones">${det}</td>
            </tr>`;
        })
        .join('');
    const tbodyRen =
        totalRen > 0
            ? rows
            : '<tr><td colspan="4" class="ag-muted">Sin renglones en este informe.</td></tr>';
    body.innerHTML = `
        ${renderNav(asocId, '')}
        <div class="ag-fin-shell ag-fin-shell--informe ag-fvd-reporte-datos">
        ${bannerAsoc}
        ${renderMovimientoBloqueoYToolbar(data)}
        <h2 class="ag-subtitle ag-fin-section-title">${reporteEsc(a.nombre || 'Asociación')}</h2>
        ${bannerCuenta}
        <div class="reporte-vista ag-fin-reporte-vista">
        ${pagerAsoc}
        ${htmlFvdTableShell(`<table class="fvd-table admin-crud-table admin-crud-table--compact text-sm">
            <thead><tr><th>Renglón</th><th class="ag-num">Cantidad</th><th class="ag-num">Monto €</th><th>Detalle</th></tr></thead>
            <tbody>${tbodyRen}</tbody>
        </table>`)}
        </div>
        <p class="ag-muted ag-inf-stat-hint">Pulse <strong>Detalle</strong> en cada renglón para ver atletas y movimientos de ese concepto en el torneo seleccionado.</p>
        <p class="ag-inf-actions"><a class="btn-secondary" href="${informesHomeHref()}">${informesAuthCtx?.rol === 'delegado' ? 'Volver' : 'Volver al consolidado'}</a>
        <a class="btn-secondary" href="${hrefFinanzasAsociacion(asocId, { torneoId: tidInf })}#fin-aso-cargos">Estado de cuenta</a>
        <a class="btn-secondary" href="${hrefFinanzasAsociacion(asocId, { torneoId: tidInf })}#fin-aso-pagos">Pagos / recibos</a></p></div>`;
    reporteLigarPaginador('inf-asoc', infPagAsocDet, totalRen, (np) => {
        infPagAsocDet = np;
        renderDetalleAsociacion(body, data);
    }, per);
    wireInformeDeudaButton(body, tidInf);
}

function movimientoBadgesInformeCarnet(row) {
    const af = Number(row.afiliacion) >= 1 ? '<span class="ins-badge">AF</span>' : '';
    const an = Number(row.anualidad) >= 1 ? '<span class="ins-badge">AN</span>' : '';
    const ca = Number(row.carnet) >= 1 ? '<span class="ins-badge">CA</span>' : '';
    const tp = Number(row.traspaso) >= 1 ? '<span class="ins-badge ins-badge--tp">TP</span>' : '';
    const bits = (af + an + ca + tp).trim();
    return bits !== '' ? bits : '<span class="ag-muted">Sin marca</span>';
}

function badgePortalInforme(usuarioStatus) {
    const st = usuarioStatus === null || usuarioStatus === undefined || usuarioStatus === '' ? null : Number(usuarioStatus);
    if (st === null || Number.isNaN(st)) {
        return '<span class="ag-inf-port ag-inf-port--na">Sin cuenta portal</span>';
    }
    if (st === 0) {
        return '<span class="ag-inf-port ag-inf-port--ok">Portal activo</span>';
    }
    if (st === 9) {
        return '<span class="ag-inf-port ag-inf-port--pend">Portal: anualidad / pago pendiente (9)</span>';
    }
    return `<span class="ag-inf-port ag-inf-port--misc">Estatus ${reporteEsc(String(st))}</span>`;
}

/**
 * Una fila del informe carnet cumple búsqueda + selector «solo solicitudes».
 * @param {any} r
 * @returns {boolean}
 */
function carnetFilaPasaFiltros(r) {
    if (infCarnetFiltroSol === 'solicitados') {
        const ca = Number(r.carnet) || 0;
        const af = Number(r.afiliacion) || 0;
        const tp = Number(r.traspaso) || 0;
        const nfM = Number(r.numfvd) || 0;
        const afiliPendiente = af === 1 && nfM < 1;
        if (!(ca >= 1 && !afiliPendiente && tp !== 1)) {
            return false;
        }
    }
    const q = infCarnetQuery.trim().toLowerCase().replace(/\s+/g, '');
    if (q === '') {
        return true;
    }
    const ced = String(r.cedula || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, '');
    const nom = String(r.nombre || '')
        .trim()
        .toLowerCase();
    const nf = String(r.numfvd ?? '').trim();
    if (ced.includes(q) || nf.includes(q)) {
        return true;
    }
    const qn = q.replace(/\D/g, '');
    if (qn !== '' && nf.replace(/\D/g, '').includes(qn)) {
        return true;
    }
    return nom.includes(infCarnetQuery.trim().toLowerCase());
}

/**
 * Filas de afiliados del informe carnet: filtro por solicitud y texto (cédula / Nº FVD / nombre).
 * @param {any[]} rows
 * @returns {any[]}
 */
function filasCarnetInformeFiltradas(rows) {
    const arr = Array.isArray(rows) ? rows : [];
    return arr.filter(carnetFilaPasaFiltros);
}

function wireCarnetInformeToolbar() {
    const body = document.getElementById('inf-body');
    const inp = document.getElementById('inf-carnet-q');
    const sel = document.getElementById('inf-carnet-filtro-sol');
    const btn = document.getElementById('inf-carnet-buscar');
    const apply = () => {
        if (!informeCarnetLastPayload || !body) {
            return;
        }
        renderDetalleRenglonCarnet(body, informeCarnetLastPayload);
    };
    inp?.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            window.clearTimeout(infCarnetDebTimer);
            infCarnetDebTimer = 0;
            infCarnetQuery = String(document.getElementById('inf-carnet-q')?.value || '');
            infPagCarnetAff = 1;
            apply();
        }
    });
    inp?.addEventListener('input', () => {
        window.clearTimeout(infCarnetDebTimer);
        infCarnetDebTimer = window.setTimeout(() => {
            infCarnetDebTimer = 0;
            infCarnetQuery = String(document.getElementById('inf-carnet-q')?.value || '');
            infPagCarnetAff = 1;
            apply();
        }, 320);
    });
    btn?.addEventListener('click', () => {
        window.clearTimeout(infCarnetDebTimer);
        infCarnetDebTimer = 0;
        infCarnetQuery = String(document.getElementById('inf-carnet-q')?.value || '');
        infPagCarnetAff = 1;
        apply();
    });
    sel?.addEventListener('change', () => {
        const s = document.getElementById('inf-carnet-filtro-sol');
        infCarnetFiltroSol = String(s?.value || 'todos') === 'solicitados' ? 'solicitados' : 'todos';
        infPagCarnetAff = 1;
        apply();
    });
}

/**
 * Vista renglón «Carnets»: listado de afiliados con iconos (ver / solicitud carnet / editar).
 * @param {HTMLElement} body
 * @param {Record<string, unknown>} data
 */
function renderDetalleRenglonCarnet(body, data) {
    informeCarnetLastPayload = data;
    const a = data.asociacion || {};
    const tor = data.torneo_activo || null;
    const tn = nombreTorneoActivo(tor);
    const cod = 'carnet';
    const asocId = a.id != null ? Number(a.id) : 0;
    const tid = data.torneo_activo_id != null ? Number(data.torneo_activo_id) : 0;
    setInfChrome(`Informe — Carnets — Torneo: ${tn}`, 'Afiliados de la asociación: ver ficha, solicitar carnet (registra movimiento_torneo y aviso) o editar.');
    const base = Array.isArray(data.afiliados_carnet_informe) ? data.afiliados_carnet_informe : [];
    const per = REPORTE_FILAS_POR_PAGINA;
    /** Paginación siempre sobre el listado completo de la asociación (no solo coincidencias). */
    const metaPag = reporteMetaPagina(base.length, infPagCarnetAff, per);
    infPagCarnetAff = metaPag.pagina;
    const slice = reporteSliceCliente(base, infPagCarnetAff, per);
    const visibles = slice.filter(carnetFilaPasaFiltros);
    const totalCoinc = filasCarnetInformeFiltradas(base).length;
    const pagerTop = reporteHtmlPaginador('inf-carnet-aff', infPagCarnetAff, base.length, per);
    const pagerBot = reporteHtmlPaginador('inf-carnet-aff-b', infPagCarnetAff, base.length, per);
    const metaTxt =
        base.length < 1
            ? 'Sin afiliados en la asociación para este informe.'
            : `Paginación sobre los ${base.length} afiliado(s) (pág. ${metaPag.pagina}/${metaPag.totalPaginas}). Filtro actual: ${totalCoinc} coincidencia(s) en total; en esta página, ${visibles.length} fila(s) visible(s).`;
    const rows = visibles
        .map((row) => {
            const uid = Number(row.user_id) || 0;
            const ced = String(row.cedula || '').trim();
            const c = encodeURIComponent(ced);
            const torQ = tid > 0 ? `&torneo_id=${encodeURIComponent(String(tid))}` : '';
            const verHref = `afiliar_atleta.html?modo=ver&cedula=${c}${torQ}`;
            const edHref = `afiliar_atleta.html?modo=editar&cedula=${c}${torQ}`;
            const sx = row.sexo === 1 ? 'M' : row.sexo === 2 ? 'F' : '—';
            return `<tr>
        <td class="ag-num">${reporteEsc(String(row.numfvd ?? ''))}</td>
        <td>${reporteEsc(ced)}</td>
        <td>${reporteEsc(String(row.nombre || ''))}</td>
        <td>${reporteEsc(sx)}</td>
        <td><div class="ag-inf-obs-cell">${badgePortalInforme(row.usuario_status)} <span class="ag-inf-obs-sep">·</span> ${movimientoBadgesInformeCarnet(row)}</div></td>
        <td class="ag-inf-icon-cell">
          <a class="ag-inf-icon-btn ag-inf-icon-btn--view" href="${verHref}" title="Ver" aria-label="Ver">👁</a>
          <button type="button" class="ag-inf-icon-btn ag-inf-icon-btn--carnet" data-inf-sol-carnet="${reporteEsc(String(uid))}" title="Solicitud de carnet (movimiento_torneo)" aria-label="Solicitar carnet"${tid < 1 ? ' disabled' : ''}>📇</button>
          <a class="ag-inf-icon-btn ag-inf-icon-btn--edit" href="${edHref}" title="Editar" aria-label="Editar">✎</a>
        </td>
      </tr>`;
        })
        .join('');
    body.innerHTML = `
        ${renderNav(asocId, cod)}
        ${renderMovimientoBloqueoYToolbar(data)}
        <h2 class="ag-subtitle">${reporteEsc(a.nombre || '')} — ${reporteEsc(etiquetaRenglon(cod))}</h2>
        ${tid < 1 ? '<p class="error">No hay torneo activo: no se puede operar sobre <code>movimiento_torneo</code> hasta que exista un torneo sin fecha de cierre.</p>' : ''}
        <p class="ag-muted ag-inf-torneo-ref">Torneo de referencia del informe: <strong>${reporteEsc(tn)}</strong>${tid > 0 ? ` (id <code>${reporteEsc(String(tid))}</code>)` : ''}</p>
        <p class="ag-muted">Cada <strong>solicitud de carnet</strong> actualiza o crea la fila en <code>movimiento_torneo</code> para este torneo (indicador carnet). Si el atleta tiene <strong>estatus portal 9</strong>, suele indicar pendiente de <strong>anualidad / pago</strong> en portal; la lógica de carnet puede además reflejar anualidad según reglas FVD. Se genera un aviso para administración general.</p>
        <div class="ag-inf-carnet-toolbar-sticky">
        <div class="ag-inf-carnet-toolbar fvd-form fvd-form--inline">
            <label class="ag-inf-carnet-fld"><span class="ag-inf-carnet-lbl">Buscar</span>
                <input type="search" id="inf-carnet-q" class="admin-search" autocomplete="off" placeholder="Cédula, Nº FVD o nombre" value="${reporteEsc(infCarnetQuery)}">
            </label>
            <button type="button" class="btn-secondary btn-sm" id="inf-carnet-buscar">Buscar</button>
            <label class="ag-inf-carnet-fld"><span class="ag-inf-carnet-lbl">Listado</span>
                <select id="inf-carnet-filtro-sol" class="admin-search">
                    <option value="todos"${infCarnetFiltroSol === 'todos' ? ' selected' : ''}>Todos los afiliados</option>
                    <option value="solicitados"${infCarnetFiltroSol === 'solicitados' ? ' selected' : ''}>Solo solicitudes de carnet</option>
                </select>
            </label>
            <span class="ag-muted ag-inf-carnet-meta" id="inf-carnet-meta">${reporteEsc(metaTxt)}</span>
        </div>
        </div>
        <div class="reporte-vista">
        ${pagerTop}
        ${htmlFvdTableShell(`<table class="fvd-table admin-crud-table admin-crud-table--compact text-sm">
            <thead><tr><th class="ag-num">Nº FVD</th><th>Cédula</th><th>Nombre</th><th>Sexo</th><th>Observaciones</th><th>Acciones</th></tr></thead>
            <tbody>${
                rows ||
                (base.length < 1
                    ? '<tr><td colspan="6" class="ag-muted">Sin afiliados en la asociación para este informe.</td></tr>'
                    : '<tr><td colspan="6" class="ag-muted">Ninguna fila de esta página cumple el filtro actual. Cambie de página o ajuste búsqueda / listado.</td></tr>')
            }</tbody>
        </table>`)}
        ${pagerBot}
        </div>
        <p class="ag-inf-actions"><a class="btn-secondary" href="informes.html?asoc=${encodeURIComponent(String(asocId))}">Volver al desglose de la asociación</a>
        <a class="btn-secondary" href="finanzas_asociacion.html?id=${encodeURIComponent(String(asocId))}#fin-aso-pagos">Pagos / recibos</a></p>`;
    const onPagCarnet = (np) => {
        infPagCarnetAff = np;
        renderDetalleRenglonCarnet(body, informeCarnetLastPayload || data);
    };
    reporteLigarPaginador('inf-carnet-aff', infPagCarnetAff, base.length, onPagCarnet, per);
    reporteLigarPaginador('inf-carnet-aff-b', infPagCarnetAff, base.length, onPagCarnet, per);
    wireInformeDeudaButton(body);
    wireCarnetInformeToolbar();
    if (tid < 1) {
        return;
    }
    body.querySelectorAll('[data-inf-sol-carnet]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const uid = parseInt(String(btn.getAttribute('data-inf-sol-carnet') || '0'), 10) || 0;
            if (uid < 1) return;
            btn.setAttribute('disabled', 'disabled');
            try {
                const r = await fetch('api/informe_movimiento_solicitar_carnet.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: uid, torneo_id: tid, asociacion_id: asocId }),
                    credentials: 'same-origin',
                });
                const d = await r.json().catch(() => ({}));
                if (r.ok && d.ok) {
                    window.alert(d.message || 'Solicitud registrada.');
                    await loadInforme(body, { preserveCarnetFilters: true });
                } else {
                    window.alert(d.message || 'No se pudo registrar.');
                    btn.removeAttribute('disabled');
                }
            } catch (_) {
                window.alert('Error de conexión.');
                btn.removeAttribute('disabled');
            }
        });
    });
}

function renderDetalleRenglon(body, data) {
    const cod = data.renglon || '';
    if (cod === 'carnet') {
        renderDetalleRenglonCarnet(body, data);
        return;
    }
    const a = data.asociacion || {};
    const tor = data.torneo_activo || null;
    const tn = nombreTorneoActivo(tor);
    const tidR = data.torneo_activo_id != null ? parseInt(String(data.torneo_activo_id), 10) : qsTorneoInforme();
    setInfChrome(`Detalle renglón — ${reporteEsc(etiquetaRenglon(cod))} — ${tn}`, '');
    const asocId = a.id;
    const movs = data.movimientos || [];
    const cars = data.cargos || [];
    const per = REPORTE_FILAS_POR_PAGINA;
    const movTot = movs.length;
    const carTot = cars.length;
    const movSlice = reporteSliceCliente(movs, infPagRengMov, per);
    const carSlice = reporteSliceCliente(cars, infPagRengCar, per);
    const pagerMov = reporteHtmlPaginador('inf-mov', infPagRengMov, movTot, per);
    const pagerCar = reporteHtmlPaginador('inf-car', infPagRengCar, carTot, per);
    const movRows =
        movTot > 0
            ? movSlice
                  .map(
                      (m) =>
                          `<tr><td>${reporteEsc(m.cedula || '')}</td><td class="ag-num">${reporteEsc(String(m.numfvd ?? ''))}</td><td>${reporteEsc(m.nombre_usuario || '—')}</td><td class="ag-num">${reporteEsc(String(m.torneo_id ?? ''))}</td></tr>`
                  )
                  .join('')
            : '<tr><td colspan="4">Sin movimientos con esta marca en el torneo activo.</td></tr>';
    const carRows =
        carTot > 0
            ? carSlice
                  .map(
                      (c) =>
                          `<tr><td>${reporteEsc(c.fecha_emision || '')}</td><td>${reporteEsc(c.concepto || '')}</td><td class="ag-num">${reporteEsc(String(c.monto_eur))} €</td><td>${reporteEsc(c.referencia || '—')}</td></tr>`
                  )
                  .join('')
            : '<tr><td colspan="4">Sin cargos clasificados en este renglón.</td></tr>';
    body.innerHTML = `
        ${renderNav(asocId, cod)}
        ${renderMovimientoBloqueoYToolbar(data)}
        <h2 class="ag-subtitle">${reporteEsc(a.nombre || '')} — ${reporteEsc(etiquetaRenglon(cod))}</h2>
        <p class="ag-muted ag-inf-torneo-ref">Torneo de referencia del informe: <strong>${reporteEsc(tn)}</strong></p>
        <h3 class="ag-inf-h3">Movimientos</h3>
        <div class="reporte-vista">
        ${pagerMov}
        ${htmlFvdTableShell(`<table class="fvd-table admin-crud-table admin-crud-table--compact text-sm">
            <thead><tr><th>Cédula</th><th class="ag-num">Nº FVD</th><th>Nombre</th><th class="ag-num">Torneo</th></tr></thead>
            <tbody>${movRows}</tbody>
        </table>`)}
        </div>
        <h3 class="ag-inf-h3">Cargos</h3>
        <div class="reporte-vista">
        ${pagerCar}
        ${htmlFvdTableShell(`<table class="fvd-table admin-crud-table admin-crud-table--compact text-sm">
            <thead><tr><th>Fecha</th><th>Concepto</th><th class="ag-num">Monto €</th><th>Ref.</th></tr></thead>
            <tbody>${carRows}</tbody>
        </table>`)}
        </div>
        <p class="ag-inf-actions"><a class="btn-secondary" href="${hrefInformeAsociacion(asocId, { torneoId: tidR })}">Volver al desglose por renglón</a>
        <a class="btn-secondary" href="${hrefFinanzasAsociacion(asocId, { torneoId: tidR })}#fin-aso-pagos">Pagos / recibos</a></p>`;
    reporteLigarPaginador('inf-mov', infPagRengMov, movTot, (np) => {
        infPagRengMov = np;
        renderDetalleRenglon(body, data);
    }, per);
    reporteLigarPaginador('inf-car', infPagRengCar, carTot, (np) => {
        infPagRengCar = np;
        renderDetalleRenglon(body, data);
    }, per);
    wireInformeDeudaButton(body);
}

async function loadInforme(body, opts = {}) {
    if (!body) return;
    const preserveCarnet = !!(opts && opts.preserveCarnetFilters);
    window.clearTimeout(infCarnetDebTimer);
    infCarnetDebTimer = 0;
    setInfLoading('Cargando informe…');
    body.innerHTML = '<p class="ag-muted">Cargando…</p>';
    try {
    let asoc = qsAsoc();
    const reng = qsRenglon();
    if (informesAuthCtx && informesAuthCtx.rol === 'delegado') {
        const mine = informesAuthCtx.asociacion_id != null ? Number(informesAuthCtx.asociacion_id) : 0;
        if (mine < 1) {
            setInfError('Su usuario no tiene asociación asignada.');
            body.innerHTML = '<p class="error">Su usuario no tiene asociación asignada.</p>';
            return;
        }
        if (asoc > 0 && asoc !== mine) {
            setInfError('Solo puede ver el informe de su asociación.');
            body.innerHTML = `<p class="error">Solo puede consultar el informe de su asociación.</p><p><a class="btn-secondary" href="informes.html?asoc=${encodeURIComponent(String(mine))}">Ir a mi asociación</a></p>`;
            return;
        }
        if (!asoc) {
            asoc = mine;
            const u = new URL(window.location.href);
            u.searchParams.set('asoc', String(mine));
            if (!u.searchParams.has('vista')) {
                u.searchParams.set('vista', 'asoc-torneos');
            }
            history.replaceState(null, '', u.toString());
        }
    }
    if (!asoc) {
        if (vistaPreferidaInforme() === 'asoc-torneos') {
            const rRep = await fetchJson('api/finanza_reporte_asociaciones.php');
            if (!rRep.res.ok || !rRep.data.ok) {
                setInfError(rRep.data.message || 'No se pudo cargar el reporte.');
                body.innerHTML = `<p class="error">${reporteEsc(rRep.data.message || 'Error')}</p>`;
                return;
            }
            renderReporteAsociacionesTorneos(body, rRep.data);
            return;
        }
        infPag = 1;
        infPagAsocDet = 1;
        infPagRengMov = 1;
        infPagRengCar = 1;
        const tq = qsTorneoInforme();
        const r = await fetchJson(
            tq > 0 ? `api/informe_consolidado.php?torneo_id=${encodeURIComponent(String(tq))}` : 'api/informe_consolidado.php'
        );
        if (!r.res.ok || !r.data.ok) {
            const hint =
                r.res.status === 403
                    ? '<p class="ag-muted">Si es delegado, use el acceso desde <strong>Finanzas</strong> en su panel.</p>'
                    : '';
            setInfError(r.data.message || 'No se pudo cargar el informe consolidado.');
            body.innerHTML = `<p class="error">${reporteEsc(r.data.message || 'Error')}</p>${hint}`;
            return;
        }
        renderConsolidado(body, r.data);
        return;
    }
    if (!reng) {
        if (vistaPreferidaInforme() === 'asoc-torneos') {
            const rRep = await fetchJson(
                `api/finanza_reporte_asociaciones.php?asociacion_id=${encodeURIComponent(String(asoc))}`
            );
            if (!rRep.res.ok || !rRep.data.ok) {
                setInfError(rRep.data.message || 'No se pudo cargar el reporte.');
                body.innerHTML = `<p class="error">${reporteEsc(rRep.data.message || 'Error')}</p>`;
                return;
            }
            renderReporteAsociacionesTorneos(body, rRep.data);
            return;
        }
        infPagAsocDet = 1;
        infPagRengMov = 1;
        infPagRengCar = 1;
        const tqA = qsTorneoInforme();
        const urlAsoc = `api/informe_consolidado.php?asociacion_id=${encodeURIComponent(String(asoc))}${tqA > 0 ? `&torneo_id=${tqA}` : ''}`;
        const r = await fetchJson(urlAsoc);
        if (!r.res.ok || !r.data.ok) {
            setInfError(r.data.message || 'Error al cargar la asociación.');
            body.innerHTML = `<p class="error">${reporteEsc(r.data.message || 'Error')}</p>`;
            return;
        }
        renderDetalleAsociacion(body, r.data);
        return;
    }
    const renglonesValidos = ['afiliacion', 'carnet', 'traspaso', 'anualidad', 'inscripcion'];
    if (!renglonesValidos.includes(reng)) {
        const tqE = qsTorneoInforme();
        body.innerHTML = `<p class="error">Renglón no válido.</p><p><a class="btn-secondary" href="informes.html?asoc=${encodeURIComponent(String(asoc))}${tqE > 0 ? `&torneo_id=${tqE}` : ''}">Volver al desglose</a></p>`;
        return;
    }
    if (reng === 'carnet' && !preserveCarnet) {
        infCarnetQuery = '';
        infCarnetFiltroSol = 'todos';
    }
    infPagRengMov = 1;
    infPagRengCar = 1;
    if (!(reng === 'carnet' && preserveCarnet)) {
        infPagCarnetAff = 1;
    }
    const tqR = qsTorneoInforme();
    const r = await fetchJson(
        `api/informe_consolidado.php?asociacion_id=${encodeURIComponent(String(asoc))}&renglon=${encodeURIComponent(reng)}${tqR > 0 ? `&torneo_id=${tqR}` : ''}`
    );
    if (!r.res.ok || !r.data.ok) {
        setInfError(r.data.message || 'Error al cargar el detalle.');
        body.innerHTML = `<p class="error">${reporteEsc(r.data.message || 'Error')}</p>`;
        return;
    }
    renderDetalleRenglon(body, r.data);
    } catch (e) {
        console.error('loadInforme', e);
        const det = e && e.message ? String(e.message) : 'desconocido';
        setInfError(`Error al mostrar el informe: ${det}`);
        body.innerHTML = `<p class="error">Error inesperado al cargar el informe: ${reporteEsc(det)}</p>
            <p><button type="button" class="btn-secondary" id="inf-retry-btn">Reintentar</button>
            <a class="btn-secondary" href="panel.html">Volver al panel</a></p>`;
        body.querySelector('#inf-retry-btn')?.addEventListener('click', () => void loadInforme(body));
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    const gate = document.getElementById('inf-gate');
    const app = document.getElementById('inf-app');
    const body = document.getElementById('inf-body');
    try {
        setInfLoading('Verificando sesión…');
        const { res, data } = await fetchJson('api/auth_context.php');
        informesAuthCtx = data;
        const puede =
            res.ok && data.logged && data.puede_panel_admin && (data.rol === 'admingral' || data.rol === 'delegado');
        if (!puede) {
            if (gate) {
                gate.style.display = 'block';
                gate.innerHTML =
                    '<p class="error">Debe iniciar sesión como administración general o delegado de asociación.</p><p><a href="index.php" class="btn-secondary">Inicio</a></p>';
            }
            setInfError('Sin acceso. Inicie sesión de nuevo.');
            return;
        }
        if (gate) gate.style.display = 'none';
        if (app) app.style.display = 'block';
        if (!body) return;

        await loadInforme(body);

        document.getElementById('btn-inf-print')?.addEventListener('click', () => window.print());
        mountPortalPerfilHeader(document.getElementById('main-nav'));
    } catch (e) {
        console.error('informes init', e);
        setInfError('No se pudo iniciar la página de informes.');
        if (body) {
            body.innerHTML = '<p class="error">Error al iniciar. <a href="panel.html">Volver al panel</a></p>';
        }
    }
});
