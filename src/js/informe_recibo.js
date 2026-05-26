/**
 * Recibo de pago — únicamente los campos de la operación (sin contenidos adicionales).
 */
import { reporteEsc } from './reporte_tabla.js';
import { initFvdReportPage } from './fvd_report_page.js';
import { initDelegadoTorneosBar, persistJornadaDesdeAuth } from './delegado_torneos_bar.js';

async function fetchJson(url, options = {}) {
    const res = await fetch(url, { credentials: 'same-origin', ...options });
    const data = await res.json().catch(() => ({}));
    return { res, data };
}

function qsParams() {
    const u = new URL(window.location.href);
    const asoc = parseInt(u.searchParams.get('asociacion_id') || u.searchParams.get('asoc') || '0', 10);
    const pago = parseInt(u.searchParams.get('pago_id') || '0', 10);
    return { asoc: asoc > 0 ? asoc : 0, pago: pago > 0 ? pago : 0 };
}

function fmtEur(n) {
    const v = typeof n === 'number' && !Number.isNaN(n) ? n : parseFloat(String(n ?? '0').replace(',', '.')) || 0;
    return `${v.toFixed(2)} €`;
}

function fmtBs(n) {
    const v = typeof n === 'number' && !Number.isNaN(n) ? n : parseFloat(String(n ?? '0').replace(',', '.')) || 0;
    return `${v.toFixed(2)} Bs`;
}

function etiquetaTipoPago(t) {
    const s = String(t || '').replace(/_/g, ' ');
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : '—';
}

function textoVerificacion(rec) {
    const verif = rec.verificado === true;
    const conc = rec.conciliacion && typeof rec.conciliacion === 'object' ? rec.conciliacion : null;
    const msgEntidad = conc && conc.mensaje_entidad ? String(conc.mensaje_entidad) : '';
    const notaAdmin = conc && conc.nota ? String(conc.nota) : '';

    if (!verif) {
        return {
            badge: '<span class="ag-recibo-op-badge ag-recibo-op-badge--no">No verificado</span>',
            detalle: `${msgEntidad || 'No contabilizado contra el total de la deuda hasta verificación.'} La administración general puede verificar manualmente en Finanzas.`,
            extra: notaAdmin ? `<p class="ag-recibo-op-nota">${reporteEsc(notaAdmin)}</p>` : '',
        };
    }
    if (rec.conciliacion_manual_admin === true) {
        return {
            badge: '<span class="ag-recibo-op-badge ag-recibo-op-badge--ok">Verificado (manual)</span>',
            detalle: msgEntidad || 'Verificación manual por administración general.',
            extra: notaAdmin ? `<p class="ag-recibo-op-nota">${reporteEsc(notaAdmin)}</p>` : '',
        };
    }
    if (rec.conciliacion_automatica === true) {
        return {
            badge: '<span class="ag-recibo-op-badge ag-recibo-op-badge--ok">Verificado (conciliación automática)</span>',
            detalle: msgEntidad || 'Conciliación automática con la entidad bancaria; operación contabilizada contra el saldo.',
            extra: '',
        };
    }
    return {
        badge: '<span class="ag-recibo-op-badge ag-recibo-op-badge--ok">Verificado</span>',
        detalle: msgEntidad || 'Operación contabilizada contra el saldo.',
        extra: notaAdmin ? `<p class="ag-recibo-op-nota">${reporteEsc(notaAdmin)}</p>` : '',
    };
}

