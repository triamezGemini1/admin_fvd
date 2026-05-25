-- =============================================================================
-- FVD — Sincronizar tridente en `movimiento_torneo` desde `atletas`
-- =============================================================================
-- Preferir la rutina PHP auditable (prepared statements, estadísticas):
--   SyncMovimientoTorneoTridenteDesdeAtletas::ejecutar($pdo, true, false)
--   o MigracionAtletasAUsuarios::syncTridenteMovimientoTorneoDesdeAtletas($pdo)
--
-- Cruce: solo mismo número FVD (carnet FVD) en ambas tablas:
--   m.numfvd > 0 AND a.numfvd > 0 AND m.numfvd = a.numfvd
--
-- Regla en atletas (legacy): afiliación/anualidad 1 o 5, carnet 1 o 20, traspaso 1 o 6,
-- inscripción = 1. En `movimiento_torneo` se guardan banderas 0/1 (SUM(campo = 1) en informes).
--
-- ANTES: respaldo de `movimiento_torneo` y `atletas`.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- Estadística (ejecutar antes o después del UPDATE)
-- -----------------------------------------------------------------------------
-- SELECT
--   COUNT(*) AS atletas_filas_criterio,
--   COUNT(DISTINCT TRIM(cedula)) AS atletas_cedulas_distintas_criterio
-- FROM atletas
-- WHERE afiliacion IN (1, 5) OR anualidad IN (1, 5) OR carnet IN (1, 20)
--    OR traspaso IN (1, 6) OR inscripcion = 1;

UPDATE `movimiento_torneo` m
INNER JOIN `atletas` a ON m.`numfvd` > 0 AND a.`numfvd` > 0 AND m.`numfvd` = a.`numfvd`
INNER JOIN (
  SELECT a1.*
  FROM `atletas` a1
  INNER JOIN (
    SELECT `numfvd`, MIN(`id`) AS `min_id`
    FROM `atletas`
    WHERE `numfvd` > 0
    GROUP BY `numfvd`
  ) pick ON pick.`min_id` = a1.`id`
) ac ON ac.`numfvd` = a.`numfvd`
SET
  m.`afiliacion` = CASE WHEN ac.`afiliacion` IN (1, 5) THEN 1 ELSE 0 END,
  m.`anualidad` = CASE WHEN ac.`anualidad` IN (1, 5) THEN 1 ELSE 0 END,
  m.`carnet` = CASE WHEN ac.`carnet` IN (1, 20) THEN 1 ELSE 0 END,
  m.`traspaso` = CASE WHEN ac.`traspaso` IN (1, 6) THEN 1 ELSE 0 END,
  m.`inscripcion` = CASE WHEN ac.`inscripcion` = 1 THEN 1 ELSE 0 END
WHERE ac.`afiliacion` IN (1, 5)
   OR ac.`anualidad` IN (1, 5)
   OR ac.`carnet` IN (1, 20)
   OR ac.`traspaso` IN (1, 6)
   OR ac.`inscripcion` = 1;
