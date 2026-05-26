/**
 * Afiliación atleta: check cédula, categoría por edad, previews, FormData → save_afiliacion.php.
 * Acceso según `modo` en URL: alta/nuevo (crear), editar (crear o escribir), ver (crear o panel con lectura de usuarios).
 */

import { mountPortalPerfilHeader } from './portal_perfil_header.js';
import { initDelegadoTorneosBar, persistJornadaDesdeAuth } from './delegado_torneos_bar.js';

const API_CHECK = 'api/check_user.php';
const API_SAVE = 'api/save_afiliacion.php';

/**
 * Rangos de categoría por edad (año cumplido a fecha de hoy). Ajustar según reglamento FVD.
 */
const CATEGORIAS_FVD = [
    { min: 0, max: 12, label: 'Infantil' },
    { min: 13, max: 17, label: 'Juvenil' },
    { min: 18, max: 49, label: 'Mayor / Absoluto' },
    { min: 50, max: 59, label: 'Máster A' },
    { min: 60, max: 69, label: 'Máster B' },
    { min: 70, max: 120, label: 'Máster C' },
];

/** @type {{ esAdmin: boolean, modoUrl: string, soloLectura: boolean } | null} */
let ctxPerms = null;

function getAfiliarUrlModo() {
    const u = new URL(window.location.href);
    return (u.searchParams.get('modo') || '').toLowerCase().trim();
}

function getAfiliarUrlCedula() {
    const u = new URL(window.location.href);
    return (u.searchParams.get('cedula') || '').trim();
}

/** Iframe o `?embed=1`: sin cabecera global duplicada respecto al panel delegado. */
function aplicarModoEmbed() {
    let embed = false;
    try {
        const u = new URL(window.location.href);
        embed = u.searchParams.get('embed') === '1' || window.self !== window.top;
    } catch (_) {
        embed = window.self !== window.top;
    }
    if (embed) {
        document.body.classList.add('afiliacion-body--embed');
    }
    return embed;
}

function actualizarTituloAfiliacion(modo) {
    const h = document.querySelector('.afiliacion-title');
    const p = document.querySelector('.afiliacion-lead');
    if (!h || !p) return;
    if (modo === 'ver') {
        h.textContent = 'Consulta de atleta';
        p.textContent = 'Datos de afiliación en solo lectura.';
    } else if (modo === 'editar') {
        h.textContent = 'Editar afiliación';
        p.textContent = 'Modifique los datos y guarde; la cédula identifica al usuario.';
    } else if (modo === 'nuevo') {
        h.textContent = 'Nueva afiliación';
        p.textContent =
            'Registre la cédula al salir del campo. Sin Nº FVD (0) la alta en federación sigue pendiente. El estatus 9 en portal indica pendiente de pago de anualidad, no el trámite del Nº FVD.';
    } else {
        h.textContent = 'Afiliación de atleta';
        p.textContent =
            'Consulte la cédula al salir del campo. Sin Nº FVD (0) la alta en federación sigue pendiente; el estatus 9 en portal se refiere a anualidad / pago, no al Nº FVD.';
    }
}

/**
 * @param {boolean} activo
 */
function aplicarSoloLecturaAfiliacion(activo) {
    const form = document.getElementById('form-afiliar');
    if (!form) return;
    form.querySelectorAll('input, select, textarea').forEach((el) => {
        if (el.type === 'hidden') return;
        if (el.type === 'file') {
            el.disabled = activo;
            return;
        }
        if (el.tagName === 'SELECT') {
            el.disabled = activo;
            return;
        }
        if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
            el.readOnly = activo;
        }
    });
    const sub = document.getElementById('btn-afiliar-submit');
    const lim = document.getElementById('btn-afiliar-limpiar');
    if (sub) {
        sub.disabled = activo;
        sub.style.display = activo ? 'none' : '';
    }
    if (lim) lim.style.display = activo ? 'none' : '';
}
function calcularEdadAnios(fechaIso) {
    if (!fechaIso || !/^\d{4}-\d{2}-\d{2}$/.test(fechaIso)) return null;
    const hoy = new Date();
    const nac = new Date(fechaIso + 'T12:00:00');
    if (Number.isNaN(nac.getTime())) return null;
    let edad = hoy.getFullYear() - nac.getFullYear();
    const m = hoy.getMonth() - nac.getMonth();
    if (m < 0 || (m === 0 && hoy.getDate() < nac.getDate())) {
        edad--;
    }
    return edad;
}

