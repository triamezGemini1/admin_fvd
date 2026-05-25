-- Migración FVD: tabla maestra `usuarios` y `movimiento_torneo`
-- Base de datos: fvdmasteradmin
-- Ejecutar manualmente tras backup (destruye las definiciones previas de `usuarios`).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `movimiento_torneo`;

-- Si en tu instalación existe la tabla legada `movimientos` (no forma parte del volcado por defecto),
-- sustituye el DROP anterior por:
-- RENAME TABLE `movimientos` TO `movimiento_torneo`;
-- y luego ajusta columnas con ALTER TABLE para coincidir con el modelo nuevo.

DROP TABLE IF EXISTS `auth_celular_login_otp`;
DROP TABLE IF EXISTS `usuarios`;

CREATE TABLE `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `numfvd` int NOT NULL DEFAULT 0,
  `cedula` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sexo` tinyint NOT NULL DEFAULT 0 COMMENT '1=M, 2=F, etc. (linea atletas)',
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `fechnac` date DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `celular` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Valores por defecto recomendados desde la app: prefijo userfvd + identificador FVD',
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'usuario' COMMENT 'admingral | delegado | usuario',
  `status` int NOT NULL DEFAULT 9 COMMENT '9 = cuenta habilitada para acceso (alineado a estatus atleta)',
  `asociacion_id` int DEFAULT NULL,
  `posirnk` int NOT NULL DEFAULT 0,
  `urlimgfoto` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `urlimgcedula` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_username` (`username`),
  UNIQUE KEY `uq_usuarios_email` (`email`),
  UNIQUE KEY `uq_usuarios_cedula` (`cedula`),
  KEY `idx_usuarios_numfvd` (`numfvd`),
  KEY `idx_usuarios_status` (`status`),
  KEY `idx_usuarios_asociacion` (`asociacion_id`),
  CONSTRAINT `fk_usuarios_asociacion` FOREIGN KEY (`asociacion_id`) REFERENCES `asociaciones` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `movimiento_torneo` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_usuario` int NOT NULL,
  `cedula` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `numfvd` int NOT NULL DEFAULT 0,
  `sexo` int NOT NULL DEFAULT 0,
  `asociacion_id` int DEFAULT NULL,
  `estatus` int NOT NULL DEFAULT 0,
  `afiliacion` int NOT NULL DEFAULT 0,
  `anualidad` int NOT NULL DEFAULT 0,
  `carnet` int NOT NULL DEFAULT 0,
  `traspaso` int NOT NULL DEFAULT 0,
  `inscripcion` int NOT NULL DEFAULT 0,
  `torneo_id` int NOT NULL DEFAULT 0,
  `posrnk` int NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mov_torneo_usuario` (`id_usuario`),
  KEY `idx_mov_torneo_torneo` (`torneo_id`),
  KEY `idx_mov_torneo_asociacion` (`asociacion_id`),
  CONSTRAINT `fk_movimiento_torneo_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_movimiento_torneo_asociacion` FOREIGN KEY (`asociacion_id`) REFERENCES `asociaciones` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Soporte futuro: código de un solo uso asociado al usuario (hash, no texto plano)
CREATE TABLE `auth_celular_login_otp` (
  `usuario_id` int NOT NULL,
  `otp_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`usuario_id`),
  CONSTRAINT `fk_otp_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
