-- FVD — Finanzas por asociación (cargos en EUR, pagos con conversión Bs según tasa BCV)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `finanza_parametro` (
  `id` tinyint unsigned NOT NULL DEFAULT 1,
  `tasa_eur_bs` decimal(14,4) NOT NULL DEFAULT 48.0000 COMMENT 'Bs por 1 EUR (tasa oficial referencia)',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `finanza_parametro` (`id`, `tasa_eur_bs`) VALUES (1, 48.0000);

CREATE TABLE IF NOT EXISTS `finanza_cargo` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asociacion_id` int NOT NULL,
  `concepto` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `monto_eur` decimal(12,2) NOT NULL,
  `referencia` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_emision` date NOT NULL,
  `notas` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_finanza_cargo_asoc` (`asociacion_id`),
  KEY `idx_finanza_cargo_fecha` (`fecha_emision`),
  CONSTRAINT `fk_finanza_cargo_asoc` FOREIGN KEY (`asociacion_id`) REFERENCES `asociaciones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `finanza_pago` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asociacion_id` int NOT NULL,
  `monto_eur` decimal(12,2) NOT NULL,
  `monto_bs` decimal(14,2) NOT NULL,
  `tasa_eur_bs` decimal(14,4) NOT NULL,
  `referencia` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `banco` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha_pago` date NOT NULL,
  `observacion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_finanza_pago_asoc` (`asociacion_id`),
  KEY `idx_finanza_pago_fecha` (`fecha_pago`),
  CONSTRAINT `fk_finanza_pago_asoc` FOREIGN KEY (`asociacion_id`) REFERENCES `asociaciones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
