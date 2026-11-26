# API REST v1 (borrador Fase 1)

Base: `/api/v1`. Contrato ejecutable: `docs/openapi.yaml` (OpenAPI 3.1). Este documento y el YAML deben mantenerse sincronizados; en Fase 2 se añaden tests de contrato.

## Convenciones

- JSON en `snake_case`. Fechas ISO 8601 UTC (`2026-01-01T12:00:00Z`).
- Identificadores: ULID públicos (`id` en la API = `public_id`).
- Importes: entero en unidad mínima + `currency` ISO 4217.
- Enums en minúsculas snake_case.
- Recurso: `{ "data": { … } }`. Colección paginada: `{ "data": [ … ], "meta": { "page", "per_page", "total", "last_page" }, "links": { "first", "last", "prev", "next" } }`. `per_page` máx. 100.
- Filtros: `filter[campo]=valor`; orden: `sort=campo` / `sort=-campo`.
- Error único:

```json
{ "error": { "code": "ORDER_PRODUCT_UNAVAILABLE", "message": "…", "details": { "campo": ["…"] } } }
```

  Nunca `exception`, `file`, `trace`.
- `Idempotency-Key` obligatorio en `POST /orders` y `POST /payments`. Misma clave + mismo cuerpo → misma respuesta; misma clave + cuerpo distinto → `409 IDEMPOTENCY_KEY_REUSED`.
- Un recurso de otro restaurante responde `404`, no `403`.

## Gateway y servicios

Un único origen `/api/v1`; el gateway enruta por prefijo: `/auth`, `/admin`, `/restaurants`, `/users` → `platform`; `/zones`, `/tables`, `/categories`, `/products` → `catalog`; `/public`, `/session`, `/sessions`, `/orders`, `/order-items`, `/waiter-calls`, `/kds` → `operations`; `/payments`, `/webhooks`, `/inventory` → `billing`. El contrato público es único (`openapi.yaml`); cada servicio publica su parte.

Administración de plataforma (superadmin): `POST /admin/restaurants` (registra y provisiona; responde `202` con estado `provisioning`), `GET /admin/restaurants/{id}`, `POST /admin/restaurants/{id}/suspend`, `POST /admin/restaurants/{id}/retry-provisioning`.

## Autenticación

| Tipo | Uso | Cabecera |
|---|---|---|
| JWT emitido por `platform` (login con Sanctum, ver ADR-012) | personal y administración | `Authorization: Bearer <jwt>` |
| Credencial de participante | cliente QR | `Authorization: Bearer <participant_token>` (abilities limitadas a su sesión) |

El tenant sale del token; para personal con varios restaurantes, `X-Restaurant` selecciona uno que debe estar en las membresías del token. Nunca basta por sí solo.

Sesión (`platform`): `login` y `refresh` devuelven `{ data: { access_token, token_type, expires_in, refresh_token } }`. El JWT dura 10 min; el refresh token es un token de Sanctum (hasheado en BD, 30 días, un solo uso: cada `refresh` lo rota). `logout` revoca los refresh tokens del usuario y el JWT usado en la llamada (*denylist*); los JWT de otros dispositivos caducan por su TTL. Restablecer la contraseña invalida todos los JWT emitidos antes. Cualquier fallo de credenciales responde el mismo `401 UNAUTHENTICATED`. Recuperación: `forgot-password` responde siempre `202` y envía el enlace solo a cuentas activas; `reset-password` (`email`, `token`, `password` ≥ 12 con `password_confirmation`) responde `204`, cierra todas las sesiones y consume el token (60 min); cualquier fallo es `422 INVALID_RESET_TOKEN`. Ambos comparten el rate limit de `login`. Inician sesión los administradores de plataforma y el personal con membresía activa en un restaurante `active`. Con varios restaurantes, `login` responde `{ data: { restaurants: [{ id, name }] } }` y el cliente repite la petición con `restaurant_id`. El JWT del personal lleva `tenant_id`, el rol y sus permisos; el refresh token recuerda el restaurante y relee rol y permisos en cada `refresh`, así que una membresía desactivada deja de renovarse.

## Endpoints (esqueleto)

