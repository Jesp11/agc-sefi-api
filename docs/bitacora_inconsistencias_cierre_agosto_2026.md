# Bitácora de Inconsistencias de Cierre Agosto 2026

Fecha de registro: 2026-08-30

## 1. Fecha de otorgación errónea en cartera individual

Se detectó un crédito individual que aparece en el Excel de cartera activa, pero en la base tiene una `fecha_otorgacion` fuera del periodo real del cierre.

Registro detectado:

- `id_cliente`: `TJSC001`
- `nombre_completo`: `TIFANY JAQUELINE SANCHEZ CARRILLO`
- `num_prog`: `65`
- `saldo_pendiente`: `4356.00`
- `estado`: `Activo`
- `fecha_otorgacion` en BD: `2029-08-25`

Impacto observado:

- El cierre de `2026-08-31` excluye este crédito al calcular cartera individual desde la base.
- El Excel `scripts/Actualizados/CARTERA DE CREDITOS INDIVIDUALES 2026.xlsx` sí lo considera en la suma de cartera activa.
- Esto explica una diferencia de `4356.00` entre el cálculo por BD y el total operativo del Excel de cartera individual.

Archivos relacionados:

- `scripts/Actualizados/CARTERA DE CREDITOS INDIVIDUALES 2026.xlsx`
- `scripts/Actualizados/CIERRE DE MES DE AGOSTO.xlsx`

## 2. Diferencia de 1000.00 entre Excel de cartera individual y Excel de cierre

Se detectó una inconsistencia entre los dos Excels fuente.

Valores observados:

- En `scripts/Actualizados/CARTERA DE CREDITOS INDIVIDUALES 2026.xlsx`, hoja `CARTERA ACTIVA `:
  - `AJ114 = 857643.00`
- En `scripts/Actualizados/CIERRE DE MES DE AGOSTO.xlsx`:
  - `C7 = 858643.00`

Diferencia:

- `1000.00`

Pista encontrada:

- En el Excel de cartera individual existe una fila con saldo exacto de `1000.00`:
  - cliente: `ROGELIO CARLOS CABALLERO LEYVA`
  - fecha: `2026-05-13`
  - asesor: `JOSSUE GIBRAN SOBREVILLA DIAZ`
  - saldo en Excel: `AJ71 = 1000.00`

Validación en BD:

- `id_cliente`: `RCCL001`
- `num_prog`: `66`
- `saldo_pendiente`: `1000.00`
- `fecha_otorgacion`: `2026-05-13`
- `estado`: `Activo`

Conclusión actual:

- La BD y el Excel de cartera individual sí contienen ese saldo de `1000.00`.
- La inconsistencia está entre el total usado en el Excel de cierre y el total del Excel de cartera individual.
- El Excel de cierre parece estar usando un ajuste manual o un total distinto no reflejado en `AJ114`.

## 3. Diferencia de 6 clientes entre la BD y el Excel de cierre mensual

Se detectó una diferencia en el bloque de conteo de clientes activos del cierre mensual de agosto de 2026.

Valores observados al corte de `2026-08-31`:

- En la BD:
  - créditos individuales activos: `104`
  - integrantes de grupos activos: `6`
  - total calculado por sistema: `110`
- En `scripts/Actualizados/CIERRE DE MES DE AGOSTO.xlsx`:
  - `C37 = 98` para `CLIENTES ACTIVOS`
  - `F37 = 6` para `2 GRUPOS ACTIVOS`
  - `E38 = 104` para `TOTAL CE CLIENTES`

Diferencia:

- `6` clientes

Validaciones realizadas:

- No se detectaron integrantes grupales duplicados en los grupos activos del cierre.
- No se detectaron integrantes grupales con crédito individual activo simultáneo que estuvieran inflando el total.
- La BD sí contiene `104` créditos individuales activos al `2026-08-31`.
- El libro detallado `scripts/Actualizados/CARTERA DE CREDITOS INDIVIDUALES 2026.xlsx` también apunta al universo de `104` activos, no a `98`.

Conclusión actual:

