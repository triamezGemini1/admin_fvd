-- =============================================================================
-- FVD — Organización rectora (entidad federativa central, no es una asociación)
-- =============================================================================
-- Ejecutar sobre fvdmasteradmin. `torneosact.organizacion_id` referencia este id
-- (organización FVD), no asociaciones provinciales.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `organizacion_fvd` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `direccion` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numreg` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nº registro / identificación legal',
  `providencia` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responsable_principal` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Autoridad de contacto (p. ej. secretaría general)',
  `indica` int NOT NULL DEFAULT 0,
  `estatus` int NOT NULL DEFAULT 1 COMMENT '1 = rectora activa en el sistema',
  `fechreg` date DEFAULT NULL,
  `fechprovi` date DEFAULT NULL,
  `ultelECC` date DEFAULT NULL,
  `logo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_estatus` (`estatus`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `organizacion_fvd` (
  `nombre`, `direccion`, `telefono`, `email`, `numreg`, `providencia`,
  `responsable_principal`, `indica`, `estatus`, `fechreg`, `logo`
)
SELECT
  'Federación Venezolana de Dominó',
  'Caracas',
  '',
  'fvdsecretaria@gmail.com',
  'REG-FVD',
  '',
  'Secretaría FVD',
  0,
  1,
  CURDATE(),
  NULL
WHERE NOT EXISTS (SELECT 1 FROM `organizacion_fvd` LIMIT 1);

-- Nota: torneos nuevos usan el id activo devuelto por la app (OrganizacionFvd::idOrganizadoraActiva).
