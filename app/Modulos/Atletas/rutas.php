<?php

declare(strict_types=1);

/**
 * Rutas HTTP del módulo Atletas (listado, afiliación, verificación de cédula).
 *
 * @return list<array{path: string, methods: string|list<string>, handler: string, modulo: string, descripcion?: string}>
 */
return [
    [
        'modulo' => 'Atletas',
        'path' => 'api/get_atletas.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'get_atletas.php',
        'descripcion' => 'Listado de atletas (tabla legacy + usuario/asociación)',
    ],
    [
        'modulo' => 'Atletas',
        'path' => 'api/save_afiliacion.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'save_afiliacion.php',
        'descripcion' => 'Alta o actualización de afiliación (usuarios + movimiento)',
    ],
    [
        'modulo' => 'Atletas',
        'path' => 'api/check_user.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'check_user.php',
        'descripcion' => 'Consulta de cédula antes de afiliar',
    ],
    [
        'modulo' => 'Atletas',
        'path' => 'api/carga_movimiento_torneo.php',
        'methods' => ['GET', 'POST'],
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'carga_movimiento_torneo.php',
        'descripcion' => 'Evaluar y regenerar movimiento_torneo desde atletas (admin. gral.)',
    ],
    [
        'modulo' => 'Atletas',
        'path' => 'afiliar_atleta.html',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'afiliar_atleta.html',
        'descripcion' => 'Vista: formulario de afiliación de atleta',
    ],
];
