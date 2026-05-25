# Vistas — módulo Informes

| Archivo | Uso |
|---------|-----|
| `informes.html` | Informe consolidado nacional, desglose por asociación y renglón |
| `informe_recibo.html` | Recibo de operación (impresión) |

La lógica de renderizado está en `src/js/informes.js` e `src/js/informe_recibo.js`.

Diseño 14": tablas con `admin-crud-table--compact`, `text-sm` y contenedores `ag-inf-scroll` (`overflow-x-auto`).
