<?php



declare(strict_types=1);



/**

 * @deprecated Use Fvd\Modulos\Atletas\Modelos\CargaTorneoDesdeAtletas — puente de compatibilidad.

 */

require_once __DIR__ . '/Autoload.php';

require_once __DIR__ . '/legacy_class_alias.php';



\Fvd\Autoload::register();

fvd_legacy_alias('CargaTorneoDesdeAtletas', \Fvd\Modulos\Atletas\Modelos\CargaTorneoDesdeAtletas::class);

