<?php

declare(strict_types=1);

/**
 * Rutas HTTP del módulo Auth (sesión, políticas, usuarios del portal).
 *
 * @return list<array{path: string, methods: string|list<string>, handler: string, modulo: string, descripcion?: string}>
 */
return [
    [
        'modulo' => 'Auth',
        'path' => 'api/login.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'login.php',
        'descripcion' => 'Inicio de sesión (usuario/contraseña)',
    ],
    [
        'modulo' => 'Auth',
        'path' => 'api/logout.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'logout.php',
        'descripcion' => 'Cierre de sesión',
    ],
    [
        'modulo' => 'Auth',
        'path' => 'api/auth_context.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'auth_context.php',
        'descripcion' => 'Contexto de sesión, capabilities y pendientes',
    ],
    [
        'modulo' => 'Auth',
        'path' => 'api/crud_usuarios.php',
        'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'crud_usuarios.php',
        'descripcion' => 'CRUD usuarios (panel admin)',
    ],
    [
        'modulo' => 'Auth',
        'path' => 'api/mi_perfil.php',
        'methods' => ['GET', 'PUT', 'PATCH'],
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'mi_perfil.php',
        'descripcion' => 'Perfil del usuario en sesión',
    ],
    [
        'modulo' => 'Auth',
        'path' => 'api/mi_perfil_upload.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'mi_perfil_upload.php',
        'descripcion' => 'Subida foto/cédula perfil propio',
    ],
    [
        'modulo' => 'Auth',
        'path' => 'index.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'index.php',
        'descripcion' => 'Portal: login y shell principal',
    ],
];
