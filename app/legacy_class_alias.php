<?php

declare(strict_types=1);

/**
 * Puente de compatibilidad: expone clases legacy (sin namespace) desde módulos namespaced.
 *
 * @param class-string $legacyName Nombre global histórico (ej. Torneo)
 * @param class-string $namespaced Clase Fvd\Modulos\...
 */
function fvd_legacy_alias(string $legacyName, string $namespaced): void
{
    if (!class_exists($namespaced, true)) {
        throw new RuntimeException("Clase modular no encontrada: {$namespaced}");
    }
    if (!class_exists($legacyName, false)) {
        class_alias($namespaced, $legacyName);
    }
}
