<?php

declare(strict_types=1);

/**
 * Rutas HTTP del módulo Informes (consolidado, recibos, actualización de deudas).
 *
 * @return list<array{path: string, methods: string|list<string>, handler: string, modulo: string, descripcion?: string}>
 */
return [
    [
        'modulo' => 'Informes',
        'path' => 'api/informe_consolidado.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'informe_consolidado.php',
        'descripcion' => 'Informe consolidado nacional / por asociación / renglón',
    ],
    [
        'modulo' => 'Informes',
        'path' => 'api/informe_movimiento_solicitar_carnet.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'informe_movimiento_solicitar_carnet.php',
        'descripcion' => 'Solicitud de carnet desde pantalla de informe',
    ],
    [
        'modulo' => 'Informes',
        'path' => 'api/informe_recibo.php',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'informe_recibo.php',
        'descripcion' => 'Datos de recibo de operación',
    ],
    [
        'modulo' => 'Informes',
        'path' => 'api/informe_recibo_actualizar_tasa.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'informe_recibo_actualizar_tasa.php',
        'descripcion' => 'Actualizar tasa en recibo',
    ],
    [
        'modulo' => 'Informes',
        'path' => 'api/informe_actualizar_deudas.php',
        'methods' => 'POST',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'informe_actualizar_deudas.php',
        'descripcion' => 'Recalcular deuda_asociaciones del torneo activo',
    ],
    [
        'modulo' => 'Informes',
        'path' => 'informes.html',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'informes.html',
        'descripcion' => 'Vista: informe consolidado y desgloses',
    ],
    [
        'modulo' => 'Informes',
        'path' => 'informe_recibo.html',
        'methods' => 'GET',
        'handler' => __DIR__ . DIRECTORY_SEPARATOR . 'Vistas' . DIRECTORY_SEPARATOR . 'informe_recibo.html',
        'descripcion' => 'Vista: recibo de pago (impresión)',
    ],
];
