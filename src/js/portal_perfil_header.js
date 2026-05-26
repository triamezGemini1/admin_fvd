/**
 * Botones Mi perfil / Salir en cabeceras del portal y panel + diálogo de edición de perfil.
 */
import { ensureFvdBrandLogos, ensureFvdHeaderTitle } from './fvd_app_header.js';
import { reporteEsc } from './reporte_tabla.js';

ensureFvdHeaderTitle();
ensureFvdBrandLogos();

async function fetchJson(url, options = {}) {
    const res = await fetch(url, { credentials: 'same-origin', ...options });
    const data = await res.json().catch(() => ({}));
    return { res, data };
}

function ensurePerfilDialog() {
    if (document.getElementById('portal-perfil-dlg')) return;
    const dlg = document.createElement('dialog');
    dlg.id = 'portal-perfil-dlg';
    dlg.className = 'admin-modal portal-perfil-dlg';
    dlg.innerHTML = `
        <div class="admin-modal-head">
            <h2 style="margin:0;font-size:1.1rem;">Mi perfil</h2>
            <button type="button" class="btn-secondary btn-sm" id="portal-perfil-close" aria-label="Cerrar">✕</button>
        </div>
        <div class="admin-modal-inner portal-perfil-inner">
            <p id="portal-perfil-msg" class="form-msg" style="display:none;"></p>
            <div class="portal-perfil-readonly ag-muted" id="portal-perfil-readonly"></div>
            <div class="portal-perfil-grid fvd-form">
                <label>Nombre completo <input type="text" id="portal-perfil-nombre" name="nombre" maxlength="255" required></label>
                <label>Fecha nac. <input type="date" id="portal-perfil-fechnac" name="fechnac"></label>
                <label>Sexo
                    <select id="portal-perfil-sexo" name="sexo">
                        <option value="0">—</option>
                        <option value="1">Masculino</option>
                        <option value="2">Femenino</option>
                    </select>
                </label>
                <label>Email <input type="email" id="portal-perfil-email" name="email" maxlength="100" required></label>
                <label>Teléfono / celular <input type="text" id="portal-perfil-celular" name="celular" maxlength="20"></label>
                <label>Usuario (login) <input type="text" id="portal-perfil-username" name="username" maxlength="60" required></label>
                <label>Nueva contraseña <input type="password" id="portal-perfil-password" name="password" autocomplete="new-password" placeholder="Dejar vacío para no cambiar" maxlength="128"></label>
            </div>
            <div class="portal-perfil-imgs">
                <div class="portal-perfil-img-block">
                    <span class="admin-logo-block-title">Foto perfil</span>
                    <input type="hidden" id="portal-perfil-urlfoto" value="">
                    <div class="admin-org-logo-frame"><img id="portal-perfil-preview-foto" class="admin-org-logo-img" alt="Foto" src="" width="120" height="120" loading="lazy"></div>
                    <input type="file" id="portal-perfil-file-foto" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
                </div>
                <div class="portal-perfil-img-block">
                    <span class="admin-logo-block-title">Foto cédula</span>
                    <input type="hidden" id="portal-perfil-urlced" value="">
                    <div class="admin-org-logo-frame"><img id="portal-perfil-preview-ced" class="admin-org-logo-img" alt="Cédula" src="" width="120" height="120" loading="lazy"></div>
                    <input type="file" id="portal-perfil-file-ced" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
                </div>
            </div>
        </div>
        <div class="admin-modal-actions">
            <button type="button" class="btn-primary" id="portal-perfil-save">Guardar cambios</button>
            <button type="button" class="btn-secondary" id="portal-perfil-cancel">Cerrar</button>
        </div>`;
    document.body.appendChild(dlg);

    const showMsg = (text, ok) => {
        const m = document.getElementById('portal-perfil-msg');
        if (!m) return;
        m.textContent = text;
        m.style.display = 'block';
        m.className = 'form-msg ' + (ok ? 'ok' : 'err');
    };

    const setPreview = (imgId, url) => {
        const im = document.getElementById(imgId);
        if (!im) return;
        const u = String(url || '').trim();
        im.src = u ? u : '';
        im.style.display = u ? 'block' : 'none';
    };

    dlg.querySelector('#portal-perfil-close')?.addEventListener('click', () => dlg.close());
    dlg.querySelector('#portal-perfil-cancel')?.addEventListener('click', () => dlg.close());

    dlg.querySelector('#portal-perfil-file-foto')?.addEventListener('change', async (ev) => {
        const inp = /** @type {HTMLInputElement} */ (ev.target);
        const f = inp.files && inp.files[0];
        if (!f) return;
        const fd = new FormData();
        fd.append('campo', 'foto');
        fd.append('archivo', f);
        showMsg('Subiendo foto…', true);
        const { res, data } = await fetch('api/mi_perfil_upload.php', { method: 'POST', credentials: 'same-origin', body: fd }).then(async (r) => ({
            res: r,
            data: await r.json().catch(() => ({})),
        }));
        inp.value = '';
        if (!res.ok || !data.ok) {
            showMsg(data.message || 'Error al subir', false);
            return;
        }
        const p = String(data.path || '');
        const hid = document.getElementById('portal-perfil-urlfoto');
        if (hid) hid.value = p;
        setPreview('portal-perfil-preview-foto', p);
        showMsg('Foto actualizada.', true);
    });

    dlg.querySelector('#portal-perfil-file-ced')?.addEventListener('change', async (ev) => {
        const inp = /** @type {HTMLInputElement} */ (ev.target);
        const f = inp.files && inp.files[0];
        if (!f) return;
        const fd = new FormData();
        fd.append('campo', 'cedula');
        fd.append('archivo', f);
        showMsg('Subiendo imagen de cédula…', true);
        const { res, data } = await fetch('api/mi_perfil_upload.php', { method: 'POST', credentials: 'same-origin', body: fd }).then(async (r) => ({
            res: r,
            data: await r.json().catch(() => ({})),
        }));
        inp.value = '';
        if (!res.ok || !data.ok) {
            showMsg(data.message || 'Error al subir', false);
            return;
        }
        const p = String(data.path || '');
        const hid = document.getElementById('portal-perfil-urlced');
        if (hid) hid.value = p;
        setPreview('portal-perfil-preview-ced', p);
        showMsg('Imagen de cédula actualizada.', true);
    });

    dlg.querySelector('#portal-perfil-save')?.addEventListener('click', async () => {
        const body = {
            nombre: document.getElementById('portal-perfil-nombre')?.value?.trim() || '',
            fechnac: document.getElementById('portal-perfil-fechnac')?.value || '',
            sexo: parseInt(String(document.getElementById('portal-perfil-sexo')?.value || '0'), 10) || 0,
            email: document.getElementById('portal-perfil-email')?.value?.trim() || '',
            celular: document.getElementById('portal-perfil-celular')?.value?.trim() || '',
            username: document.getElementById('portal-perfil-username')?.value?.trim() || '',
        };
        const uf = document.getElementById('portal-perfil-urlfoto')?.value?.trim() || '';
        const uc = document.getElementById('portal-perfil-urlced')?.value?.trim() || '';
        if (uf) body.urlimgfoto = uf;
        if (uc) body.urlimgcedula = uc;
        const pw = document.getElementById('portal-perfil-password')?.value || '';
        if (pw !== '') body.password = pw;
        const { res, data } = await fetchJson('api/mi_perfil.php', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        if (!res.ok || !data.ok) {
            showMsg(data.message || 'No se pudo guardar', false);
            return;
        }
        showMsg(data.message || 'Guardado.', true);
        const pwEl = document.getElementById('portal-perfil-password');
        if (pwEl) pwEl.value = '';
        if (data.item) fillPerfilForm(data.item);
    });
}

function fillPerfilForm(item) {
    const ro = document.getElementById('portal-perfil-readonly');
    if (ro) {
        const sx = item.sexo === 1 ? 'M' : item.sexo === 2 ? 'F' : '—';
        const an = item.asociacion_nombre ? String(item.asociacion_nombre) : '—';
        ro.innerHTML = `<p><strong>Cédula</strong> (solo lectura): ${reporteEsc(item.cedula)} · <strong>Nº FVD</strong>: ${reporteEsc(String(item.numfvd ?? ''))} · <strong>Rol</strong>: ${reporteEsc(item.role)} · <strong>Sexo reg.</strong>: ${reporteEsc(sx)}</p>
            <p><strong>Asociación</strong>: ${reporteEsc(an)} · <strong>Estatus portal</strong>: ${reporteEsc(String(item.status ?? ''))}</p>`;
    }
    const setv = (id, v) => {
        const el = document.getElementById(id);
        if (el) el.value = v != null ? String(v) : '';
    };
    setv('portal-perfil-nombre', item.nombre);
    setv('portal-perfil-fechnac', item.fechnac ? String(item.fechnac).slice(0, 10) : '');
    const sxEl = document.getElementById('portal-perfil-sexo');
    if (sxEl) sxEl.value = String(item.sexo ?? '0');
    setv('portal-perfil-email', item.email);
    setv('portal-perfil-celular', item.celular);
    setv('portal-perfil-username', item.username);
    const uf = String(item.urlimgfoto || '').trim();
    const uc = String(item.urlimgcedula || '').trim();
    const hf = document.getElementById('portal-perfil-urlfoto');
    const hc = document.getElementById('portal-perfil-urlced');
    if (hf) hf.value = uf;
    if (hc) hc.value = uc;
    const imf = document.getElementById('portal-perfil-preview-foto');
    const imc = document.getElementById('portal-perfil-preview-ced');
    if (imf) {
        imf.src = uf;
        imf.style.display = uf ? 'block' : 'none';
    }
    if (imc) {
        imc.src = uc;
        imc.style.display = uc ? 'block' : 'none';
    }
    const m = document.getElementById('portal-perfil-msg');
    if (m) {
        m.style.display = 'none';
        m.textContent = '';
    }
}

async function openPortalPerfilDialog() {
    ensurePerfilDialog();
    const dlg = document.getElementById('portal-perfil-dlg');
    if (!dlg) return;
    const { res, data } = await fetchJson('api/mi_perfil.php');
    if (!res.ok || !data.ok || !data.item) {
        window.alert(data.message || 'No se pudo cargar el perfil.');
        return;
    }
    fillPerfilForm(data.item);
    if (!dlg.open) dlg.showModal();
}

async function portalLogout() {
    await fetch('api/logout.php', { method: 'POST', credentials: 'same-origin' }).catch(() => {});
    window.location.href = 'index.php';
}

/**
 * @param {HTMLElement|null} navEl
 */
export function mountPortalPerfilHeader(navEl) {
    if (!navEl || navEl.querySelector('[data-portal-perfil-mounted]')) return;
    const wrap = document.createElement('span');
    wrap.className = 'portal-header-user';
    wrap.dataset.portalPerfilMounted = '1';
    wrap.innerHTML = `<button type="button" class="btn-secondary" id="portal-hdr-perfil" style="font-size:0.85rem;padding:0.45rem 0.9rem;">Mi perfil</button>
        <button type="button" class="btn-secondary" id="portal-hdr-logout" style="font-size:0.85rem;padding:0.45rem 0.9rem;">Salir</button>`;
    navEl.appendChild(wrap);
    wrap.querySelector('#portal-hdr-perfil')?.addEventListener('click', () => void openPortalPerfilDialog());
    wrap.querySelector('#portal-hdr-logout')?.addEventListener('click', () => void portalLogout());
}
