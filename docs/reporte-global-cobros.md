# Reporte Global Cobros

La pantalla `/dashboard/reportes/global-cobros` sustituye Global Mensual y Gestor Mensual. Las rutas de esas pantallas redirigen a la nueva; sus endpoints siguen disponibles para compatibilidad. El reporte Diario conserva sus reglas.

## Consulta

`GET /api/reportes/global-cobros` requiere autenticación y acepta:

- `periodo`: obligatorio, `semana` o `mes`.
- `fecha`: fecha base `YYYY-MM-DD`; si se omite, usa el día actual en la zona horaria de Laravel.
- `id_asesor`: identificador opcional existente. Para roles de campo, el servidor siempre impone el asesor vinculado al usuario; si no existe esa vinculación, responde 403.

La semana comprende lunes a viernes, recortada al mes de la fecha seleccionada; si el mes empieza en fin de semana y se selecciona ese día, se muestra la primera semana laboral del mes; el mes agrupa semanas de lunes a viernes recortadas a sus límites. Se excluyen sábados y domingos de ambos períodos. Los parámetros inválidos reciben HTTP 422.

La respuesta contiene `periodo`, `fecha_base`, `inicio`, `fin`, `id_asesor`, `totales`, `dias` y `semanas`. En consulta semanal se llena `dias`; en mensual se llena `semanas` y `dias` queda vacío. Cada semana tiene `numero` (consecutivo dentro del mes), `inicio`, `fin`, `totales` y `por_asesor`. Cada día incluye `fecha`, `totales` y `por_asesor`. Cada asesor incluye `id_asesor`, `nombre_asesor`, `abonos`, `multas` y `total_cobrado`. Los totales usan estas mismas tres métricas.

## Cálculo y presentación

Se acumulan por separado los pagos de tipo Abono y Multa por su fecha, incluyendo créditos de cualquier estado. `total_cobrado` equivale únicamente a los abonos; las multas pertenecen al gestor y no se suman al total cobrado en filas, subtotales ni totales del período. El asesor corresponde a la asignación actual del crédito; sin asociación se presenta como Sin asesor en la consulta global. Los pagos grupales se cuentan una sola vez, sin unir sus asignaciones a integrantes. No se incluyen colocación, ahorros ni entregas a caja.

La consulta semanal muestra días; la mensual muestra semanas, con una fila acumulada por asesor y subtotal en cada bloque. Los bloques sin movimientos conservan totales en cero. Septiembre de 2026 muestra las semanas 1–4, 7–11, 14–18, 21–25 y 28–30. El total del período concilia con sus subtotales mediante acumulación en centavos.

## Verificación

Desde `api`: `php artisan test --filter=GlobalCobrosReportTest` o `php artisan test` para regresiones.

Desde `frontend`: `npm run build` y `npx eslint app/dashboard/reportes/global-cobros/page.tsx app/dashboard/reportes/semanal/page.tsx app/dashboard/reportes/gestor-mensual/page.tsx`.

La revisión visual en navegador quedó pendiente: Computer Use no obtuvo autorización para acceder a Google Chrome.
