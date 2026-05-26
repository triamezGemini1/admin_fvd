/**
 * Formulario de acceso en la raíz (index.php) cuando no hay sesión.
 */

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('portal-login-form');
    const msg = document.getElementById('portal-login-msg');
    const passEl = document.getElementById('portal-login-pass');
    const passToggle = document.getElementById('portal-login-pass-toggle');
    if (!form) return;

    passToggle?.addEventListener('click', () => {
        if (!passEl) return;
        const show = passEl.type === 'password';
        passEl.type = show ? 'text' : 'password';
        passToggle.setAttribute('aria-pressed', show ? 'true' : 'false');
        passToggle.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
        passToggle.title = show ? 'Ocultar contraseña' : 'Mostrar contraseña';
        passToggle.textContent = show ? 'Ocultar' : 'Ver';
    });

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const userEl = document.getElementById('portal-login-user');
        const user = userEl ? userEl.value.trim() : '';
        const password = passEl ? passEl.value : '';
        if (msg) {
            msg.hidden = true;
            msg.textContent = '';
        }
        try {
            const res = await fetch('api/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ username: user, password }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.status === 'success') {
                if (data.rol === 'admingral') {
                    window.location.href = 'panel.html';
                } else if (data.rol === 'delegado') {
                    window.location.href = 'panel.html';
                } else {
                    window.location.reload();
                }
                return;
            }
            if (msg) {
                msg.textContent = data.message || 'Credenciales inválidas.';
                msg.className = 'portal-landing-form-msg portal-landing-form-msg--err';
                msg.hidden = false;
            }
        } catch (e) {
            console.error(e);
            if (msg) {
                msg.textContent = 'Error de conexión.';
                msg.className = 'portal-landing-form-msg portal-landing-form-msg--err';
                msg.hidden = false;
            }
        }
    });
});
