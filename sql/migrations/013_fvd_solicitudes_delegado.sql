-- Tabla de referencia unificada para solicitudes del delegado (traspaso, carnet, afiliación).
-- Alinear con volcados existentes; si la tabla ya existe, no se altera.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `fvd_solicitudes_delegado` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tipo` enum('traspaso','carnet','afiliacion') COLLATE utf8mb4_unicode_ci NOT NULL,
  `asociacion_id` int NOT NULL COMMENT 'Asociación de origen del delegado / del atleta según flujo',
  `atleta_id` int NOT NULL COMMENT 'id_usuario del atleta',
  `delegado_id` int DEFAULT NULL COMMENT 'id_usuario del delegado que registró',
  `asociacion_destino_id` int DEFAULT NULL COMMENT 'Solo traspaso: asociación destino',
  `nota` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Metadatos libres (p. ej. torneo_id, movimiento_id)',
  `estado` enum('pendiente','aprobada','rechazada') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resuelto_en` datetime DEFAULT NULL,
  `resuelto_por_user_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fvd_sol_estado` (`estado`),
  KEY `idx_fvd_sol_asoc` (`asociacion_id`),
  KEY `idx_fvd_sol_atleta` (`atleta_id`),
  KEY `idx_fvd_sol_tipo_est` (`tipo`, `estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