/**
 * @param {number|null} edad
 */
function categoriaPorEdad(edad) {
    if (edad === null || edad < 0) return '—';
    for (const r of CATEGORIAS_FVD) {
        if (edad >= r.min && edad <= r.max) {
            return r.label;
        }
    }
    return 'Fuera de tabla';
}

function actualizarCategoriaDesdeFecha() {
    const inp = document.getElementById('fechnac');
    const out = document.getElementById('categoria-edad');
    if (!inp || !out) return;
    const edad = calcularEdadAnios(inp.value);
    out.textContent = edad === null ? '—' : `${categoriaPorEdad(edad)} (${edad} años)`;
}

function wirePreview(inputId, imgId, phId) {
    const inp = document.getElementById(inputId);
    const img = document.getElementById(imgId);
    const ph = document.getElementById(phId);
    if (!inp || !img || !ph) return;
    inp.addEventListener('change', () => {
        const file = inp.files && inp.files[0];
        if (!file || !file.type.startsWith('image/')) {
            img.src = '';
            img.style.display = 'none';
            ph.style.display = 'block';
            return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
            img.src = /** @type {string} */ (e.target && e.target.result);
            img.style.display = 'block';
            ph.style.display = 'none';
        };
        reader.readAsDataURL(file);
    });
}

function setPreviewFromUrl(imgId, phId, url) {
    const img = document.getElementById(imgId);
    const ph = document.getElementById(phId);
    if (!img || !ph) return;
    if (url) {
        img.src = url;
        img.style.display = 'block';
        ph.style.display = 'none';
    } else {
        img.src = '';
        img.style.display = 'none';
        ph.style.display = 'block';
    }
}

function setSubmitLabel(esActualizacion) {
    const btn = document.getElementById('btn-afiliar-submit');
    if (btn) btn.textContent = esActualizacion ? 'Actualizar' : 'Guardar';
}

function aplicarRolUi() {
    const esAdmin = ctxPerms && ctxPerms.esAdmin === true;
    const wDel = document.getElementById('numfvd-delegado-wrap');
    const wAdm = document.getElementById('numfvd-admin-wrap');
    const wAsoc = document.getElementById('asoc-admin-wrap');
    if (wDel) wDel.classList.toggle('is-hidden', esAdmin);
    if (wAdm) wAdm.classList.toggle('is-hidden', !esAdmin);
    if (wAsoc) wAsoc.classList.toggle('is-hidden', !esAdmin);
}

