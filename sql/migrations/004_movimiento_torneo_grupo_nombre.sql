-- Nombre de pareja / equipo en inscripciones (opcional, no rompe filas existentes)
-- Nota: la tabla legacy `equipos` del volcado antiguo guarda ranking por club/código en torneo;
-- el portal vincula integrantes con `grupo_id` + `grupo_nombre` en `movimiento_torneo` (ver migración 005).
SET NAMES utf8mb4;

ALTER TABLE `movimiento_torneo`
  ADD COLUMN `grupo_nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
  COMMENT 'Nombre de pareja o de equipo (modalidades 2 y 3)'
  AFTER `posrnk`;
