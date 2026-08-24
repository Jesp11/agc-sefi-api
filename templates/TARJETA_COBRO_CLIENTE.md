# A G C
## SERVICIOS FINANCIEROS
### TARJETA DE PAGOS

---

| **CLIENTE:** | {{NOMBRE_CLIENTE}} | **TASA:** | {{TASA}} |
| :--- | :--- | :--- | :--- |
| **DOMICILIO:** | {{DOMICILIO_CLIENTE}} | | |
| **CEL. CLIENTE:** | {{CELULAR_CLIENTE}} | **MONTO OTORGADO:** | ${{MONTO_OTORGADO}} |
| **ID:** | {{ID_CLIENTE}} | **PLAZO:** | {{PLAZO}} SEMANAS |
| **CICLO ACTUAL:** | {{CICLO_ACTUAL}} | **CICLO ANTERIOR:** | {{CICLO_ANTERIOR}} |
| **FECHA INICIO:** | {{FECHA_INICIO}} | **FECHA TERMINO:** | {{FECHA_TERMINO}} |

---

### REFERENCIAS

| Referencia | Nombre | Parentesco | Teléfono | Dirección |
| :--- | :--- | :--- | :--- | :--- |
| **REF. FAMILIAR** | {{REF_FAM_NOMBRE}} | {{REF_FAM_PARENTESCO}} | {{REF_FAM_CELULAR}} | {{REF_FAM_DIRECCION}} |
| **REF. PERSONAL / AMISTAD** | {{REF_PER_NOMBRE}} | {{REF_PER_PARENTESCO}} | {{REF_PER_CELULAR}} | {{REF_PER_DIRECCION}} |

**ASESOR:** {{NOMBRE_ASESOR}}

---

### CALENDARIO DE PAGOS

| FECHA | SEMANA | MONTO | MULTA | TOTAL | ASESOR (FIRMA) |
| :---: | :---: | :---: | :---: | :---: | :---: |
{{TABLA_PAGOS_FILAS}}

---

### MULTAS Y CONDICIONES

* **RETARDO EN HORARIO:** ${{MULTA_HORARIO}} *(El cierre de pago deberá efectuarse antes de las 15:00 horas)*
* **RETARDO POR DÍA:** ${{MULTA_DIA}}

**ACEPTO PLAZO Y CONDICIONES:**  
&nbsp;  
_________________________________________  
**{{NOMBRE_CLIENTE}}**  
**FIRMA DEL CLIENTE**

---

#### POLÍTICAS DE RENOVACIÓN
1. **Un retraso:** Pierde derecho de refinanciamiento anticipado y deberá esperar la renovación normal.
2. **Dos retrasos:** Con derecho a renovar al término de ciclo, sin aumento de crédito.
3. **Tres retrasos:** Pierde derecho a renovación, quedando suspendido durante dos ciclos.
