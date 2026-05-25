<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap_modulo.php';
require_once FVD_ROOT . '/Database.php';
require_once FVD_ROOT . '/app/Auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (Auth::check() && Auth::rol() === 'admingral') {
    header('Location: panel.html', true, 302);
    exit;
}

$portal_login_gate = !Auth::check();
?>
<!DOCTYPE html>
<!--
  URL de acceso con WAMP (carpeta www\admin_fvd):
  http://localhost/admin_fvd/
  Sin sesión: formulario de login. Con sesión admingral: redirección a panel.html (PHP).
-->
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FVD Portal - Gestión Federativa</title>
    <link rel="icon" href="img/logonvofvd.ico" type="image/x-icon">
    <link rel="stylesheet" href="dist/assets/css/main.css">
</head>
<body class="<?php echo $portal_login_gate ? 'portal-login-body' : ''; ?>">
<?php if ($portal_login_gate) { ?>
    <div class="login-container">
        <div class="login-box">
            <img src="img/fvd-login-oficial.png" alt="Federación Venezolana de Dominó" class="login-brand-img" width="240" height="120">
            <h1 style="font-size:1.15rem;margin:0 0 0.5rem;color:var(--primary-blue,#0a4a7a);">Portal FVD</h1>
            <p style="font-size:0.88rem;color:#555;margin:0 0 1rem;">Inicie sesión para continuar</p>
            <form id="portal-login-form" novalidate>
                <label class="visually-hidden" for="portal-login-user">Usuario, email o cédula</label>
                <input type="text" id="portal-login-user" name="username" required autocomplete="username" placeholder="Usuario, email o cédula" maxlength="120">
                <label class="visually-hidden" for="portal-login-pass">Contraseña</label>
                <div class="login-pwd-wrap">
                    <input type="password" id="portal-login-pass" name="password" required autocomplete="current-password" placeholder="Contraseña" maxlength="128">
                    <button type="button" class="login-pwd-toggle" id="portal-login-pass-toggle" aria-label="Mostrar contraseña" aria-pressed="false" title="Mostrar contraseña">Ver</button>
                </div>
                <button type="submit" class="btn-primary" style="width:100%;margin-top:0.5rem;padding:0.65rem;">Entrar</button>
            </form>
            <p id="portal-login-msg" class="form-msg" style="display:none;margin-top:0.75rem;"></p>
            <p style="font-size:0.75rem;color:#888;margin-top:1rem;">Credenciales: vea <strong>ACCESO_ADMIN.txt</strong>. Si el usuario admin no existe en MySQL, ejecute una vez: <code style="font-size:0.68rem;">php tools/bootstrap_portal_admin.php</code></p>
        </div>
    </div>
    <script type="module" src="src/js/index_login.js"></script>
<?php } else { ?>
    <header class="main-header">
        <div class="brand-container">
            <img src="img/fvd-login-oficial.png" alt="Federación Venezolana de Dominó" class="main-logo">
            <div id="affiliate-container">
                </div>
        </div>
        <nav id="main-nav"></nav>
    </header>

    <main id="app-container">
        <div class="loader">Cargando sistema...</div>
    </main>

    <footer class="main-footer-portal">
        <p>&copy; 2024 Federación Venezolana de Dominó</p>
        <p class="main-footer-org">Organización</p>
    </footer>

    <script type="module" src="src/js/main.js"></script>
<?php } ?>
</body>
</html>
