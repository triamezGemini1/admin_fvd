-- Avisos para administración general (delegado → admin; base para push futuro).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `fvd_admin_notificaciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `admin_user_id` int DEFAULT NULL,
  `tipo` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mensaje` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `leido` tinyint(1) NOT NULL DEFAULT 0,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fvd_admin_notif_leido` (`leido`),
  KEY `idx_fvd_admin_notif_creado` (`creado_en`),
  KEY `idx_fvd_admin_notif_admin` (`admin_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