- La diferencia no parece originarse en la base de datos.
- La inconsistencia está entre el resumen mostrado en `CIERRE DE MES DE AGOSTO.xlsx` y el universo real de cartera individual activa usado por la BD y el Excel detallado de cartera.
- El bloque de clientes del cierre mensual parece estar resumido manualmente o con una fuente distinta no reflejada en el libro detallado.

## 4. Distribución de carteras: Refactorización a comparativo mes anterior vs mes actual

Se contrastó el bloque de `DISTRIBUCION DE CARTERAS` del sistema contra `scripts/Actualizados/CIERRE DE MES DE AGOSTO.xlsx`.

Valores observados en el Excel:

- `JOSSUE GIBRAN S. DIAZ`: `CLIENT/JUL = 33`, `CLIENT/AGO = 34`
- `JULIO CESA RAMIREZ RAMOS`: `CLIENT/JUL = 38`, `CLIENT/AGO = 39`
- `GIBRAN URIEL SOBREVILLA`: `CLIENT/JUL = 27`, `CLIENT/AGO = 31`
- totales: `98` y `104`

Valores calculados por BD al corte de `2026-08-31` (a partir de la asignación real en la cartera detallada):

- `GIBRAN URIEL SOBREVILLA`: `30`
- `JOSSUE GIBRAN S. DIAZ`: `38`
- `JULIO CESAR RAMIREZ RAMOS`: `36`
- total: `104`

Resolución aplicada:

- Se modificó la estructura del sistema en backend y frontend para que `Distribución de carteras` presente la comparativa mensual (`mes_anterior_label` vs `mes_actual_label`, ej. `CLIENT/JUL` vs `CLIENT/AGO`).
- Se implementó la lectura directa desde el libro de cierre mensual cuando esté disponible y un cálculo dinámico mes anterior vs mes actual desde la base de datos como fallback.
- Se actualizaron las vistas web, la exportación a Excel y el formato de impresión.

## 5. Mora activa: Causa y resolución de los 2,156.00

Se contrastó el bloque de mora entre el Excel de cierre mensual, el libro oficial `CARTERADEMORA.xlsx` y la base de datos.

Valores observados en el Excel de cierre mensual:

- `MORA ACTIVA = 19797.00`
- `MORA MUERTA = 109940.00`
- `TOTAL = 129737.00`

Valores en el sistema y en `CARTERADEMORA.xlsx` al corte de `2026-08-31`:

- `mora_activa = 17641.00`
- `mora_muerta = 109940.00`
- `total = 127581.00`

Diferencia identificada:

- `2156.00` concentrada exclusivamente en mora activa.

Rastreo exacto cliente por cliente:

1. `MARIA DE LOS ANGELES TAVERA MORENO`:
   - En Excel de cierre: `4365.00`
   - En BD y `CARTERADEMORA.xlsx`: `2909.00`
   - Causa: Se registraron 4 abonos de `364.00` cada uno (P-12, P-13, P-14, P-15) sumando `1456.00` de pagos aplicados.
2. `HECTOR ADAN HERNANDEZ GONZALEZ`:
   - En Excel de cierre: `4382.00`
   - En BD y `CARTERADEMORA.xlsx`: `3682.00`
   - Causa: Se registró un abono de `700.00` (P-7).
3. `NORMA CRUZ GUEVARA`: `1500.00` (coincide exacto).
4. `GABRIELA ROCIO SANCHEZ WHITAKER`: `750.00` (coincide exacto).
5. `LUCIA MILAGRO CRUZ DE LA TORRE`: `8800.00` (coincide exacto).

Conclusión:

- La diferencia de `2156.00` (`1456.00 + 700.00`) corresponde a cobranza real aplicada durante agosto.
- El Excel `CIERRE DE MES DE AGOSTO.xlsx` reutilizó los saldos del corte anterior (de hecho tituló el bloque como `CIERRE DE MORA JULIO 2026`).
- El valor del sistema (`17641.00`) y de `CARTERADEMORA.xlsx` es el financieramente actualizado y correcto.
