# Motor de Recomendación y Simulación de Créditos

Este documento describe las reglas de negocio, los cálculos matemáticos y el funcionamiento técnico del **Motor de Elegibilidad y Recomendación de Créditos** de la API.

## Arquitectura del Motor
El motor está construido utilizando un patrón de "Servicios" (Service Pattern) en Laravel y se divide en 3 capas fundamentales:
1. **Catálogos:** Definen los factores matemáticos estáticos y los requisitos (montos mínimos).
2. **Servicio de Elegibilidad:** Analiza el contexto de un cliente o grupo y dictamina a qué tasa tienen derecho.
3. **Simulador de Amortización:** Toma la tasa elegible, aplica la fórmula matemática financiera y despliega la tabla de pagos.

---

## 1. El Modelo Matemático (Factor de Pago Semanal)

El sistema financiero opera bajo un modelo de **Factor de Pago Semanal por cada $1,000 autorizados**.
Esto significa que las "tasas" en realidad no representan el porcentaje de interés anual, sino que determinan de cuánto será la cuota semanal del cliente.

**Fórmula Exacta utilizada:**
1. `pago_semanal = (monto_solicitado / 1000) * factor_semanal_segun_tabla`
2. `total_a_pagar = pago_semanal * plazo_en_semanas`
3. `interes_total_devengado = total_a_pagar - monto_solicitado`

### Ejemplo Práctico (Crédito Individual TCIPE14):
*   **Monto Solicitado:** $9,000
*   **Plazo:** 16 semanas
*   **Factor en Tabla (TCIPE14 a 16 sem):** $94.00

*Cálculo:*
*   Pago semanal: (9000 / 1000) * 94 = **$846 semanales**
*   Total a pagar: $846 * 16 = **$13,536**
*   Interés real cobrado: $13,536 - $9,000 = **$4,536**

---

## 2. Reglas de Elegibilidad (Promoción de Tasas)

### Créditos Individuales
El sistema evalúa 3 parámetros principales para asignar la tasa: `ciclo`, `buen_historial` y `cantidad_referidos`.

*   **Ciclo 0 (Nuevo Ingreso):** Siempre arranca en la Tasa Normal (`TCIN21`). Monto mínimo: $3,000. No se permiten excepciones ni montos mayores durante la primera evaluación.
*   **Ciclos 4 a 7:** Si cuenta con historial positivo y tiene al menos 2 referidos, se promueve a Tasa Preferencial (`TCIP18`).
*   **Ciclos 8 a 11:** Si cuenta con historial positivo y tiene al menos 3 referidos, se promueve a Tasa Preferencial Especial (`TCIPE14`).
*   **Ciclos 12+:** Si mantiene el buen historial, alcanza el nivel máximo: Tasa VIP (`TCIPV10`).
*   *Nota de Origen:* Si el `origen` viene marcado como `"competencia"`, el sistema tiene la directriz de brincarlo directamente a una tasa preferencial para ganar su cartera.

### Créditos Grupales
La evaluación es ligeramente diferente. Importa el ciclo, pero también el **origen** del grupo y el monto global solicitado.

*   **Montos Mínimos por Origen:**
    *   `nuevo`: $15,000
    *   `competencia`: $25,000
    *   `casa`: $10,000
    *   `referido_socio`: $20,000
*   Al igual que en individuales, en el **Ciclo 0** no se puede sobrepasar el límite mínimo correspondiente a su origen.
*   Conforme el ciclo avanza y se mantienen buenas referencias globales, la tasa se va reduciendo progresivamente (`TCGN10` -> `TCGP07` -> `TCGPE04` -> `TCGPEV01` -> `TCGEC00`).

---

## 3. Uso de los Endpoints (API)

Para consumir este motor desde un frontend, el flujo recomendado es:

### Paso A: Consultar Catálogos
Antes de mostrar el formulario de simulación, consulta los catálogos para saber qué "orígenes" existen y de cuánto es el límite de monto mínimo que debes permitir en los inputs numéricos:

```bash
GET /api/simular/catalogo/individual
GET /api/simular/catalogo/grupal
```
*Esto te devolverá los `origenes` permitidos y el `monto_minimo` exacto que no puede ser violado.*

### Paso B: Enviar Simulación (Cotización)
Una vez recolectados los datos iniciales, mándalos al motor para que procese la regla matemática.

```bash
POST /api/simular/individual
{
    "ciclo": 8,
    "monto_solicitado": 9000,
    "buen_historial": true,
    "cantidad_referidos": 3,
    "origen": "nuevo"
}
```

El motor validará si el monto es aceptable para el ciclo, procesará la tasa elegible (ej. TCIPE14) y devolverá las "opciones" disponibles (ej. planes a 12, 14 y 16 semanas). El cliente deberá elegir una de esas opciones devueltas para finalmente generar el registro oficial del crédito.
