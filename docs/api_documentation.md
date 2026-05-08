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

## Manejo de Errores Comunes

### Error de Autenticación (401 Unauthorized)
Ocurre cuando las credenciales son incorrectas o el token es inválido/ha expirado.
```json
{
    "error": "No autorizado"
}
```

### Error de Validación (400 Bad Request)
Ocurre cuando faltan datos o no cumplen con las reglas (ej: email duplicado).
```json
{
    "email": ["The email has already been taken."],
    "password": ["The password field is required."]
}
```
