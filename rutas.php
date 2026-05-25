<?php

declare(strict_types=1);

/**
 * Registro central de rutas HTTP del portal FVD.
 * Cada módulo en app/Modulos/{Nombre}/rutas.php devuelve un array de definiciones.
 *
 * Formato de cada ruta:
 *   - path:     ruta pública relativa al proyecto (ej. api/crud_torneos.php)
 *   - methods:  GET|POST|PUT|PATCH|DELETE o lista
 *   - handler:  ruta absoluta al script PHP que atiende la petición
 *   - modulo:   identificador del módulo (exportación / documentación)
 *
 * @return list<array{path: string, methods: string|list<string>, handler: string, modulo: string}>
 */
function fvd_rutas_registro(): array
{
    $rutas = [];
    $modulosDir = __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modulos';
    if (!is_dir($modulosDir)) {
        return $rutas;
    }

    foreach (scandir($modulosDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $rutasModulo = $modulosDir . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . 'rutas.php';
        if (!is_file($rutasModulo)) {
            continue;
        }
        $def = require $rutasModulo;
        if (is_array($def)) {
            foreach ($def as $ruta) {
                if (is_array($ruta) && isset($ruta['path'], $ruta['handler'])) {
                    $rutas[] = $ruta;
                }
            }
        }
    }

    return $rutas;
}

/**
 * Resuelve el handler absoluto para una ruta pública (útil para front controller futuro).
 */
function fvd_rutas_resolver(string $pathPublico): ?string
{
    $pathPublico = ltrim(str_replace('\\', '/', $pathPublico), '/');
    foreach (fvd_rutas_registro() as $ruta) {
        if (ltrim(str_replace('\\', '/', $ruta['path']), '/') === $pathPublico) {
            $handler = $ruta['handler'];
            if (is_file($handler)) {
                return $handler;
            }
        }
    }

    return null;
}
