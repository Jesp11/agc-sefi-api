# Documentación de API - Autenticación JWT

Esta API utiliza **JSON Web Tokens (JWT)** para la autenticación. Todos los endpoints de autenticación están agrupados bajo el prefijo `/api/auth`.

---

## 1. Registro de Usuario
Crea una nueva cuenta de usuario en el sistema.

- **URL:** `/api/auth/register`
- **Método:** `POST`
- **Auth Requerida:** No

### Parámetros (Request Body - JSON)
| Campo | Tipo | Requerido | Descripción |
| :--- | :--- | :--- | :--- |
| `name` | String | Sí | Nombre completo del usuario (2-100 caracteres). |
| `email` | String | Sí | Correo electrónico único. |
| `password` | String | Sí | Contraseña (mínimo 6 caracteres). |
| `password_confirmation` | String | Sí | Debe coincidir con `password`. |

### Respuesta Exitosa (201 Created)
```json
{
    "message": "Usuario registrado exitosamente",
    "user": {
        "id": 1,
        "name": "Jesus Ponce",
        "email": "jesus@example.com",
        "created_at": "2026-05-08T06:52:48.000000Z",
        "updated_at": "2026-05-08T06:52:48.000000Z"
    }
}
```

---

## 2. Inicio de Sesión (Login)
Autentica al usuario y devuelve un token de acceso.

- **URL:** `/api/auth/login`
- **Método:** `POST`
- **Auth Requerida:** No

### Parámetros (Request Body - JSON)
| Campo | Tipo | Requerido | Descripción |
| :--- | :--- | :--- | :--- |
| `email` | String | Sí | Correo electrónico del usuario. |
| `password` | String | Sí | Contraseña del usuario. |

### Respuesta Exitosa (200 OK)
```json
{
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "token_type": "bearer",
    "expires_in": 3600
}
```

---

## 3. Obtener Usuario Autenticado (Me)
Devuelve los datos del usuario asociado al token actual.

- **URL:** `/api/auth/me`
- **Método:** `POST`
- **Auth Requerida:** Sí (Bearer Token)

### Headers
| Header | Valor |
| :--- | :--- |
| `Authorization` | `Bearer <tu_token_aqui>` |

### Respuesta Exitosa (200 OK)
```json
{
    "id": 1,
    "name": "Jesus Ponce",
    "email": "jesus@example.com",
    "email_verified_at": null,
    "created_at": "2026-05-08T06:52:48.000000Z",
    "updated_at": "2026-05-08T06:52:48.000000Z"
}
```

---

## 4. Refrescar Token
Invalida el token actual y genera uno nuevo con un tiempo de vida renovado.

- **URL:** `/api/auth/refresh`
- **Método:** `POST`
- **Auth Requerida:** Sí (Bearer Token)

### Respuesta Exitosa (200 OK)
Devuelve un nuevo objeto de token igual al del login.

---

## 5. Cerrar Sesión (Logout)
Invalida permanentemente el token actual.

- **URL:** `/api/auth/logout`
- **Método:** `POST`
- **Auth Requerida:** Sí (Bearer Token)

### Respuesta Exitosa (200 OK)
```json
{
    "message": "Sesión cerrada exitosamente"
}
```

---

# Módulos de Negocio

Todos los endpoints a continuación requieren autenticación mediante un **Bearer Token** en el header `Authorization`.

## 6. Clientes

Gestiona la información de los clientes.

### 6.1 Listar Clientes
- **URL:** `/api/clientes`
- **Método:** `GET`
- **Respuesta:** Paginated list of clients with their related data.

### 6.2 Crear Cliente
- **URL:** `/api/clientes`
- **Método:** `POST`
- **Body (JSON):**
```json
{
    "id_asesor": 1,
    "nombre_completo": "Juan Pérez",
    "curp": "PERJ800101HDFRRN01",
    "clave_elector": "ABC123456789",
    "telefono": "5512345678",
    "direccion": "Av. Siempre Viva 123",
    "entre_calles": "Calle 1 y Calle 2",
    "ocupacion": "Comerciante",
    "direccion_trabajo": "Mercado Central Local 5",
    "telefono_trabajo": "5598765432",
    "id_grupo": 1
}
```
*Nota: El `id_cliente` se genera automáticamente. El campo `id_asesor` es obligatorio a menos que proporciones un `id_grupo`; si se proporciona el `id_grupo`, el cliente heredará automáticamente el asesor de dicho grupo y se le asociará de inmediato.*

