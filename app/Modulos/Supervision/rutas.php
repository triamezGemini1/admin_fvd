<?php

declare(strict_types=1);

/**
 * Rutas HTTP del módulo Supervisión (cola de solicitudes delegadas, admin. gral.).
 *
 * @return list<array{path: string, methods: string|list<string>, handler: string, modulo: string, descripcion?: string}>
 */
return [
    [
        'modulo' => 'Supervision',
        'path' => 'api/supervision_fvd.php',
        'methods' => ['GET', 'POST'],
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'supervision_fvd.php',
        'descripcion' => 'Listado y decisión de solicitudes en movimiento_torneo',
    ],
    [
        'modulo' => 'Supervision',
        'path' => 'panel.html',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'panel.html',
        'descripcion' => 'Panel: pestañas Supervisión FVD (referencia; UI en src/js/panel.js)',
    ],
];
