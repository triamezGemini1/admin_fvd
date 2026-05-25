/**
 * Página de recibo / estado financiero consolidado por asociación (admin. gral.).
 * Incluye desglose de conceptos del informe por cada torneo con movimiento de la asociación.
 */

import {
    REPORTE_FILAS_POR_PAGINA,
    reporteEsc,
    reporteHtmlPaginador,
    reporteLigarPaginador,
    reporteSliceCliente,
    htmlFvdTableShell,
} from './reporte_tabla.js';
import { mountPortalPerfilHeader } from './portal_perfil_header.js';
import {
    htmlGruposInformeAcordeon,
    htmlReporteAsociacionesPorTorneo,
    resetPaginasDetalleFinanza,
    wirePaginacionDetalleGrupos,
    wirePaginacionReporteAsociacionesTorneo,
} from './finanzas_detalle_ui.js';
import {
    hrefInformeAsociacion,
    hrefReporteAsociacionesTorneo,
    hrefReporteParticipacion,
} from './finanzas_reporte_nav.js';
import {
    guardarTorneoFinanzas,
    htmlBannerTorneoCuentas,
    htmlBarraTorneoFinanzas,
    leerTorneoFinanzasGuardado,
    metaTorneoFinanzas,
    postActualizarDeudas,
    torneoFinanzasQs,
    wireBarraTorneoFinanzas,
} from './torneo_finanzas_ui.js';

async function fetchJson(url, options = {}) {
    const res = await fetch(url, { credentials: 'same-origin', ...options });
    const data = await res.json().catch(() => ({}));
    return { res, data };
}

function qsId() {
    const u = new URL(window.location.href);
    const n = parseInt(u.searchParams.get('id') || '0', 10);
    return n > 0 ? n : 0;
}

function qsTorneoId() {
    const u = new URL(window.location.href);
    const n = parseInt(u.searchParams.get('torneo_id') || '0', 10);
    return n > 0 ? n : 0;
}

function qsGrupoEventoId() {
    const u = new URL(window.location.href);
    const n = parseInt(u.searchParams.get('grupo_evento_id') || '0', 10);
    return n > 0 ? n : 0;
}

function qsConsolidarCampeonato() {
    const u = new URL(window.location.href);
    if (!u.searchParams.has('consolidar_campeonato')) {
        return true;
    }
    return parseInt(u.searchParams.get('consolidar_campeonato') || '1', 10) !== 0;
}

let finAsoPag = { pag: 1 };
const FIN_ASO_PAG_PREFIX = 'fin-aso-inf';
const FIN_ASO_REP_PREFIX = 'fin-aso-rep';

/** @type {Record<string, unknown>|null} */
let finAsoReporteCache = null;

function etiquetaListaTorneo(t) {
    const nom = t.nombre ? String(t.nombre) : '';
    const tid = t.torneo_id != null ? parseInt(String(t.torneo_id), 10) : 0;
    const base = nom.trim() !== '' ? nom : `Torneo ${tid}`;
    const f = t.fechator != null && String(t.fechator).trim() !== '' ? ` — ${String(t.fechator).slice(0, 10)}` : '';
    const fin = t.finalizado_en ? ' [finalizado]' : '';
    const sinNom = t.tiene_nomina === false ? ' · sin nómina en esta asoc.' : '';
    return `${base}${f}${fin}${sinNom}`;
}

function torneosListaBarraFinAsoc(d, torneoUrl) {
    const raw = Array.isArray(d.torneos_para_selector) ? d.torneos_para_selector : d.torneos_con_movimiento || [];
    const ids = new Set(raw.map((t) => parseInt(String(t.torneo_id ?? '0'), 10)).filter((n) => n > 0));
    const out = [...raw];
    if (torneoUrl > 0 && !ids.has(torneoUrl)) {
        out.unshift({
            torneo_id: torneoUrl,
            nombre: d.vista_torneo_nombre || `Torneo ${torneoUrl}`,
            tiene_nomina: false,
        });
    }
    return out;
}

