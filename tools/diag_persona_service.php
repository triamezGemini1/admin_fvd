<?php

declare(strict_types=1);

/**
 * Diagnóstico rápido: PersonaService + .env
 * Uso: php tools/diag_persona_service.php [cedula] [nacionalidad]
 */
define('FVD_ROOT', dirname(__DIR__));
require FVD_ROOT . '/app/Config/Env.php';
\Fvd\Config\Env::load();
require FVD_ROOT . '/app/Autoload.php';
\Fvd\Autoload::register();

$cedula = $argv[1] ?? '12345678';
$nac = $argv[2] ?? 'V';

echo "FVD_ROOT: " . FVD_ROOT . PHP_EOL;
echo ".env existe: " . (is_file(FVD_ROOT . '/.env') ? 'si' : 'no') . PHP_EOL;
echo "PersonaService configurado: " . (\Fvd\Servicios\PersonaService::isConfigured() ? 'si' : 'no') . PHP_EOL;
echo "FVD_PERSONA_DB_DATABASE: " . \Fvd\Config\Env::get('FVD_PERSONA_DB_DATABASE', '(default personas)') . PHP_EOL;

$result = \Fvd\Servicios\PersonaService::buscarPorIdentificacion($nac, $cedula);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
