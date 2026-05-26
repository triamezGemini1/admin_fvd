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
        'path' => 'api/finanza_resumen_periodo.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'finanza_resumen_periodo.php',
        'descripcion' => 'Resumen FVD por periodo (renglones y desglose por asociación)',
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
    [
        'modulo' => 'Finanzas',
        'path' => 'resumen_finanzas_fvd.html',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'resumen_finanzas_fvd.html',
        'descripcion' => 'Vista: resumen finanzas FVD por periodo',
    ],
    [
        'modulo' => 'Finanzas',
        'path' => 'api/finanza_gasto_torneo.php',
        'methods' => ['GET', 'POST'],
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'finanza_gasto_torneo.php',
        'descripcion' => 'Gastos operativos por torneo',
    ],
    [
        'modulo' => 'Finanzas',
        'path' => 'gastos_torneo.html',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'gastos_torneo.html',
        'descripcion' => 'Formulario gastos por torneo (móvil)',
    ],
    [
        'modulo' => 'Finanzas',
        'path' => 'api/finanza_resultado_torneos.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'finanza_resultado_torneos.php',
        'descripcion' => 'Resultado financiero ingresos vs gastos por torneo',
    ],
    [
        'modulo' => 'Finanzas',
        'path' => 'resultado_financiero_torneo.html',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'resultado_financiero_torneo.html',
        'descripcion' => 'Vista: resultado financiero por torneo',
    ],
    [
        'modulo' => 'Finanzas',
        'path' => 'resumen_finanzas_fvd_detalle.html',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'resumen_finanzas_fvd_detalle.html',
        'descripcion' => 'Vista: detalle finanzas por torneo (asociaciones)',
    ],
];
