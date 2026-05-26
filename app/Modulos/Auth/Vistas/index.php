<?php declare(strict_types=1);

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
$fvd_logo_exact_url = 'img/fvd-portal-logo.png';
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
    <title>FVD — Gestión administrativa</title>
    <link rel="icon" href="img/logonvofvd.ico" type="image/x-icon">
    <link rel="stylesheet" href="dist/assets/css/main.css">
</head>
<body class="<?php echo $portal_login_gate ? 'portal-login-body portal-landing-body' : ''; ?>">
<?php if ($portal_login_gate) { ?>
    <div class="portal-landing">
        <section class="portal-landing-hero" aria-labelledby="portal-landing-title">
            <div class="portal-landing-head">
                <h1 id="portal-landing-title" class="portal-landing-org-title">
                    FEDERACION VENEZOLANA DE DOMINO
                    <span class="portal-landing-tricolor" aria-hidden="true">
                        <span></span><span></span><span></span>
                    </span>
                </h1>
                <h2 class="portal-landing-heading">Gestión administrativa</h2>
                <p class="portal-landing-subtitle">Torneos - Afiliaciones - Transferencias - Inscripciones - Trámites administrativos</p>
                <p class="portal-landing-lead">
                    Afiliaciones, torneos, inscripciones y reportes federativos en un solo portal
                    para directivos, asociaciones y delegados de la FVD.
                </p>
            </div>

            <div class="portal-landing-row">
                <aside id="acceso" class="portal-landing-aside" aria-labelledby="portal-access-title">
                    <div class="portal-landing-tile portal-landing-tile--login">
                        <div class="portal-landing-tile__inner">
                            <div class="portal-landing-access-card">
                                <h2 id="portal-access-title" class="portal-landing-access-title">Acceso al portal administrativo</h2>
                                <p class="portal-landing-access-hint">Usuario federativo, email o cédula</p>
                                <form id="portal-login-form" class="portal-landing-form" novalidate>
                                    <label class="visually-hidden" for="portal-login-user">Usuario, email o cédula</label>
                                    <input type="text" id="portal-login-user" name="username" required autocomplete="username" placeholder="Usuario, email o cédula" maxlength="120">
                                    <label class="visually-hidden" for="portal-login-pass">Contraseña</label>
                                    <div class="portal-landing-pwd-wrap">
                                        <input type="password" id="portal-login-pass" name="password" required autocomplete="current-password" placeholder="Contraseña" maxlength="128">
                                        <button type="button" class="portal-landing-pwd-toggle" id="portal-login-pass-toggle" aria-label="Mostrar contraseña" aria-pressed="false" title="Mostrar contraseña">Ver</button>
                                    </div>
                                    <button type="submit" class="portal-landing-btn portal-landing-btn--primary portal-landing-btn--block">Entrar al portal</button>
                                </form>
                                <p id="portal-login-msg" class="portal-landing-form-msg" hidden></p>
                            </div>
                        </div>
                    </div>
                </aside>

                <aside class="portal-landing-visual" aria-label="Identidad institucional FVD">
                    <div class="portal-landing-tile portal-landing-tile--logo">
                        <div class="portal-landing-tile__inner portal-landing-tile__inner--logo">
                            <img src="<?php echo htmlspecialchars($fvd_logo_exact_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Federación Venezolana de Dominó — FVD" width="800" height="800" fetchpriority="high" loading="eager" decoding="async" class="portal-landing-logo-main">
                        </div>
                    </div>
                </aside>
            </div>
        </section>

        <footer class="portal-landing-footer">
            <p>&copy; <?php echo date('Y'); ?> Federación Venezolana de Dominó — Portal administrativo</p>
        </footer>
    </div>
    <script type="module" src="src/js/index_login.js"></script>
<?php } else { ?>
    <header class="main-header">
        <div class="brand-container">
            <img src="<?php echo htmlspecialchars($fvd_logo_exact_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Federación Venezolana de Dominó" class="main-logo">
            <div id="affiliate-container"></div>
        </div>
        <p class="fvd-header-title">FEDERACION VENEZOLANA DE DOMINO</p>
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
