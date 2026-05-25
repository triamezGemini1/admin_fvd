<?php

declare(strict_types=1);

/**
 * Rutas HTTP del módulo Torneos (CRUD, inscripciones, agrupación).
 *
 * @return list<array{path: string, methods: string|list<string>, handler: string, modulo: string, descripcion?: string}>
 */
return [
    [
        'modulo' => 'Torneos',
        'path' => 'api/crud_torneos.php',
        'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'crud_torneos.php',
        'descripcion' => 'CRUD de torneos (panel admin)',
    ],
    [
        'modulo' => 'Torneos',
        'path' => 'api/save_torneo.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'save_torneo.php',
        'descripcion' => 'Alta de torneo con archivos (formulario legacy)',
    ],
    [
        'modulo' => 'Torneos',
        'path' => 'api/torneos_agrupar.php',
        'methods' => ['GET', 'POST'],
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'torneos_agrupar.php',
        'descripcion' => 'Agrupación de torneos en campeonato',
    ],
    [
        'modulo' => 'Torneos',
        'path' => 'api/inscripciones_context.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'inscripciones_context.php',
        'descripcion' => 'Contexto del torneo activo para inscripciones',
    ],
    [
        'modulo' => 'Torneos',
        'path' => 'api/inscripciones_disponibles.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'inscripciones_disponibles.php',
        'descripcion' => 'Atletas disponibles para inscribir',
    ],
    [
        'modulo' => 'Torneos',
        'path' => 'api/inscripciones_inscritos.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'inscripciones_inscritos.php',
        'descripcion' => 'Listado de inscritos al torneo',
    ],
    [
        'modulo' => 'Torneos',
        'path' => 'api/inscripciones_inscribir.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'inscripciones_inscribir.php',
        'descripcion' => 'Registrar inscripción al torneo activo',
    ],
    [
        'modulo' => 'Torneos',
        'path' => 'crear_torneo.html',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'crear_torneo.html',
        'descripcion' => 'Vista: formulario crear torneo',
    ],
    [
        'modulo' => 'Torneos',
        'path' => 'inscripciones.html',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'inscripciones.html',
        'descripcion' => 'Vista: panel de inscripciones',
    ],
];