async function verificarAcceso() {
    const gate = document.getElementById('afiliacion-gate');
    const root = document.getElementById('afiliacion-form-root');
    const modoUrl = getAfiliarUrlModo();
    try {
        const res = await fetch('api/auth_context.php', { credentials: 'same-origin' });
        const ctx = await res.json().catch(() => ({}));
        if (!res.ok || !ctx.logged) {
            if (gate) {
                gate.classList.remove('is-hidden');
                gate.innerHTML =
                    '<p class="error">Debe iniciar sesión para acceder a esta pantalla.</p><p><a href="index.php" class="btn-secondary">Inicio</a></p>';
            }
            if (root) root.classList.add('is-hidden');
            return false;
        }
        const capU = ctx.capabilities?.usuarios || {};
        const canCreate = capU.create === true;
        const canWrite = capU.write === true;
        const canRead = capU.read === true;
        const panel = ctx.puede_panel_admin === true;

        let permitido = false;
        if (modoUrl === 'ver') {
            permitido = canCreate === true || (panel === true && canRead === true);
        } else if (modoUrl === 'editar') {
            permitido = canCreate === true || canWrite === true;
        } else if (modoUrl === 'nuevo') {
            permitido = canCreate === true;
        } else {
            permitido = canCreate === true;
        }

        if (!permitido) {
            if (gate) {
                gate.classList.remove('is-hidden');
                gate.innerHTML =
                    '<p class="error">No tiene permiso para esta operación (afiliación / consulta).</p><p><a href="index.php" class="btn-secondary">Inicio</a></p>';
            }
            if (root) root.classList.add('is-hidden');
            return false;
        }
        const soloLectura = modoUrl === 'ver';
        ctxPerms = {
            esAdmin: ctx.rol === 'admingral',
            modoUrl: modoUrl || 'alta',
            soloLectura,
            rol: ctx.rol,
        };
        if (gate) gate.classList.add('is-hidden');
        if (root) root.classList.remove('is-hidden');
        aplicarRolUi();
        if (!document.body.classList.contains('afiliacion-body--embed')) {
            mountPortalPerfilHeader(document.getElementById('main-nav'));
            persistJornadaDesdeAuth(ctx);
            void initDelegadoTorneosBar();
        }
        if (ctx.rol === 'delegado') {
            const hi = document.getElementById('hdr-link-inicio');
            if (hi) hi.setAttribute('href', 'panel.html');
        }
        return true;
    } catch (e) {
        console.error(e);
        if (gate) {
            gate.classList.remove('is-hidden');
            gate.innerHTML = '<p class="error">No se pudo validar el acceso.</p>';
        }
        return false;
    }
}
async function consultarCedula() {
    const cedulaInp = document.getElementById('cedula');
    const msg = document.getElementById('form-afiliar-msg');
    if (!cedulaInp) return;
    const cedula = cedulaInp.value.trim();
    if (msg) {
        msg.style.display = 'none';
    }
    if (cedula.length < 4) {
        document.getElementById('user_id').value = '';
        setSubmitLabel(false);
        return;
    }
    try {
        const res = await fetch(`${API_CHECK}?cedula=${encodeURIComponent(cedula)}`, { credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
            if (msg) {
                msg.textContent = data.message || 'Error al consultar cédula';
                msg.className = 'form-msg torneo-alta-msg err';
                msg.style.display = 'block';
            }
            return;
        }
        if (data.blocked) {
            if (msg) {
                msg.textContent = data.message || 'No puede usar esta cédula.';
                msg.className = 'form-msg torneo-alta-msg err';
                msg.style.display = 'block';
            }
            document.getElementById('user_id').value = '';
            setSubmitLabel(false);
            return;
        }
        if (!data.exists) {
            document.getElementById('user_id').value = '';
            setSubmitLabel(false);
            if (data.persona_externa && typeof data.persona_externa === 'object') {
                const pe = data.persona_externa;
                if (pe.cedula) {
                    document.getElementById('cedula').value = String(pe.cedula);
                }
                document.getElementById('nombre').value = pe.nombre || '';
                document.getElementById('fechnac').value = pe.fechnac ? String(pe.fechnac).slice(0, 10) : '';
                if (pe.sexo === 1 || pe.sexo === 2) {
                    document.getElementById('sexo').value = String(pe.sexo);
                }
                actualizarCategoriaDesdeFecha();
                if (msg) {
                    msg.textContent =
                        'Datos cargados desde el registro nacional de personas. Complete email y demás campos, luego guarde para solicitar la afiliación.';
                    msg.className = 'form-msg torneo-alta-msg ok';
                    msg.style.display = 'block';
                }
            }
            return;
        }
        const u = data.user;
        if (!u) return;
        document.getElementById('user_id').value = String(u.id || '');
        document.getElementById('nombre').value = u.nombre || '';
        document.getElementById('fechnac').value = u.fechnac ? String(u.fechnac).slice(0, 10) : '';
        document.getElementById('sexo').value = String(u.sexo ?? '0');
        document.getElementById('email').value = u.email || '';
        document.getElementById('celular').value = u.celular || '';
        if (ctxPerms && ctxPerms.esAdmin) {
            const na = document.getElementById('numfvd_admin');
            if (na) na.value = String(u.numfvd ?? 0);
        }
        if (u.asociacion_id && document.getElementById('asociacion_id')) {
            document.getElementById('asociacion_id').value = String(u.asociacion_id);
        }
        actualizarCategoriaDesdeFecha();
        setPreviewFromUrl('preview-foto', 'preview-foto-ph', u.urlimgfoto || '');
        setPreviewFromUrl('preview-ced', 'preview-ced-ph', u.urlimgcedula || '');
        setSubmitLabel(true);
    } catch (e) {
        console.error(e);
    }
}

function limpiarFormulario() {
    const form = document.getElementById('form-afiliar');
    if (!form) return;
    form.reset();
    document.getElementById('user_id').value = '';
    setSubmitLabel(false);
    actualizarCategoriaDesdeFecha();
    setPreviewFromUrl('preview-foto', 'preview-foto-ph', '');
    setPreviewFromUrl('preview-ced', 'preview-ced-ph', '');
    const msg = document.getElementById('form-afiliar-msg');
    if (msg) msg.style.display = 'none';
}

function showMsg(text, ok) {
    const msg = document.getElementById('form-afiliar-msg');
    if (!msg) return;
    msg.textContent = text;
    msg.style.display = 'block';
    msg.className = 'form-msg torneo-alta-msg ' + (ok ? 'ok' : 'err');
}

document.addEventListener('DOMContentLoaded', async () => {
    aplicarModoEmbed();
    const ok = await verificarAcceso();
    if (!ok) return;

    const uUrl = new URL(window.location.href);
    const tt = parseInt(uUrl.searchParams.get('torneo_id') || '0', 10) || 0;
    const th = document.getElementById('torneo_id_hidden');
    if (th && tt > 0) th.value = String(tt);

    const modo = getAfiliarUrlModo();
    const cedUrl = getAfiliarUrlCedula();
    actualizarTituloAfiliacion(modo);

    if (modo === 'nuevo') {
        limpiarFormulario();
    }

    document.getElementById('fechnac')?.addEventListener('change', actualizarCategoriaDesdeFecha);
    document.getElementById('fechnac')?.addEventListener('input', actualizarCategoriaDesdeFecha);
    document.getElementById('cedula')?.addEventListener('blur', () => {
        if (ctxPerms && ctxPerms.soloLectura) return;
        consultarCedula();
    });

    wirePreview('foto_atleta', 'preview-foto', 'preview-foto-ph');
    wirePreview('imagen_cedula', 'preview-ced', 'preview-ced-ph');

    document.getElementById('btn-afiliar-limpiar')?.addEventListener('click', () => {
        if (ctxPerms && ctxPerms.soloLectura) return;
        limpiarFormulario();
    });

    document.getElementById('form-afiliar')?.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        if (ctxPerms && ctxPerms.soloLectura) return;
        const form = /** @type {HTMLFormElement} */ (ev.target);
        const fd = new FormData(form);
        if (!ctxPerms || !ctxPerms.esAdmin) {
            fd.set('numfvd', '0');
        }
        if (ctxPerms && ctxPerms.rol === 'delegado' && !ctxPerms.esAdmin) {
            const tv = parseInt(String(document.getElementById('torneo_id_hidden')?.value || '0'), 10) || 0;
            if (tv < 1) {
                showMsg(
                    'Indique el torneo: abra la afiliación desde el panel de asociación con un torneo seleccionado en la barra superior (o añada torneo_id en la URL).',
                    false
                );
                return;
            }
        }
        try {
            const res = await fetch(API_SAVE, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.ok) {
                showMsg(data.message || 'Guardado correctamente.', true);
                limpiarFormulario();
            } else {
                showMsg(data.message || 'No se pudo guardar.', false);
            }
        } catch (e) {
            console.error(e);
            showMsg('Error de conexión.', false);
        }
    });

    if (cedUrl && (modo === 'ver' || modo === 'editar')) {
        const ci = document.getElementById('cedula');
        if (ci) ci.value = cedUrl;
        await consultarCedula();
    } else if (!modo && cedUrl) {
        const ci = document.getElementById('cedula');
        if (ci) ci.value = cedUrl;
        await consultarCedula();
    }

    if (ctxPerms && ctxPerms.soloLectura) {
        aplicarSoloLecturaAfiliacion(true);
    }

    actualizarCategoriaDesdeFecha();
});