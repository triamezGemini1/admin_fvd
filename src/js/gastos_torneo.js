/**
 * Gastos operativos por torneo — formulario móvil con conversión Bs → EUR.
 */
import { initFvdReportPage } from './fvd_report_page.js';
import { reporteEsc } from './reporte_tabla.js';
import { initDelegadoTorneosBar, persistJornadaDesdeAuth } from './delegado_torneos_bar.js';
import { puedeFinanzasOperativasFvd } from './finanzas_reporte_nav.js';

const API = 'api/finanza_gasto_torneo.php';

/** @type {Record<string, unknown>|null} */
let ctxAuth = null;

function esc(v) {
    return reporteEsc(v);
}

function qsTorneoId() {
    const n = parseInt(new URL(window.location.href).searchParams.get('torneo_id') || '0', 10);
    return n > 0 ? n : 0;
}

function hoyIso() {
    const d = new Date();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${m}-${day}`;
}

function torneoIdActivo() {
    const sel = document.getElementById('gasto-torneo-select');
    if (sel) {
        const n = parseInt(String(sel.value), 10);
        if (n > 0) return n;
    }
    return qsTorneoId();
}

function recalcEur() {
    const bsIn = document.getElementById('gasto-monto-bs');
    const tasaIn = document.getElementById('gasto-tasa');
    const eurIn = document.getElementById('gasto-monto-eur');
    if (!bsIn || !tasaIn || !eurIn) return;
    const bs = parseFloat(String(bsIn.value));
    const tasa = parseFloat(String(tasaIn.value));
    if (!(bs > 0) || !(tasa > 0)) {
        eurIn.value = '';
        return;
    }
    eurIn.value = String(Math.round((bs / tasa) * 100) / 100);
}

function wireConversion() {
    document.getElementById('gasto-monto-bs')?.addEventListener('input', recalcEur);
    document.getElementById('gasto-tasa')?.addEventListener('input', recalcEur);
}

function renderLista(gastos, totales) {
    const el = document.getElementById('gasto-lista');
    const totEl = document.getElementById('gasto-totales');
    if (totEl && totales) {
        totEl.textContent = `${totales.n || 0} registro(s) · Total ${Number(totales.total_bs || 0).toFixed(2)} Bs · ${Number(totales.total_eur || 0).toFixed(2)} €`;
    }
    if (!el) return;
    const list = Array.isArray(gastos) ? gastos : [];
    if (list.length < 1) {
        el.innerHTML = '<p class="ag-muted">Sin gastos registrados en este torneo.</p>';
        return;
    }
    el.innerHTML = list
        .map((g) => {
            const nf = g.nro_factura ? esc(String(g.nro_factura)) : '—';
            return `<article class="ag-gasto-item">
            <div class="ag-gasto-item-head">
                <strong>${esc(g.concepto)}</strong>
                <span class="ag-gasto-item-fecha">${esc(String(g.fecha || ''))}</span>
            </div>
            <p class="ag-gasto-item-montos">
                <span>${Number(g.monto_bs).toFixed(2)} Bs</span>
                <span class="ag-gasto-item-sep">→</span>
                <span>${Number(g.monto_eur).toFixed(2)} €</span>
                <span class="ag-gasto-item-tasa">@ ${Number(g.tasa_eur_bs).toFixed(4)}</span>
            </p>
            <p class="ag-gasto-item-meta">Factura: ${nf}${g.notas ? ` · ${esc(String(g.notas))}` : ''}</p>
        </article>`;
        })
        .join('');
}

function poblarTorneos(torneos, tidActivo) {
    const sel = document.getElementById('gasto-torneo-select');
    if (!sel) return;
    const list = Array.isArray(torneos) ? torneos : [];
    sel.innerHTML = list
        .map((t) => {
            const id = parseInt(String(t.torneo_id ?? 0), 10);
            const nom = String(t.nombre || `Torneo ${id}`);
            return `<option value="${id}"${id === tidActivo ? ' selected' : ''}>${esc(nom)}</option>`;
        })
        .join('');
    if (list.length < 1) {
        sel.innerHTML = '<option value="">Sin torneos</option>';
    }
}

async function cargarDatos(torneoId) {
    const fb = document.getElementById('gasto-form-fb');
    if (fb) fb.textContent = '';
    const res = await fetch(`${API}?torneo_id=${encodeURIComponent(String(torneoId))}`, {
        credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
        throw new Error(data.message || 'No se pudo cargar datos del torneo.');
    }
    const nomTor = document.getElementById('gasto-torneo-nombre');
    if (nomTor && data.torneo) {
        nomTor.textContent = data.torneo.nombre || `Torneo ${torneoId}`;
    }
    const tasaIn = document.getElementById('gasto-tasa');
    if (tasaIn && data.tasa_eur_bs != null) {
        tasaIn.value = String(data.tasa_eur_bs);
    }
    const fechaIn = document.querySelector('#gasto-torneo-form input[name="fecha"]');
    if (fechaIn && !fechaIn.value) {
        fechaIn.value = hoyIso();
    }
    poblarTorneos(data.torneos_selector || [], torneoId);
    renderLista(data.gastos, data.totales);
    recalcEur();
    return data;
}

async function onSubmit(ev) {
    ev.preventDefault();
    const fb = document.getElementById('gasto-form-fb');
    const form = document.getElementById('gasto-torneo-form');
    if (!form) return;
    const tid = torneoIdActivo();
    if (tid < 1) {
        if (fb) fb.textContent = 'Seleccione un torneo.';
        return;
    }
    const fd = new FormData(form);
    const payload = {
        torneo_id: tid,
        fecha: String(fd.get('fecha') || '').trim(),
        concepto: String(fd.get('concepto') || '').trim(),
        monto_bs: parseFloat(String(fd.get('monto_bs') || '0')),
        tasa_eur_bs: parseFloat(String(fd.get('tasa_eur_bs') || '0')),
        nro_factura: String(fd.get('nro_factura') || '').trim(),
        notas: String(fd.get('notas') || '').trim(),
    };
    const eur = parseFloat(String(fd.get('monto_eur') || '0'));
    if (eur > 0) payload.monto_eur = eur;

    if (!payload.concepto || !(payload.monto_bs > 0) || !payload.fecha) {
        if (fb) fb.textContent = 'Complete fecha, concepto y monto en Bs.';
        return;
    }
    if (fb) fb.textContent = 'Guardando…';
    try {
        const res = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
            if (fb) fb.textContent = data.message || 'Error al guardar.';
            return;
        }
        if (fb) {
            fb.textContent = data.message || 'Guardado.';
            fb.classList.add('ok');
        }
        form.querySelector('[name="concepto"]').value = '';
        form.querySelector('[name="monto_bs"]').value = '';
        form.querySelector('[name="nro_factura"]').value = '';
        form.querySelector('[name="notas"]').value = '';
        recalcEur();
        await cargarDatos(tid);
    } catch (e) {
        console.error(e);
        if (fb) fb.textContent = 'Error de conexión.';
    }
}

function puedeAcceder(ctx) {
    return puedeFinanzasOperativasFvd(ctx);
}

document.addEventListener('DOMContentLoaded', async () => {
    const gate = document.getElementById('gasto-gate');
    const app = document.getElementById('gasto-app');
    try {
        const res = await fetch('api/auth_context.php', { credentials: 'same-origin' });
        const ctx = await res.json().catch(() => ({}));
        if (!puedeAcceder(ctx)) {
            if (gate) {
                gate.style.display = 'block';
                gate.innerHTML =
                    ctx.logged && ctx.rol === 'delegado'
                        ? '<p class="error">Los gastos operativos del torneo los gestiona solo administración general.</p><p><a href="panel.html" class="btn-secondary">Panel</a></p>'
                        : '<p class="error">Inicie sesión como administración general.</p>';
            }
            return;
        }
        ctxAuth = ctx;
        initFvdReportPage({ nav: '#main-nav' });
        persistJornadaDesdeAuth(ctx);
        void initDelegadoTorneosBar({
            showWhen: (a) => a.logged && (a.rol === 'admingral' || a.rol === 'delegado'),
        });

        let tid = qsTorneoId();
        if (tid < 1 && ctx.torneo_jornada_id > 0) {
            tid = parseInt(String(ctx.torneo_jornada_id), 10);
        }
        if (app) app.style.display = '';
        wireConversion();
        document.getElementById('gasto-torneo-form')?.addEventListener('submit', (e) => void onSubmit(e));
        document.getElementById('gasto-torneo-select')?.addEventListener('change', () => {
            const t = torneoIdActivo();
            const u = new URL(window.location.href);
            if (t > 0) u.searchParams.set('torneo_id', String(t));
            history.replaceState(null, '', u.toString());
            void cargarDatos(t).catch((err) => {
                const fb = document.getElementById('gasto-form-fb');
                if (fb) fb.textContent = err.message;
            });
        });

        if (tid < 1) {
            const bootRes = await fetch(API, { credentials: 'same-origin' });
            const boot = await bootRes.json().catch(() => ({}));
            const list = boot.torneos_selector || [];
            if (list[0]) tid = parseInt(String(list[0].torneo_id), 10);
        }
        if (tid < 1) {
            if (gate) {
                gate.style.display = 'block';
                gate.textContent = 'No hay torneo seleccionado.';
            }
            return;
        }
        const u = new URL(window.location.href);
        u.searchParams.set('torneo_id', String(tid));
        history.replaceState(null, '', u.toString());
        await cargarDatos(tid);
    } catch (e) {
        console.error(e);
        if (gate) {
            gate.style.display = 'block';
            gate.textContent = 'Error al iniciar.';
        }
    }
});