### 6.3 Ver Cliente
- **URL:** `/api/clientes/{id}`
- **Método:** `GET`
- **Respuesta:** Detalle completo del cliente incluyendo sus créditos, referencias, avales, asesor y grupos.

### 6.4 Actualizar Cliente
- **URL:** `/api/clientes/{id}`
- **Método:** `PUT/PATCH`
- **Body (JSON):** Los mismos campos que en la creación, pero todos son opcionales (`sometimes`).

### 6.5 Eliminar Cliente
- **URL:** `/api/clientes/{id}`
- **Método:** `DELETE`


---

## 7. Asesores

Gestiona el personal encargado de los créditos.

### 7.1 Listar Asesores
- **URL:** `/api/asesores`
- **Método:** `GET`

### 7.2 Crear Asesor
- **URL:** `/api/asesores`
- **Método:** `POST`
- **Body (JSON):**
```json
{
    "nombre_asesor": "Jossue"
}
```

### 7.3 Ver Asesor
- **URL:** `/api/asesores/{id}`
- **Método:** `GET`

### 7.4 Actualizar Asesor
- **URL:** `/api/asesores/{id}`
- **Método:** `PUT/PATCH`

### 7.5 Eliminar Asesor
- **URL:** `/api/asesores/{id}`
- **Método:** `DELETE`


---

## 8. Créditos

Gestiona los préstamos otorgados.

### 8.1 Listar Créditos
- **URL:** `/api/creditos`
- **Método:** `GET`

### 8.2 Crear Crédito Individual
- **URL:** `/api/creditos`
- **Método:** `POST`
- **Body (JSON):**
```json
{
    "id_cliente": "LMDT001",
    "fecha_otorgacion": "2026-05-09",
    "monto_otorgado": 5000.00,
    "interes": 500.00,
    "total": 5500.00,
    "plazos": 10,
    "valor_ficha": 550.00,
    "dias_pago": "Lunes"
}
```
*Nota: El sistema detecta automáticamente que es un crédito **Individual** al recibir `id_cliente`.*

### 8.3 Crear Crédito Grupal
- **URL:** `/api/creditos`
- **Método:** `POST`
- **Body (JSON):**
```json
{
    "id_grupo": 1,
    "fecha_otorgacion": "2026-05-09",
    "monto_otorgado": 20000.00,
    "interes": 2000.00,
    "total": 22000.00,
    "plazos": 10,
    "valor_ficha": 2200.00,
    "dias_pago": "Miércoles"
}
```
*Nota: El sistema detecta automáticamente que es un crédito **Grupal** al recibir `id_grupo`.*
*Nota: El `id_asesor` se asigna automáticamente basándose en el asesor del cliente (si es individual) o del grupo (si es grupal). El `ciclo` se establece por defecto en 0.*

### 8.4 Ver Crédito
- **URL:** `/api/creditos/{id}`
- **Método:** `GET`

### 8.5 Actualizar Crédito
- **URL:** `/api/creditos/{id}`
- **Método:** `PUT/PATCH`

### 8.6 Eliminar Crédito
- **URL:** `/api/creditos/{id}`
- **Método:** `DELETE`


---

## 9. Grupos

Gestiona grupos de clientes para créditos grupales.

### 9.1 Listar Grupos
- **URL:** `/api/grupos`
- **Método:** `GET`

### 9.2 Crear Grupo
- **URL:** `/api/grupos`
- **Método:** `POST`
- **Body (JSON):**
```json
{
    "nombre_grupo": "Mujeres Emprendedoras",
    "id_asesor": 1,
    "clientes": ["LMDT001", "JP002"]
}
```
*Nota: El campo `clientes` es opcional.*

### 9.3 Añadir Cliente al Grupo
- **URL:** `/api/grupos/{id}/agregar-cliente`
- **Método:** `POST`
- **Body (JSON):**
```json
{
    "id_cliente": "JP002"
}
```

