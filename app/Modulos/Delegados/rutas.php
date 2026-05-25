<?php

declare(strict_types=1);

/**
 * Rutas HTTP del módulo Delegados (movimiento torneo, solicitudes, reportes).
 *
 * @return list<array{path: string, methods: string|list<string>, handler: string, modulo: string, descripcion?: string}>
 */
return [
    [
        'modulo' => 'Delegados',
        'path' => 'api/delegado_reporte_afiliaciones_torneo.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'delegado_reporte_afiliaciones_torneo.php',
        'descripcion' => 'Reporte afiliaciones desde movimiento_torneo (delegado)',
    ],
    [
        'modulo' => 'Delegados',
        'path' => 'api/delegado_reporte_afiliados_torneo.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'delegado_reporte_afiliados_torneo.php',
        'descripcion' => 'Reporte afiliados con movimiento (delegado)',
    ],
    [
        'modulo' => 'Delegados',
        'path' => 'api/delegado_movimiento_solicitar_carnet.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'delegado_movimiento_solicitar_carnet.php',
        'descripcion' => 'Solicitud de carnet en movimiento_torneo',
    ],
    [
        'modulo' => 'Delegados',
        'path' => 'api/delegado_movimiento_solicitar_traspaso.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'delegado_movimiento_solicitar_traspaso.php',
        'descripcion' => 'Solicitud de traspaso entre asociaciones',
    ],
    [
        'modulo' => 'Delegados',
        'path' => 'api/delegado_actividad_reciente.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'delegado_actividad_reciente.php',
        'descripcion' => 'Actividad reciente del panel delegado',
    ],
    [
        'modulo' => 'Delegados',
        'path' => 'api/delegado_traspasos_vista.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'delegado_traspasos_vista.php',
        'descripcion' => 'Vista traspasos inscripción (delegado)',
    ],
    [
        'modulo' => 'Delegados',
        'path' => 'api/delegado_otras_asociaciones.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'delegado_otras_asociaciones.php',
        'descripcion' => 'Otras asociaciones destino para traspaso',
    ],
    [
        'modulo' => 'Delegados',
        'path' => 'api/torneos_activos_list.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'torneos_activos_list.php',
        'descripcion' => 'Torneos activos (selector panel delegado / informes)',
    ],
    [
        'modulo' => 'Delegados',
        'path' => 'panel.html',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'panel.html',
        'descripcion' => 'Panel: pestañas delegado (referencia; UI en src/js/panel.js)',
    ],
];
