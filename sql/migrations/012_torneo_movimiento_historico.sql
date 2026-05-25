-- Tabla de auditoría / histórico de cambios en movimientos por torneo (traslados, carnet, etc.).
-- Si ya existe (p. ej. volcado legacy), este script no la modifica.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `torneo_movimiento_historico` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `torneo_id` int NOT NULL,
  `atleta_id` int DEFAULT NULL COMMENT 'id_usuario del atleta',
  `numfvd` int NOT NULL DEFAULT 0,
  `tipo` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'anualidad,carnet,afiliacion,traspaso,inscripcion_bandera,...',
  `valor_anterior` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valor_nuevo` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notas` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_torneo_mov_torneo` (`torneo_id`),
  KEY `idx_torneo_mov_numfvd` (`numfvd`),
  KEY `idx_torneo_mov_atleta` (`atleta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