| Grupo | Endpoints | Auth / permiso |
|---|---|---|
| Auth | `POST /auth/login`, `POST /auth/refresh`, `POST /auth/logout`, `GET /auth/me`, `POST /auth/forgot-password`, `POST /auth/reset-password` | login/refresh públicos con rate limit (5/min por cuenta+IP); logout/me con JWT |
| Ajustes | `GET /settings`, `PATCH /settings` | El restaurante sale del token. `GET`: cualquier personal del restaurante; `PATCH` (parcial): permiso `restaurant.manage`. Sin fila guardada se devuelven los valores por defecto |
| Personal | `GET /staff`, `POST /staff`, `PATCH /staff/{id}` | Permiso `users.manage` (solo `restaurant_admin`); el restaurante sale del token. `POST` (`name`, `email`, `role`) solo crea una invitación (7 días, sustituye a la pendiente) y envía el enlace; no toca ninguna cuenta. `409 ALREADY_MEMBER` si ya pertenece. La persona se une con `POST /auth/accept-invitation` (`token`, `password`): cuenta nueva = fija su contraseña; cuenta existente = debe dar la suya (`422 INVALID_CREDENTIALS`); enlace caducado, usado o de cuenta desactivada = `422 INVALID_INVITATION`. `PATCH` cambia `role` o `is_active` y revoca sus JWT abiertos; nadie puede modificarse a sí mismo (`422 CANNOT_MODIFY_SELF`) |
| Restaurantes | `GET /restaurants`, `GET/PATCH /restaurants/{id}` | `restaurant.manage` |
| Usuarios | `GET/POST /users`, `GET/PATCH /users/{id}` | `users.manage` |
| Zonas y mesas | `GET/POST /zones`, `PATCH /zones/{id}`, `GET/POST /tables`, `PATCH /tables/{id}`, `POST /tables/{id}/qr/rotate` | Lectura: cualquier personal; escritura: `floor.manage`. `qr_token` solo se devuelve con `floor.manage`. Nombre de zona y etiqueta de mesa únicos (`409`); no se crean mesas en zonas inactivas (`422 INVALID_ZONE`); rotar el QR invalida el anterior |
| Menú | `GET/POST /categories`, `PATCH /categories/{id}`, `GET/POST /products`, `PATCH/DELETE /products/{id}`, `POST/DELETE /products/{id}/image` (multipart `image`: JPEG/PNG/WebP por contenido real, 2 MB, 4000 px), `GET /media/{tenant}/{file}` (pública, inmutable), `POST /products/{id}/variants\|addons`, `PATCH/DELETE /products/{id}/variants\|addons/{id}` | Lectura: cualquier personal; escritura: `menu.manage`; la lectura pública del cliente QR llega en la Fase 4. Precios en unidad mínima (entero); alérgenos: los 14 de la UE; horario `HH:MM` en la zona horaria del restaurante; `is_available`, `is_sold_out` y horario se cambian con `PATCH`; borrado lógico de productos; una sola variante por defecto; `409` por nombres duplicados, `422 INVALID_CATEGORY` |
| Público QR | `GET /public/tables/{qr_token}`, `POST /public/tables/{qr_token}/sessions`, `POST /public/sessions/join` (`qr_token`, `join_code`) | pública con rate limit. Abrir solo si la mesa no tiene sesión (`409 SESSION_EXISTS`); los demás entran con el código de 6 caracteres, válido mientras la sesión esté abierta. La respuesta trae `participant_token` |
| Sesión | `GET /session` | participante |
| Sesiones (personal) | `GET /sessions`, `POST /sessions/{id}/approve`, `POST /sessions/{id}/close` | `orders.view` / `orders.accept`; cerrar una pendiente es rechazarla |
| Pedidos | `POST /orders`, `GET /orders`, `GET /orders/{id}`, `POST /orders/{id}/accept\|reject\|cancel` | participante / `orders.*` |
| Artículos | `POST /order-items/{id}/start\|ready\|deliver\|cancel` | `kds.*` / `orders.deliver` |
| Llamadas | `POST /waiter-calls`, `GET /waiter-calls`, `POST /waiter-calls/{id}/assign\|resolve` | participante / `calls.*` |
| Cuenta y pagos | `GET /session/bill`, `GET /session/bill/split-preview`, `POST /payments`, `GET /payments`, `POST /webhooks/payments/{gateway}` | participante / `payments.*`; webhook firmado |
| Inventario | `/inventory/items`, `/inventory/movements` | `inventory.manage` |
| Informes | `GET /reports/{kind}` | `reports.view` |

El detalle de parámetros, respuestas y permisos de cada endpoint se completa en la fase que lo implementa.

## Catálogo de códigos de error

| Código | HTTP | Significado |
|---|---|---|
| `VALIDATION_FAILED` | 422 | Entrada inválida (`details` por campo) |
| `UNAUTHENTICATED` | 401 | Credencial ausente o inválida |
| `FORBIDDEN` | 403 | Sin permiso para la operación |
| `NOT_FOUND` | 404 | Recurso inexistente o de otro restaurante |
| `RATE_LIMITED` | 429 | Demasiadas peticiones |
| `IDEMPOTENCY_KEY_REQUIRED` | 400 | Falta `Idempotency-Key` |
| `IDEMPOTENCY_KEY_REUSED` | 409 | Misma clave con cuerpo distinto |
| `CONFLICT_VERSION` | 409 | Recurso modificado por otra operación |
| `INVALID_STATE_TRANSITION` | 409 | Transición no permitida |
| `SLUG_TAKEN` | 409 | El slug del restaurante ya existe |
| `ORDER_PRODUCT_UNAVAILABLE` | 422 | Producto agotado o fuera de horario |
| `SESSION_EXISTS` | 409 | La mesa ya tiene una sesión: pedir su código |
| `SESSION_CLOSED` | 409 | Sesión cerrada o expirada |
| `SESSION_PENDING_APPROVAL` | 409 | Sesión a la espera del camarero |
| `JOIN_CODE_INVALID` | 422 | Código inexistente o de una sesión terminada |
| `JOIN_CODE_LOCKED` | 429 | Demasiados intentos |
| `PAYMENT_AMOUNT_INVALID` | 422 | Importe fuera del saldo pendiente |
| `PAYMENT_SIGNATURE_INVALID` | 400 | Firma de webhook inválida |
| `SESSION_BALANCE_PENDING` | 409 | Saldo pendiente impide cerrar |
