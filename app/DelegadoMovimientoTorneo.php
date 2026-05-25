<?php

declare(strict_types=1);

/**
 * @deprecated Use Fvd\Modulos\Delegados\Modelos\DelegadoMovimientoTorneo — puente de compatibilidad.
 */
require_once __DIR__ . '/Autoload.php';
require_once __DIR__ . '/legacy_class_alias.php';

\Fvd\Autoload::register();
fvd_legacy_alias('DelegadoMovimientoTorneo', \Fvd\Modulos\Delegados\Modelos\DelegadoMovimientoTorneo::class);