document.addEventListener('DOMContentLoaded', async () => {
    const gate = document.getElementById('rec-gate');
    const app = document.getElementById('rec-app');
    const head = document.getElementById('rec-head');
    const body = document.getElementById('rec-body');
    const { asoc, pago } = qsParams();

    const { res, data } = await fetchJson('api/auth_context.php');
    const mine = data.asociacion_id != null ? parseInt(String(data.asociacion_id), 10) : 0;
    const puede =
        res.ok &&
        data.logged &&
        data.puede_panel_admin &&
        (data.rol === 'admingral' || (data.rol === 'delegado' && mine > 0));
    if (!puede) {
        if (gate) {
            gate.style.display = 'block';
            gate.innerHTML =
                '<p class="error">Debe iniciar sesión como administración general o delegado de asociación.</p><p><a href="index.php" class="btn-secondary">Inicio</a></p>';
        }
        return;
    }
    if (data.rol === 'delegado' && asoc > 0 && asoc !== mine) {
        if (gate) {
            gate.style.display = 'block';
            gate.innerHTML =
                mine > 0
                    ? `<p class="error">Solo puede ver recibos de su asociación.</p><p><a class="btn-secondary" href="finanzas_asociacion.html?id=${encodeURIComponent(String(mine))}#fin-aso-pagos">Finanzas de mi asociación</a></p>`
                    : '<p class="error">Sin asociación asignada.</p><p><a href="panel.html" class="btn-secondary">Panel</a></p>';
        }
        return;
    }
    if (asoc < 1 || pago < 1) {
        if (gate) {
            gate.style.display = 'block';
            gate.innerHTML = `<p class="error">Indique <code>asociacion_id</code> y <code>pago_id</code> en la URL.</p>
                <p class="ag-muted">Ej.: <code>informe_recibo.html?asociacion_id=4&amp;pago_id=12</code> (desde Finanzas → Recibo por operación).</p>
                <p><a href="informes.html" class="btn-secondary">Informes</a>
                ${asoc > 0 ? `<a class="btn-secondary" href="finanzas_asociacion.html?id=${encodeURIComponent(String(asoc))}#fin-aso-pagos">Finanzas</a>` : ''}</p>`;
        }
        return;
    }
    if (gate) gate.style.display = 'none';
    if (app) app.style.display = 'block';
    if (!body || !head) return;

    persistJornadaDesdeAuth(data);
    initFvdReportPage();
    void initDelegadoTorneosBar();

    body.innerHTML = '<p class="ag-muted">Cargando recibo…</p>';

    const r = await fetchJson(`api/informe_recibo.php?asociacion_id=${encodeURIComponent(String(asoc))}&pago_id=${encodeURIComponent(String(pago))}`);
    if (!r.res.ok || !r.data.ok) {
        body.innerHTML = `<p class="error">${reporteEsc(r.data.message || 'Error al cargar el recibo')}</p>`;
        return;
    }

    let rec = r.data.recibo || {};
    const a = rec.asociacion || {};
    const opId = rec.operacion_id != null ? String(rec.operacion_id) : String(pago);
    const v = textoVerificacion(rec);
    const verif = rec.verificado === true;
    const lineaFechaVerif = verif
        ? `<p class="ag-muted ag-recibo-op-verif-fecha">Verificado el: ${reporteEsc(String(rec.verificado_en || '—'))}</p>`
        : '<p class="ag-muted ag-recibo-op-verif-fecha">Fecha de verificación: pendiente (no descuenta del saldo hasta verificar).</p>';

    const tasaVal = rec.tasa_vcb_bs_por_eur != null ? String(rec.tasa_vcb_bs_por_eur) : '';

    const esAdminRec = data.rol === 'admingral';
    const tasaEditorHtml = esAdminRec
        ? `<div class="ag-recibo-tasa-row">
                    <label class="ag-recibo-tasa-label"><span class="visually-hidden">Tasa editable</span>
                    <input type="number" step="any" min="0.0001" class="ag-recibo-tasa-input" id="rec-tasa-input" value="${reporteEsc(tasaVal)}" aria-describedby="rec-tasa-hint"></label>
                    <button type="button" class="btn-secondary btn-sm" id="rec-tasa-guardar">Aplicar tasa</button>
                    <span id="rec-tasa-msg" class="ag-muted" role="status"></span>
                    </div>
                    <p id="rec-tasa-hint" class="ag-muted" style="margin:0.35rem 0 0;">Recalcula el monto en bolívares de esta operación según el EUR abonado.</p>`
        : `<span class="ag-num">${reporteEsc(tasaVal || '—')}</span>
                    <p class="ag-muted" style="margin:0.35rem 0 0;">Solo lectura. La tasa la gestiona administración general.</p>`;

    head.innerHTML = '<h1 class="ag-inf-report-title">Recibo de pago</h1>';

    body.innerHTML = `
        <div class="ag-recibo-op ag-fvd-reporte-datos">
            <dl class="ag-recibo-op-dl">
                <div><dt>Asociación (operación)</dt><dd>${reporteEsc(a.nombre || '')} · <span class="ag-num">Nº ${reporteEsc(opId)}</span></dd></div>
                <div><dt>Monto de la deuda</dt><dd class="ag-num">${fmtEur(rec.deuda_total_eur)} <span class="ag-muted">(total cargos EUR)</span></dd></div>
                <div><dt>Monto abonado</dt><dd class="ag-num">${fmtEur(rec.monto_abonado_eur)} <span class="ag-muted">(esta operación)</span></dd></div>
                <div><dt>Saldo pendiente</dt><dd class="ag-num"><strong>${fmtEur(rec.saldo_pendiente_eur)}</strong> <span class="ag-muted">(EUR; solo descuentan pagos verificados)</span></dd></div>
                <div><dt>Fecha</dt><dd>${reporteEsc(String(rec.fecha || '—'))}</dd></div>
                <div><dt>Tipo de pago</dt><dd>${reporteEsc(etiquetaTipoPago(rec.tipo_pago))}</dd></div>
                <div><dt>Monto a pagar en EUR <span class="ag-muted">(en base al saldo deudor)</span></dt><dd class="ag-num">${fmtEur(rec.monto_eur_segun_saldo_deudor)}</dd></div>
                <div><dt>Tasa de cambio VCB (Bs por 1 EUR)</dt><dd>
                    ${tasaEditorHtml}
                </dd></div>
                <div><dt>Equivalencia en Bs</dt><dd class="ag-num" id="rec-equiv-bs">${fmtBs(rec.equivalencia_bs)}</dd></div>
                <div><dt>Banco</dt><dd>${reporteEsc(rec.banco || '—')}</dd></div>
                <div><dt>Referencia bancaria</dt><dd>${reporteEsc(rec.referencia || '—')}</dd></div>
                <div><dt>Verificación y conciliación</dt><dd class="ag-recibo-op-verif">${v.badge}<p class="ag-recibo-op-conc">${reporteEsc(v.detalle)}</p>${v.extra}${lineaFechaVerif}</dd></div>
            </dl>
        </div>`;

    function aplicarTasaLocal() {
        const inp = document.getElementById('rec-tasa-input');
        const eq = document.getElementById('rec-equiv-bs');
        if (!inp || !eq) return;
        const t = parseFloat(String(inp.value).replace(',', '.')) || 0;
        const eur =
            typeof rec.monto_abonado_eur === 'number' && !Number.isNaN(rec.monto_abonado_eur)
                ? rec.monto_abonado_eur
                : parseFloat(String(rec.monto_abonado_eur ?? '0').replace(',', '.')) || 0;
        if (t > 0 && eur >= 0) {
            eq.textContent = fmtBs(Math.round(eur * t * 100) / 100);
        }
    }

    if (esAdminRec) {
        document.getElementById('rec-tasa-input')?.addEventListener('input', aplicarTasaLocal);

        document.getElementById('rec-tasa-guardar')?.addEventListener('click', async () => {
            const inp = document.getElementById('rec-tasa-input');
            const msg = document.getElementById('rec-tasa-msg');
            if (!inp) return;
            const t = parseFloat(String(inp.value).replace(',', '.')) || 0;
            if (t <= 0) {
                if (msg) msg.textContent = 'Indique una tasa mayor a cero.';
                return;
            }
            if (msg) msg.textContent = 'Guardando…';
            const pr = await fetchJson('api/informe_recibo_actualizar_tasa.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    asociacion_id: asoc,
                    pago_id: pago,
                    tasa_vcb_bs_por_eur: t,
                }),
            });
            if (!pr.res.ok || !pr.data.ok) {
                if (msg) msg.textContent = pr.data.message || 'Error al guardar.';
                return;
            }
            const nr = pr.data.recibo || {};
            Object.assign(rec, nr);
            inp.value = nr.tasa_vcb_bs_por_eur != null ? String(nr.tasa_vcb_bs_por_eur) : inp.value;
            const eq = document.getElementById('rec-equiv-bs');
            if (eq) eq.textContent = fmtBs(nr.equivalencia_bs);
            if (msg) msg.textContent = 'Tasa actualizada.';
        });
    }

    document.getElementById('btn-rec-print')?.addEventListener('click', () => window.print());
});
