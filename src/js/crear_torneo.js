/**
 * Crear torneo: vista previa afiche, envío FormData → api/crud_torneos.php.
 * Acceso solo si auth_context.capabilities.torneos.create === true.
 */

const API_TORNEOS = 'api/crud_torneos.php';

function showState(loading, denied, allowed) {
    const elL = document.getElementById('crear-torneo-loading');
    const elD = document.getElementById('crear-torneo-denied');
    const elA = document.getElementById('crear-torneo-allowed');
    if (elL) elL.classList.toggle('is-hidden', !loading);
    if (elD) elD.classList.toggle('is-hidden', !denied);
    if (elA) elA.classList.toggle('is-hidden', !allowed);
}

/**
 * @returns {Promise<boolean>}
 */
async function verificarPuedeCrearTorneo() {
    try {
        const res = await fetch('api/auth_context.php', { credentials: 'same-origin' });
        const ctx = await res.json().catch(() => ({}));
        if (!res.ok || !ctx.logged) {
            showState(false, true, false);
            return false;
        }
        if (ctx.capabilities?.torneos?.create !== true) {
            showState(false, true, false);
            return false;
        }
        showState(false, false, true);
        return true;
    } catch (e) {
        console.error(e);
        showState(false, true, false);
        return false;
    }
}

function initAfichePreview() {
    const aficheInput = document.getElementById('afiche');
    const previewImg = document.getElementById('afiche-preview');
    const previewPlaceholder = document.getElementById('afiche-preview-placeholder');
    if (!aficheInput || !previewImg || !previewPlaceholder) return;
    aficheInput.addEventListener('change', () => {
        const file = aficheInput.files && aficheInput.files[0];
        if (!file || !file.type.startsWith('image/')) {
            previewImg.src = '';
            previewImg.style.display = 'none';
            previewPlaceholder.style.display = 'block';
            return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
            previewImg.src = /** @type {string} */ (e.target && e.target.result);
            previewImg.style.display = 'block';
            previewPlaceholder.style.display = 'none';
        };
        reader.readAsDataURL(file);
    });
}

function showMsg(text, ok) {
    const msgEl = document.getElementById('form-crear-torneo-msg');
    if (!msgEl) return;
    msgEl.textContent = text;
    msgEl.style.display = 'block';
    msgEl.className = 'form-msg torneo-alta-msg ' + (ok ? 'ok' : 'err');
}

function resetDefaultsAfterSave(form) {
    if (!form) return;
    const tiempo = form.querySelector('[name="tiempo"]');
    const puntos = form.querySelector('[name="puntos"]');
    const rondas = form.querySelector('[name="rondas"]');
    const ranking = form.querySelector('[name="ranking"]');
    const estatus = form.querySelector('[name="estatus"]');
    const pareclub = form.querySelector('[name="pareclub"]');
    if (tiempo) tiempo.value = '35';
    if (puntos) puntos.value = '200';
    if (rondas) rondas.value = '9';
    if (ranking) ranking.value = '1';
    if (estatus) estatus.value = '0';
    if (pareclub) pareclub.value = '0';
    const pImg = document.getElementById('afiche-preview');
    const pPh = document.getElementById('afiche-preview-placeholder');
    if (pImg && pPh) {
        pImg.src = '';
        pImg.style.display = 'none';
        pPh.style.display = 'block';
    }
}

function wireCampeonatoMode(form) {
    const sel = form.querySelector('#tor-modo-registro');
    const hint = form.querySelector('#tor-camp-hint');
    const tipo = form.querySelector('select[name="tipo"]');
    const rondas = form.querySelector('input[name="rondas"]');
    if (!sel) return;
    const apply = () => {
        const modo = sel.value || 'simple';
        const esGen = modo === 'campeonato_genero';
        const esCat = modo === 'campeonato_categoria';
        if (tipo) {
            tipo.disabled = esGen;
            if (esGen) tipo.value = '';
        }
        if (rondas) {
            rondas.disabled = esCat;
            if (esCat) rondas.value = '5';
        }
        if (hint) {
            if (esGen) {
                hint.textContent =
                    'Se crearán 2 torneos enlazados: MASCULINO y FEMENINO (mismos datos y rondas).';
            } else if (esCat) {
                hint.textContent =
                    'Se crearán 3 torneos enlazados: CATEG SUB 12 (5 rondas), SUB 15 y SUB 18 (7 rondas).';
            } else {
                hint.textContent = '';
            }
        }
    };
    sel.addEventListener('change', apply);
    apply();
}

function initFormSubmit() {
    const form = document.getElementById('form-crear-torneo');
    if (!form) return;
    wireCampeonatoMode(form);

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const msgEl = document.getElementById('form-crear-torneo-msg');
        if (msgEl) msgEl.style.display = 'none';

        const fd = new FormData(form);
        try {
            const res = await fetch(API_TORNEOS, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.ok) {
                const extra =
                    data.grupo_evento_id != null
                        ? ` Grupo campeonato: ${data.grupo_evento_id}.`
                        : '';
                showMsg((data.message || 'Torneo guardado.') + extra, true);
                form.reset();
                resetDefaultsAfterSave(form);
                wireCampeonatoMode(form);
            } else {
                showMsg(data.message || 'No se pudo guardar el torneo.', false);
            }
        } catch (err) {
            console.error(err);
            showMsg('Error de conexión con la API.', false);
        }
    });
}

document.addEventListener('DOMContentLoaded', async () => {
    showState(true, false, false);
    const permitido = await verificarPuedeCrearTorneo();
    if (!permitido) {
        return;
    }
    initAfichePreview();
    initFormSubmit();
});
