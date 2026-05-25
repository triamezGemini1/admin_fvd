<?php

declare(strict_types=1);

/**
 * Bootstrap común para controladores dentro de app/Modulos/{Modulo}/Controladores/.
 */
if (!defined('FVD_ROOT')) {
    define('FVD_ROOT', dirname(__DIR__, 2));
}

require_once FVD_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Autoload.php';
\Fvd\Autoload::register();
