<?php

declare(strict_types=1);

/**
 * Rutas HTTP del módulo Asociaciones (organización rectora FVD, asociaciones, logos).
 *
 * @return list<array{path: string, methods: string|list<string>, handler: string, modulo: string, descripcion?: string}>
 */
return [
    [
        'modulo' => 'Asociaciones',
        'path' => 'api/crud_asociaciones.php',
        'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'crud_asociaciones.php',
        'descripcion' => 'CRUD de asociaciones federativas',
    ],
    [
        'modulo' => 'Asociaciones',
        'path' => 'api/crud_organizacion.php',
        'methods' => ['GET', 'PUT', 'PATCH'],
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'crud_organizacion.php',
        'descripcion' => 'Organización rectora FVD (organizacion_fvd)',
    ],
    [
        'modulo' => 'Asociaciones',
        'path' => 'api/upload_panel_asset.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'upload_panel_asset.php',
        'descripcion' => 'Subida de logos (org/asoc) y assets del panel',
    ],
    [
        'modulo' => 'Asociaciones',
        'path' => 'panel.html',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'panel.html',
        'descripcion' => 'Panel admin: pestañas Organización y Asociaciones',
    ],
];