document.addEventListener('DOMContentLoaded', async () => {
    const gate = document.getElementById('fin-asoc-gate');
    const app = document.getElementById('fin-asoc-app');
    let id = qsId();
    let torneoUrl = qsTorneoId();
    let grupoUrl = qsGrupoEventoId();
    let consolidarCampeonato = qsConsolidarCampeonato();
    if (torneoUrl < 1 && leerTorneoFinanzasGuardado() > 0) {
        torneoUrl = leerTorneoFinanzasGuardado();
        const u0 = new URL(window.location.href);
        u0.searchParams.set('torneo_id', String(torneoUrl));
        history.replaceState(null, '', u0.toString());
    }
    const { res, data } = await fetchJson('api/auth_context.php');
    const mine = data.asociacion_id != null ? parseInt(String(data.asociacion_id), 10) : 0;
    const puedeFin =
        res.ok &&
        data.logged &&
        data.puede_panel_admin &&
        (data.rol === 'admingral' || (data.rol === 'delegado' && mine > 0));
    if (!puedeFin) {
        if (gate) {
            gate.style.display = 'block';
            gate.innerHTML =
                '<p class="error">Debe iniciar sesión como administración general o delegado de asociación.</p><p><a href="index.php" class="btn-secondary">Inicio</a></p>';
        }
        return;
    }
    if (data.rol === 'delegado') {
        if (mine < 1) {
            if (gate) {
                gate.style.display = 'block';
                gate.innerHTML =
                    '<p class="error">Su usuario no tiene asociación asignada.</p><p><a href="panel.html" class="btn-secondary">Volver al panel</a></p>';
            }
            return;
        }
        if (id > 0 && id !== mine) {
            if (gate) {
                gate.style.display = 'block';
                gate.innerHTML = `<p class="error">Solo puede ver las finanzas de su asociación.</p><p><a class="btn-secondary" href="finanzas_asociacion.html?id=${encodeURIComponent(String(mine))}">Abrir estado de mi asociación</a></p>`;
            }
            return;
        }
        if (id < 1) {
            id = mine;
            const u = new URL(window.location.href);
            u.searchParams.set('id', String(mine));
            history.replaceState(null, '', u.toString());
        }
    } else if (id < 1) {
        if (gate) {
            gate.style.display = 'block';
            gate.innerHTML =
                '<p class="error">Indique <code>?id=</code> de asociación en la URL.</p><p><a href="panel.html" class="btn-secondary">Volver al panel</a></p>';
        }
        return;
    }
    if (gate) gate.style.display = 'none';
    if (app) app.style.display = 'block';

    const body = document.getElementById('fin-asoc-body');
    const title = document.getElementById('fin-asoc-title');
    const lead = document.getElementById('fin-asoc-lead');
    if (!body) return;
    body.innerHTML = '<p class="ag-muted">Cargando…</p>';

    const qTor =
        torneoUrl > 0
            ? `&torneo_id=${encodeURIComponent(String(torneoUrl))}`
            : grupoUrl > 0
              ? `&grupo_evento_id=${encodeURIComponent(String(grupoUrl))}`
              : `&consolidar_campeonato=${consolidarCampeonato ? '1' : '0'}`;
    const r = await fetchJson(`api/finanza_asociacion.php?id=${encodeURIComponent(String(id))}${qTor}`);
    if (!r.res.ok || !r.data.ok) {
        body.innerHTML = `<p class="error">${reporteEsc(r.data.message || 'Error al cargar')}</p>`;
        return;
    }
    const d = r.data.data;
    const a = d.asociacion || {};
    const t = d.totales || {};
    const vistaNombre = d.vista_torneo_nombre != null ? String(d.vista_torneo_nombre) : '';
    const vistaId = d.vista_torneo_id != null ? parseInt(String(d.vista_torneo_id), 10) : 0;
    const grupoVista = d.grupo_evento_id != null ? parseInt(String(d.grupo_evento_id), 10) : grupoUrl;
    consolidarCampeonato = d.consolidar_campeonato !== false && consolidarCampeonato;
    const gruposInforme = Array.isArray(d.grupos_informe) ? d.grupos_informe : [];
    const torneoVista = torneoUrl > 0 ? torneoUrl : vistaId;
    if (torneoVista > 0) guardarTorneoFinanzas(torneoVista);
    const puedeActualizar = data.rol === 'admingral';
    const puedeGestionarCargos = !!(d.puede_gestionar_cargos && data.rol === 'admingral');
    const cargosList = Array.isArray(d.cargos) ? d.cargos : [];
    const porConcepto = Array.isArray(d.por_concepto) ? d.por_concepto : [];
    const listaBarra = torneosListaBarraFinAsoc(d, torneoUrl);
    const tq = torneoFinanzasQs(torneoVista);

    let nomCampeonatoGrupo = '';
    if (grupoVista > 0 && gruposInforme.length > 0) {
        const g0 = gruposInforme.find((g) => parseInt(String(g.grupo_evento_id ?? '0'), 10) === grupoVista);
        if (g0 && g0.etiqueta) nomCampeonatoGrupo = String(g0.etiqueta);
    }
    const nomTorneoBanner =
        grupoVista > 0 && nomCampeonatoGrupo
            ? `${nomCampeonatoGrupo} (campeonato consolidado)`
            : torneoUrl > 0 && vistaNombre.trim() !== ''
              ? vistaNombre
              : torneoUrl < 1 && grupoVista < 1
                ? 'Todas las nóminas — vista por campeonato'
                : vistaNombre.trim() !== ''
                  ? vistaNombre
                  : `Torneo ${torneoVista}`;
    if (title) {
        title.textContent = `Estado financiero — ${a.nombre || 'Asociación'}`;
    }
    if (lead) {
        lead.style.display = 'block';
        lead.className = 'ag-muted ag-fin-page-lead ag-fin-page-lead--torneo';
        const qv =
            torneoUrl > 0 || grupoVista > 0
                ? ` · <a class="ag-inline-link" href="finanzas_asociacion.html?id=${encodeURIComponent(String(id))}&consolidar_campeonato=1">Ver todos (acordeón campeonatos)</a>`
                : '';
        lead.innerHTML = `Resumen de cuentas para <strong>${reporteEsc(nomTorneoBanner)}</strong>${qv}`;
    }

    const pagos = d.pagos || [];
    const listaSelector = Array.isArray(d.torneos_para_selector)
        ? d.torneos_para_selector
        : d.torneos_con_movimiento || [];

    const render = () => {
        const per = REPORTE_FILAS_POR_PAGINA;
        const slicePag = reporteSliceCliente(pagos, finAsoPag.pag);
        const puedeVerificar = data.rol === 'admingral';

        const pagerPag = reporteHtmlPaginador('fin-aso-pag', finAsoPag.pag, pagos.length, per);

        const etiquetaTipoPago = (tp) => reporteEsc(String(tp || '—').replace(/_/g, ' '));

        const pagoRows =
            slicePag.length > 0
                ? slicePag
                      .map((p) => {
                          const pid = p.id != null ? parseInt(String(p.id), 10) : 0;
                          const ver = parseInt(String(p.verificado ?? '0'), 10) === 1;
                          const verLab = ver
                              ? '<span class="ag-recibo-op-badge ag-recibo-op-badge--ok">Sí</span>'
                              : '<span class="ag-recibo-op-badge ag-recibo-op-badge--no">No</span>';
                          const recUrl =
                              pid > 0
                                  ? `informe_recibo.html?asociacion_id=${encodeURIComponent(String(id))}&pago_id=${encodeURIComponent(String(pid))}`
                                  : '#';
                          const fvd = p.torneo_id === 0 || p.torneo_id === '0';
                          const btnVer =
                              puedeVerificar && pid > 0 && fvd && !ver
                                  ? `<button type="button" class="btn-secondary btn-sm fin-asoc-btn-verificar" data-pago="${pid}">Verificar pago</button>`
                                  : '—';
                          return `<tr><td>${reporteEsc(p.fecha_pago)}</td><td class="ag-num">${reporteEsc(String(p.monto_eur))} €</td><td class="ag-num">${reporteEsc(
                              String(p.monto_bs)
                          )} Bs</td><td>${etiquetaTipoPago(p.tipo_pago)}</td><td>${verLab}</td><td>${reporteEsc(p.referencia)}</td><td>${reporteEsc(
                              p.banco
                          )}</td><td><a class="btn-secondary btn-sm" href="${recUrl}">Recibo</a></td><td>${btnVer}</td></tr>`;
                      })
                      .join('')
                : '<tr><td colspan="9">Sin pagos</td></tr>';

        const pendVer = t.pagado_pendiente_verificacion_eur != null ? String(t.pagado_pendiente_verificacion_eur) : '0';
        const tm = metaTorneoFinanzas({
            id: torneoVista,
            nombre: nomTorneoBanner,
            finalizado_en: null,
        });
        const bannerTor = htmlBannerTorneoCuentas({
            multiple: torneoUrl < 1 && grupoVista < 1,
            nombre: reporteEsc(nomTorneoBanner),
            torneoId: torneoUrl > 0 ? torneoUrl : tm.id,
            alcance: `Asociación: ${reporteEsc(a.nombre || '')}`,
        });
        const barraTor = `${htmlBarraTorneoFinanzas({
            prefix: 'fin-asoc',
            torneos: listaBarra,
            torneoSeleccionado: torneoUrl > 0 ? torneoUrl : 0,
            torneosEstructurado: d.torneos_estructurado || null,
            showCampeonatoConsolidado: true,
            grupoSeleccionado: grupoVista,
            requerirTorneo: false,
            showActualizar: puedeActualizar,
            showOpcionGeneral: true,
            showConsolidar: false,
        })}
        <label class="ag-fin-otros-check ag-fin-camp-cons-check">
            <input type="checkbox" id="fin-asoc-cons-camp" ${consolidarCampeonato && torneoUrl < 1 ? 'checked' : ''} ${torneoUrl > 0 ? 'disabled' : ''} />
            <span>Acordeón por campeonato (cuenta consolidada)</span>
        </label>`;
        let bloquesList = d.informes_por_torneo || [];
        if (torneoUrl > 0) {
            bloquesList = bloquesList.filter((b) => {
                const tor = b.torneo_activo;
                const tid = tor && tor.id != null ? parseInt(String(tor.id), 10) : 0;
                return tid === torneoUrl;
            });
        }
        const idsBloque = new Set(
            bloquesList
                .map((b) => {
                    const tor = b.torneo_activo;
                    return tor && tor.id != null ? parseInt(String(tor.id), 10) : 0;
                })
                .filter((n) => n > 0)
        );
        const avisoSinBloque =
            torneoUrl > 0 && !idsBloque.has(torneoUrl)
                ? `<div class="ag-fin-aviso-nomina" role="status"><p class="ag-muted">El torneo seleccionado <strong>no tiene nómina FVD</strong> (<code>movimiento_torneo</code>) para <strong>${reporteEsc(
                      a.nombre || 'esta asociación'
                  )}</strong>. Más abajo solo aparecen torneos con conceptos consolidados.</p></div>`
                : '';
        const usarAcordeon =
            consolidarCampeonato && torneoUrl < 1 && gruposInforme.length > 0 && data.rol !== 'delegado';
        const navFin = {
            asocId: id,
            torneoId: torneoUrl > 0 ? torneoUrl : torneoVista,
            grupoId: grupoVista > 0 ? grupoVista : 0,
        };
        const reporteItem = {
            asociacion: a,
            informes_por_torneo: bloquesList,
            estado_cuenta: {
                deuda_eur: t.deuda_eur,
                pagado_eur: t.pagado_eur,
                saldo_eur: t.saldo_eur,
                nomina_eur: t.nomina_eur,
                cargos_manuales_eur: t.cargos_manuales_eur,
            },
            n_torneos: bloquesList.length,
        };
        finAsoReporteCache = { items: [reporteItem], usarAcordeon, gruposInforme, navFin };
        const bloquesHtml = usarAcordeon
            ? htmlGruposInformeAcordeon(gruposInforme, FIN_ASO_PAG_PREFIX, navFin)
            : htmlReporteAsociacionesPorTorneo([reporteItem], FIN_ASO_REP_PREFIX, { soloUna: true });

        const hoy = new Date().toISOString().slice(0, 10);
        const cargoRows =
            cargosList.length > 0
                ? cargosList
                      .slice(0, 25)
                      .map(
                          (c) => `<tr>
            <td>${reporteEsc(String(c.fecha_emision || '—'))}</td>
            <td>${reporteEsc(String(c.concepto || '—'))}</td>
            <td class="ag-num">${reporteEsc(String(c.monto_eur))} €</td>
            <td>${reporteEsc(String(c.referencia || '—'))}</td>
            <td>${reporteEsc(String(c.notas || '—'))}</td>
        </tr>`
                      )
                      .join('')
                : '<tr><td colspan="5" class="ag-muted">Sin cargos registrados.</td></tr>';
        const conceptoChips =
            porConcepto.length > 0
                ? porConcepto
                      .map(
                          (pc) =>
                              `<span class="ag-fin-concepto-chip">${reporteEsc(String(pc.concepto || ''))}: <strong>${reporteEsc(String(pc.monto_eur))} €</strong></span>`
                      )
                      .join(' ')
                : '<span class="ag-muted">Sin desglose por concepto.</span>';
        const seccionCargos = puedeGestionarCargos
            ? `<section class="ag-fin-cargos" id="fin-aso-cargos">
        <h2 class="ag-subtitle ag-fin-section-title">Actualizar deuda — cargos manuales</h2>
        <p class="ag-muted ag-fin-pago-hint">Registre conceptos adicionales (multas, ajustes, etc.) para actualizar el estado de cuenta de la asociación. Los importes de nómina se generan con «Actualizar deudas desde movimiento».</p>
        <form id="fin-aso-cargo-form" class="ag-fin-cargo-form">
            <label>Concepto <input type="text" name="concepto" required maxlength="120" class="ag-fin-input" placeholder="Ej. multa, ajuste anual"></label>
            <label>Monto € <input type="number" name="monto_eur" required min="0.01" step="0.01" class="ag-fin-input ag-fin-input--num"></label>
            <label>Fecha emisión <input type="date" name="fecha_emision" required value="${reporteEsc(hoy)}" class="ag-fin-input"></label>
            <label>Referencia <input type="text" name="referencia" maxlength="80" class="ag-fin-input"></label>
            <label>Notas <input type="text" name="notas" maxlength="255" class="ag-fin-input"></label>
            <button type="submit" class="btn-primary">Registrar cargo</button>
            <span id="fin-aso-cargo-fb" class="ag-muted" role="status"></span>
        </form>
        <p class="ag-fin-conceptos-resumen">${conceptoChips}</p>
        ${htmlFvdTableShell(`<table class="fvd-table admin-crud-table admin-crud-table--compact text-sm">
            <thead><tr><th>Fecha</th><th>Concepto</th><th class="ag-num">€</th><th>Ref.</th><th>Notas</th></tr></thead>
            <tbody>${cargoRows}</tbody>
        </table>`)}
        </section>`
            : '';

        body.innerHTML = `<div class="ag-fin-shell ag-fvd-reporte-datos">
        <div id="fin-aso-torneo-bar">${bannerTor}${barraTor}</div>
        <div class="ag-fin-recibo-sum ag-fin-recibo-sum--fvd">
            <span><strong>Total cuenta</strong> ${reporteEsc(String(t.deuda_eur))} €</span>
            <span><strong>Nómina (todos los torneos)</strong> ${reporteEsc(String(t.nomina_eur ?? 0))} €</span>
            <span><strong>Cargos manuales</strong> ${reporteEsc(String(t.cargos_manuales_eur ?? 0))} €</span>
            <span><strong>Pagado verificado</strong> ${reporteEsc(String(t.pagado_eur))} €</span>
            <span><strong>Pendiente verificación</strong> ${reporteEsc(pendVer)} €</span>
            <span><strong>Saldo</strong> ${reporteEsc(String(t.saldo_eur))} €</span>
        </div>
        <p class="ag-fin-asoc-links">
            <a class="btn-secondary btn-sm" href="${hrefReporteAsociacionesTorneo({ asocId: id })}">Reporte por torneo</a>
            <a class="btn-secondary btn-sm" href="${hrefReporteParticipacion()}">Participación (columnas)</a>
            <a class="btn-secondary btn-sm" href="${hrefInformeAsociacion(id, { torneoId: torneoUrl > 0 ? torneoUrl : torneoVista, grupoId: grupoVista })}">Desglose por renglón</a>
            ${puedeGestionarCargos ? `<a class="btn-secondary btn-sm" href="#fin-aso-cargos">Registrar cargo</a>` : ''}
            ${torneoUrl > 0 || grupoVista > 0 ? `<a class="btn-secondary btn-sm" href="finanzas_asociacion.html?id=${encodeURIComponent(String(id))}&consolidar_campeonato=1">Vista acordeón (todos)</a>` : ''}
        </p>
        ${seccionCargos}
        <div class="reporte-vista ag-fin-informe-torneos admin-fin-scroll text-sm ag-fvd-reporte-datos">
        <h2 class="ag-subtitle ag-fin-section-title">Conceptos por torneo — ${reporteEsc(nomTorneoBanner)}</h2>
        ${avisoSinBloque}
        <p class="ag-fin-hint-copy">${
            usarAcordeon
                ? 'Cada campeonato muestra <strong>totales consolidados</strong> y, al expandir, el detalle por variante (torneo).'
                : 'Listado de <strong>cada torneo</strong> con su resumen (afiliación, anualidad, carnet, traspaso, inscritos). Pulse <strong>🔍 Detalle</strong> en un renglón para ver atletas.'
        }</p>
        ${bloquesHtml}
        </div>
        <div class="reporte-vista ag-fin-reporte-vista">
        <h2 class="ag-subtitle ag-fin-section-title" id="fin-aso-pagos">Pagos registrados</h2>
        <p class="ag-muted ag-fin-pago-hint">${
            puedeVerificar
                ? 'Solo los pagos <strong>verificados</strong> descuentan del saldo. Use «Verificar pago» si la conciliación automática no aplicó. Recibo: una operación concreta.'
                : 'Solo los pagos <strong>verificados</strong> por la FVD descuentan del saldo. Puede abrir el <strong>Recibo</strong> de cada operación. La verificación manual la realiza administración general.'
        }</p>
        ${pagerPag}
        ${htmlFvdTableShell(`<table class="fvd-table admin-crud-table admin-crud-table--compact text-sm">
            <thead><tr><th>Fecha</th><th>€</th><th>Bs</th><th>Tipo</th><th>Verif.</th><th>Ref.</th><th>Banco</th><th>Recibo</th><th>Acción</th></tr></thead>
            <tbody>${pagoRows}</tbody>
        </table>`)}
        </div></div>`;

        reporteLigarPaginador('fin-aso-pag', finAsoPag.pag, pagos.length, (np) => {
            finAsoPag.pag = np;
            render();
        }, per);

        if (usarAcordeon) {
            wirePaginacionDetalleGrupos(body, gruposInforme, FIN_ASO_PAG_PREFIX, () => render());
        } else if (finAsoReporteCache && finAsoReporteCache.items) {
            resetPaginasDetalleFinanza(FIN_ASO_REP_PREFIX);
            wirePaginacionReporteAsociacionesTorneo(body, finAsoReporteCache.items, FIN_ASO_REP_PREFIX, () =>
                render()
            );
        }

        body.querySelectorAll('.fin-asoc-btn-verificar').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const pid = parseInt(String(btn.getAttribute('data-pago') || '0'), 10);
                if (pid < 1) return;
                const { res: rv, data: dv } = await fetchJson('api/finanza_pago_verificar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ pago_id: pid, asociacion_id: id }),
                });
                if (rv.ok && dv.ok) {
                    window.location.reload();
                    return;
                }
                window.alert(dv.message || 'No se pudo verificar el pago.');
            });
        });

        const cargoForm = body.querySelector('#fin-aso-cargo-form');
        if (cargoForm) {
            cargoForm.addEventListener('submit', async (ev) => {
                ev.preventDefault();
                const fb = body.querySelector('#fin-aso-cargo-fb');
                const fd = new FormData(cargoForm);
                const concepto = String(fd.get('concepto') || '').trim();
                const monto = parseFloat(String(fd.get('monto_eur') || '0'));
                const fecha = String(fd.get('fecha_emision') || '').trim();
                const referencia = String(fd.get('referencia') || '').trim();
                const notas = String(fd.get('notas') || '').trim();
                if (!concepto || !(monto > 0) || !fecha) {
                    if (fb) fb.textContent = 'Complete concepto, monto y fecha.';
                    return;
                }
                if (fb) fb.textContent = 'Guardando…';
                const payload = {
                    asociacion_id: id,
                    concepto,
                    monto_eur: monto,
                    fecha_emision: fecha,
                };
                if (referencia) payload.referencia = referencia;
                if (notas) payload.notas = notas;
                const { res: rc, data: dc } = await fetchJson('api/finanza_cargo.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                if (rc.ok && dc.ok) {
                    window.location.hash = 'fin-aso-cargos';
                    window.location.reload();
                    return;
                }
                if (fb) fb.textContent = dc.message || 'No se pudo registrar el cargo.';
            });
        }

        body.querySelector('#fin-asoc-cons-camp')?.addEventListener('change', (e) => {
            const on = !!/** @type {HTMLInputElement} */ (e.target).checked;
            const base = `finanzas_asociacion.html?id=${encodeURIComponent(String(id))}`;
            window.location.assign(`${base}&consolidar_campeonato=${on ? '1' : '0'}`);
        });

        wireBarraTorneoFinanzas(body, 'fin-asoc', {
            onTorneoChange: (v) => {
                const base = `finanzas_asociacion.html?id=${encodeURIComponent(String(id))}`;
                const sel = body.querySelector('#fin-asoc-sel-torneo');
                const raw = sel ? String(/** @type {HTMLSelectElement} */ (sel).value || '') : String(v);
                if (raw.startsWith('g-')) {
                    const gid = parseInt(raw.slice(2), 10) || 0;
                    if (gid > 0) {
                        window.location.assign(
                            `${base}&grupo_evento_id=${encodeURIComponent(String(gid))}&consolidar_campeonato=1`
                        );
                    }
                    return;
                }
                if (v < 1) {
                    window.location.assign(`${base}&consolidar_campeonato=${consolidarCampeonato ? '1' : '0'}`);
                    return;
                }
                guardarTorneoFinanzas(v);
                window.location.assign(`${base}&torneo_id=${encodeURIComponent(String(v))}`);
            },
            onActualizar: puedeActualizar
                ? async () => {
                      const fb = body.querySelector('#fin-asoc-deuda-fb');
                      if (fb) fb.textContent = 'Procesando…';
                      const tidAct = torneoVista > 0 ? torneoVista : torneoUrl;
                      const pr = await postActualizarDeudas(fetchJson, tidAct);
                      if (fb) fb.textContent = pr.data.message || (pr.res.ok && pr.data.ok ? 'Listo.' : 'Error');
                      if (pr.res.ok && pr.data.ok) window.location.reload();
                  }
                : undefined,
        });

        if (torneoUrl > 0) {
            requestAnimationFrame(() => {
                document.getElementById(`fin-torneo-${torneoUrl}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }
        if (
            window.location.hash === '#fin-aso-torneo-bar' ||
            window.location.hash === '#fin-aso-otros-torneos' ||
            window.location.hash === '#fin-aso-cargos'
        ) {
            requestAnimationFrame(() => {
                const target =
                    window.location.hash === '#fin-aso-cargos'
                        ? document.getElementById('fin-aso-cargos')
                        : document.getElementById('fin-aso-torneo-bar');
                target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }
    };

    render();
    document.getElementById('fin-asoc-print')?.addEventListener('click', () => window.print());
    mountPortalPerfilHeader(document.getElementById('main-nav'));
});
