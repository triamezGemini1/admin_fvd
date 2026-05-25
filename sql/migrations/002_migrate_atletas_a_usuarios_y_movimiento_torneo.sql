-- =============================================================================
-- FVD — Migración de datos: atletas → usuarios + movimiento_torneo
-- =============================================================================
-- Ejecutar DESPUÉS de: 001_usuarios_maestro_movimiento_torneo.sql
-- Base de datos: fvdmasteradmin
--
-- CONTRASEÑA INICIAL de cada usuario creado (cambiar en el primer acceso):
--   MigracionFVD2026!
--
-- ANTES: respaldo completo de `atletas`, `usuarios`, `movimiento_torneo`.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- Validaciones previas (ejecutar y revisar resultado; no modifican datos)
-- -----------------------------------------------------------------------------
-- Cédulas duplicadas en atletas (bloquean INSERT por UNIQUE en usuarios.cedula):
--   SELECT TRIM(cedula) AS cedula, COUNT(*) AS n FROM atletas GROUP BY TRIM(cedula) HAVING n > 1;
-- Números FVD duplicados:
--   SELECT numfvd, COUNT(*) AS n FROM atletas GROUP BY numfvd HAVING n > 1 AND numfvd > 0;
-- -----------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 0;

-- Si aplicaste una versión anterior de 001 con columna movimiento, elimínala:
-- ALTER TABLE movimiento_torneo DROP COLUMN movimiento;

-- -----------------------------------------------------------------------------
-- Paso 1 — Limpiar destinos de la conversión (ajusta según tu caso)
-- -----------------------------------------------------------------------------
-- Opción A — Solo movimientos y OTP (conservas filas manuales en usuarios):
TRUNCATE TABLE `movimiento_torneo`;
TRUNCATE TABLE `auth_celular_login_otp`;

-- Opción B — Reinicio total de usuarios del portal (BORRA ADMINS; exporta antes):
-- TRUNCATE TABLE `usuarios`;

-- -----------------------------------------------------------------------------
-- Paso 2 — Usuarios maestros desde cada fila de atletas
-- -----------------------------------------------------------------------------
-- username: userfvd{numfvd}_{id_atleta} (único por fila atleta)
-- email:    atleta.{id}@migracion.fvd.local (único; puedes actualizar luego)
-- asociacion_id: NULL si asociación no existe en catálogo
-- posirnk:    categ si 0 < categ < 9999 (sentinel en datos históricos), si no 0
--
-- Cédula duplicada en atletas: solo se migra UN registro por cédula (MIN(id)),
-- el resto debe resolverse en atletas o ajustando índices en usuarios.

INSERT INTO `usuarios` (
  `numfvd`,
  `cedula`,
  `sexo`,
  `nombre`,
  `fechnac`,
  `email`,
  `celular`,
  `username`,
  `password_hash`,
  `role`,
  `status`,
  `asociacion_id`,
  `posirnk`,
  `urlimgfoto`,
  `urlimgcedula`
)
SELECT
  a.`numfvd`,
  TRIM(a.`cedula`),
  CASE WHEN a.`sexo` IN (1, 2) THEN a.`sexo` ELSE 0 END,
  TRIM(a.`nombre`),
  a.`fechnac`,
  CONCAT('atleta.', a.`id`, '@migracion.fvd.local'),
  NULLIF(TRIM(SUBSTRING(COALESCE(a.`celular`, ''), 1, 20)), ''),
  SUBSTRING(CONCAT('userfvd', a.`numfvd`, '_', a.`id`), 1, 60),
  '$2y$10$B6phliqgjw4gkwcmt1fV4u6b6Ptpid.iCS1Jgik/Gk/YUK.qpVvyC',
  'usuario',
  9,
  ax.`id`,
  CASE WHEN a.`categ` > 0 AND a.`categ` < 9999 THEN a.`categ` ELSE 0 END,
  NULLIF(TRIM(a.`foto`), ''),
  NULLIF(TRIM(a.`cedula_img`), '')
FROM `atletas` a
INNER JOIN (
  SELECT MIN(`id`) AS `id` FROM `atletas` GROUP BY TRIM(`cedula`)
) `canon` ON `canon`.`id` = a.`id`
LEFT JOIN `asociaciones` ax ON ax.`id` = a.`asociacion`
WHERE NOT EXISTS (
  SELECT 1 FROM `usuarios` u
  WHERE u.`username` = SUBSTRING(CONCAT('userfvd', a.`numfvd`, '_', a.`id`), 1, 60)
)
AND NOT EXISTS (
  SELECT 1 FROM `usuarios` u2
  WHERE TRIM(u2.`cedula`) = TRIM(a.`cedula`)
);
-- Si hay cédulas duplicadas en atletas, solo el atleta con menor `id` por cédula entra aquí.

-- -----------------------------------------------------------------------------
-- Paso 3 — movimiento_torneo: solo atletas con movimiento = 1; cruce con
--          usuarios por cédula. torneo_id fijo = 6 (resto: snapshot atleta).
-- -----------------------------------------------------------------------------
INSERT INTO `movimiento_torneo` (
  `id_usuario`,
  `cedula`,
  `numfvd`,
  `sexo`,
  `asociacion_id`,
  `estatus`,
  `afiliacion`,
  `anualidad`,
  `carnet`,
  `traspaso`,
  `inscripcion`,
  `torneo_id`,
  `posrnk`
)
SELECT
  u.`id`,
  TRIM(a.`cedula`),
  a.`numfvd`,
  a.`sexo`,
  ax.`id`,
  a.`estatus`,
  a.`afiliacion`,
  a.`anualidad`,
  a.`carnet`,
  a.`traspaso`,
  a.`inscripcion`,
  6,
  CASE WHEN a.`categ` > 0 AND a.`categ` < 9999 THEN a.`categ` ELSE 0 END
FROM `atletas` a
INNER JOIN `usuarios` u ON TRIM(u.`cedula`) = TRIM(a.`cedula`)
LEFT JOIN `asociaciones` ax ON ax.`id` = a.`asociacion`
WHERE a.`movimiento` = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- Paso 4 — Comprobaciones
-- -----------------------------------------------------------------------------
-- SELECT COUNT(*) AS atletas FROM atletas;
-- SELECT COUNT(*) AS usuarios_migrados FROM usuarios WHERE email LIKE '%@migracion.fvd.local';
-- SELECT COUNT(*) AS movimientos FROM movimiento_torneo;
-- movimientos = atletas con movimiento = 1 y cédula en usuarios; torneo_id = 6

-- -----------------------------------------------------------------------------
-- Opcional — Copiar email real desde atletas (solo si no choca UNIQUE email)
-- -----------------------------------------------------------------------------
-- UPDATE usuarios u
-- INNER JOIN atletas a
--   ON u.username = SUBSTRING(CONCAT('userfvd', a.numfvd, '_', a.id), 1, 60)
-- SET u.email = SUBSTRING(TRIM(a.email), 1, 100)
-- WHERE a.email IS NOT NULL
--   AND TRIM(a.email) <> ''
--   AND TRIM(a.email) <> '@'
--   AND TRIM(a.email) LIKE '%_@_%'
--   AND NOT EXISTS (
--     SELECT 1 FROM usuarios u2
--     WHERE u2.email = SUBSTRING(TRIM(a.email), 1, 100) AND u2.id <> u.id
--   );