### 9.4 Eliminar Cliente del Grupo
- **URL:** `/api/grupos/{id}/quitar-cliente`
- **Método:** `POST`
- **Body (JSON):**
```json
{
    "id_cliente": "JP002"
}
```

### 9.5 Ver Grupo
- **URL:** `/api/grupos/{id}`
- **Método:** `GET`

### 9.6 Actualizar Grupo
- **URL:** `/api/grupos/{id}`
- **Método:** `PUT/PATCH`

### 9.7 Eliminar Grupo
- **URL:** `/api/grupos/{id}`
- **Método:** `DELETE`


---

## 10. Referencias

Contactos familiares o de amistad del cliente.

### 10.1 Crear Referencia
- **URL:** `/api/referencias`
- **Método:** `POST`
- **Body (JSON):**
```json
{
    "id_cliente": "LMDT001",
    "tipo_referencia": "Familiar",
    "nombre": "Maria Pérez",
    "parentesco": "Hermana",
    "direccion": "Calle Falsa 123",
    "telefono": "5500001111",
    "años_amistad": 30
}
```

### 10.2 Ver Referencia
- **URL:** `/api/referencias/{id}`
- **Método:** `GET`

### 10.3 Actualizar Referencia
- **URL:** `/api/referencias/{id}`
- **Método:** `PUT/PATCH`

### 10.4 Eliminar Referencia
- **URL:** `/api/referencias/{id}`
- **Método:** `DELETE`


---

## 11. Avales

Garantizan el pago del crédito.

### 11.1 Crear Aval
- **URL:** `/api/avales`
- **Método:** `POST`
- **Body (JSON):**
```json
{
    "id_cliente": "LMDT001",
    "nombre": "Pedro Páramo",
    "direccion": "Comala s/n",
    "telefono": "5522223333",
    "parentesco": "Tío"
}
```

### 11.2 Ver Aval
- **URL:** `/api/avales/{id}`
- **Método:** `GET`

### 11.3 Actualizar Aval
- **URL:** `/api/avales/{id}`
- **Método:** `PUT/PATCH`

### 11.4 Eliminar Aval
- **URL:** `/api/avales/{id}`
- **Método:** `DELETE`


---

## 12. Simulador de Créditos (Motor)

Permite calcular las tablas de amortización y las opciones disponibles (tasas y plazos) para un crédito antes de guardarlo.

### 12.1 Simular Crédito Individual
- **URL:** `/api/simular/individual`
- **Método:** `POST`
- **Body (JSON):**
```json
{
    "ciclo": 4,
    "monto_solicitado": 5000.00,
    "buen_historial": true,
    "cantidad_referidos": 2,
    "origen": "nuevo"
}
```

### 12.2 Simular Crédito Grupal
- **URL:** `/api/simular/grupal`
- **Método:** `POST`
- **Body (JSON):**
```json
{
    "ciclo": 0,
    "monto_total_grupo": 10000.00,
    "cantidad_integrantes": 5,
    "origen": "nuevo",
    "cantidad_referidos": 0
}
```

### 12.3 Catálogo de Tasas Individual
- **URL:** `/api/simular/catalogo/individual`
- **Método:** `GET`
Devuelve el catálogo estático de tasas, plazos e intereses configurados para créditos individuales. Además, retorna el arreglo `"origenes"` con los valores permitidos (`["nuevo", "competencia"]`).

### 12.4 Catálogo de Tasas Grupal
- **URL:** `/api/simular/catalogo/grupal`
- **Método:** `GET`
Devuelve el catálogo estático de tasas, plazos e intereses configurados para créditos grupales, junto con los montos mínimos requeridos. También retorna el arreglo `"origenes"` con los valores permitidos (`["nuevo", "competencia", "casa", "referido_socio"]`).

---

## Manejo de Errores Comunes

### Error de Autenticación (401 Unauthorized)
Ocurre cuando las credenciales son incorrectas o el token es inválido/ha expirado.
```json
{
    "error": "No autorizado"
}
```

### Error de Validación (400 Bad Request)
Ocurre cuando faltan datos o no cumplen con las reglas (ej: email duplicado o formato incorrecto).
```json
{
    "id_cliente": ["The id cliente has already been taken."],
    "curp": ["The curp must be 18 characters."]
}
```
