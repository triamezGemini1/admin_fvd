-- Gastos operativos por torneo (FVD): monto en Bs, tasa referente y equivalente EUR.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `finanza_gasto_torneo` (
  `id` int NOT NULL AUTO_INCREMENT,
  `torneo_id` int NOT NULL,
  `fecha` date NOT NULL,
  `concepto` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `monto_bs` decimal(14,2) NOT NULL,
  `tasa_eur_bs` decimal(14,4) NOT NULL COMMENT 'Bs por 1 EUR al registrar',
  `monto_eur` decimal(12,2) NOT NULL,
  `nro_factura` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notas` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_finanza_gasto_torneo` (`torneo_id`),
  KEY `idx_finanza_gasto_fecha` (`fecha`),
  CONSTRAINT `fk_finanza_gasto_torneo` FOREIGN KEY (`torneo_id`) REFERENCES `torneosact` (`torneo`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
