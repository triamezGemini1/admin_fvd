-- Vincula integrantes de pareja/equipo en el mismo torneo (portal `usuarios` + `movimiento_torneo`).
-- Nota: la tabla legacy `equipos` es ranking por club/código; no sustituye este vínculo por usuario.
-- Requiere haber aplicado antes `004_movimiento_torneo_grupo_nombre.sql` (columna `grupo_nombre`).
SET NAMES utf8mb4;

ALTER TABLE `movimiento_torneo`
  ADD COLUMN `grupo_id` int unsigned DEFAULT NULL
  COMMENT 'Mismo id para todos los integrantes de una pareja/equipo en este torneo'
  AFTER `grupo_nombre`,
  ADD KEY `idx_mov_torneo_grupo` (`torneo_id`, `grupo_id`);
