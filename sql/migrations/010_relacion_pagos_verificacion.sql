-- FVD — Verificación / conciliación de pagos en `relacion_pagos`
-- Solo los pagos con verificado = 1 descuentan del saldo (FinanzaFvd, deuda_asociaciones).
SET NAMES utf8mb4;

ALTER TABLE `relacion_pagos`
  ADD COLUMN `verificado` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = contabilizado contra deuda' AFTER `observaciones`,
  ADD COLUMN `verificado_en` datetime DEFAULT NULL COMMENT 'Fecha/hora verificación o conciliación' AFTER `verificado`,
-- Opcional: marcar pagos FVD históricos como ya verificados (solo si aplica en su corte contable):
-- UPDATE relacion_pagos SET verificado = 1, verificado_en = COALESCE(fecha_creacion, NOW()) WHERE torneo_id = 0;
