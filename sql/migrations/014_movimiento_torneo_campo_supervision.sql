-- Campo `movimiento` en `movimiento_torneo`: supervisión FVD (9 = carnet/traspaso aprobado).
-- Los indicadores afiliacion/carnet/traspaso/inscripcion siguen en 1 para contadores financieros.

SET NAMES utf8mb4;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'movimiento_torneo'
    AND COLUMN_NAME = 'movimiento'
);

SET @sql_add := IF(
  @col_exists = 0,
  'ALTER TABLE `movimiento_torneo` ADD COLUMN `movimiento` int NOT NULL DEFAULT 0 COMMENT ''Estado supervisión FVD: 9=aprobado carnet/traspaso'' AFTER `inscripcion`',
  'SELECT 1'
);
PREPARE stmt FROM @sql_add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
