<?php

declare(strict_types=1);

/**
 * Rutas HTTP del módulo Finanzas.
 *
 * @return list<array{path: string, methods: string|list<string>, handler: string, modulo: string, descripcion?: string}>
 */
return [
    [
        'modulo' => 'Finanzas',
        'path' => 'api/finanza_resumen.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'finanza_resumen.php',
        'descripcion' => 'Resumen financiero por asociación (panel admin)',
    ],
    [
        'modulo' => 'Finanzas',
        'path' => 'api/finanza_asociacion.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'finanza_asociacion.php',
        'descripcion' => 'Estado financiero detallado de una asociación',
    ],
    [
        'modulo' => 'Finanzas',
        'path' => 'api/finanza_tasa.php',
        'methods' => ['GET', 'PUT', 'PATCH'],
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'finanza_tasa.php',
        'descripcion' => 'Tasa oficial Bs/EUR',
    ],
    [
        'modulo' => 'Finanzas',
        'path' => 'api/finanza_cargo.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'finanza_cargo.php',
        'descripcion' => 'Registrar cargo financiero',
    ],
    [
        'modulo' => 'Finanzas',
        'path' => 'api/finanza_pago.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'finanza_pago.php',
        'descripcion' => 'Registrar pago',
    ],
    [
        'modulo' => 'Finanzas',
        'path' => 'api/finanza_pago_verificar.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'finanza_pago_verificar.php',
        'descripcion' => 'Verificar pago manualmente',
    ],
    [
        'modulo' => 'Finanzas',
        'path' => 'finanzas_asociacion.html',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'finanzas_asociacion.html',
        'descripcion' => 'Vista: estado financiero por asociación',
    ],
];
