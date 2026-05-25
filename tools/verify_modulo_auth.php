<?php

declare(strict_types=1);

/**
 * Verificación del módulo Auth. Ejecutar: php tools/verify_modulo_auth.php
 */
$root = dirname(__DIR__);
chdir($root);

require $root . '/app/Autoload.php';
\Fvd\Autoload::register();

require $root . '/app/Auth.php';
require $root . '/app/AdminPolicy.php';
require $root . '/app/AdminUsuario.php';

$checks = [
    'Auth' => class_exists('Auth'),
    'AdminPolicy' => class_exists('AdminPolicy'),
    'AdminUsuario' => class_exists('AdminUsuario'),
    'Fvd\\Modulos\\Auth\\Modelos\\Auth' => class_exists('Fvd\\Modulos\\Auth\\Modelos\\Auth'),
    'Auth alias is_a' => is_a('Auth', \Fvd\Modulos\Auth\Modelos\Auth::class, true),
    'AdminPolicy alias is_a' => is_a('AdminPolicy', \Fvd\Modulos\Auth\Modelos\AdminPolicy::class, true),
    'AdminUsuario alias is_a' => is_a('AdminUsuario', \Fvd\Modulos\Auth\Modelos\AdminUsuario::class, true),
    'Auth::check static' => method_exists('Auth', 'check'),
    'AdminPolicy::capabilities' => method_exists('AdminPolicy', 'capabilities'),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

require $root . '/rutas.php';
$n = 0;
foreach (fvd_rutas_registro() as $ruta) {
    if (($ruta['modulo'] ?? '') === 'Auth') {
        $n++;
        if (!is_file($ruta['handler'] ?? '')) {
            $failed[] = 'handler: ' . ($ruta['path'] ?? '?');
        }
    }
}
if ($n !== 7) {
    $failed[] = "rutas Auth (expected 7, got {$n})";
}

$guard = $root . '/app/Modulos/Auth/Controladores/admin_api_guard.php';
if (!is_file($guard) || !function_exists('admin_guard_pdo')) {
    require_once $root . '/api/admin_api_guard.php';
}
if (!function_exists('admin_guard_pdo') || !function_exists('admin_json_body')) {
    $failed[] = 'admin_api_guard functions';
}
if (!function_exists('portal_auth_pdo')) {
    require_once $root . '/api/portal_auth_pdo.php';
}
if (!function_exists('portal_auth_pdo')) {
    $failed[] = 'portal_auth_pdo function';
}

if ($failed !== []) {
    fwrite(STDERR, "FAIL:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK: modulo Auth verificado ({$n} rutas HTTP, guards cargados).\n";
exit(0);
