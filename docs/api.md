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

Un único origen `/api/v1`; el gateway enruta por prefijo: `/auth`, `/admin`, `/restaurants`, `/users` → `platform`; `/zones`, `/tables`, `/categories`, `/products` → `catalog`; `/public`, `/session`, `/orders`, `/order-items`, `/waiter-calls`, `/kds` → `operations`; `/payments`, `/webhooks`, `/inventory` → `billing`. El contrato público es único (`openapi.yaml`); cada servicio publica su parte.

Administración de plataforma (superadmin): `POST /admin/restaurants` (registra y provisiona; responde `202` con estado `provisioning`), `GET /admin/restaurants/{id}`, `POST /admin/restaurants/{id}/suspend`, `POST /admin/restaurants/{id}/retry-provisioning`.

## Autenticación

| Tipo | Uso | Cabecera |
|---|---|---|
| JWT emitido por `platform` (login con Sanctum, ver ADR-012) | personal y administración | `Authorization: Bearer <jwt>` |
| Credencial de participante | cliente QR | `Authorization: Bearer <participant_token>` (abilities limitadas a su sesión) |

El tenant sale del token; para personal con varios restaurantes, `X-Restaurant` selecciona uno que debe estar en las membresías del token. Nunca basta por sí solo.

Sesión (`platform`): `login` y `refresh` devuelven `{ data: { access_token, token_type, expires_in, refresh_token } }`. El JWT dura 10 min; el refresh token es un token de Sanctum (hasheado en BD, 30 días, un solo uso: cada `refresh` lo rota). `logout` revoca los refresh tokens del usuario; el JWT vigente caduca por su TTL. Cualquier fallo de credenciales responde el mismo `401 UNAUTHENTICATED`. Por ahora solo inician sesión los administradores de plataforma; el personal de restaurante llega con membresías y roles.

## Endpoints (esqueleto)

| Grupo | Endpoints | Auth / permiso |
|---|---|---|
| Auth | `POST /auth/login`, `POST /auth/refresh`, `POST /auth/logout`, `GET /auth/me` | login/refresh públicos con rate limit (5/min por cuenta+IP); logout/me con JWT |
| Restaurantes | `GET /restaurants`, `GET/PATCH /restaurants/{id}` | `restaurant.manage` |
| Usuarios | `GET/POST /users`, `GET/PATCH /users/{id}` | `users.manage` |
| Zonas y mesas | `/zones`, `/tables`, `POST /tables/{id}/qr/rotate` | `floor.manage` |
| Menú | `/categories`, `/products`, `/products/{id}/variants`, `/products/{id}/addons` | `menu.manage`; lectura pública vía sesión |
| Público QR | `GET /public/tables/{qr_token}`, `POST /public/tables/{qr_token}/sessions`, `POST /public/sessions/join` | pública con rate limit |
| Sesión | `GET /session`, `POST /session/close` | participante / personal |
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
| `SESSION_CLOSED` | 409 | Sesión cerrada o expirada |
| `SESSION_PENDING_APPROVAL` | 409 | Sesión a la espera del camarero |
| `JOIN_CODE_INVALID` | 422 | Código inexistente, caducado o usado |
| `JOIN_CODE_LOCKED` | 429 | Demasiados intentos |
| `PAYMENT_AMOUNT_INVALID` | 422 | Importe fuera del saldo pendiente |
| `PAYMENT_SIGNATURE_INVALID` | 400 | Firma de webhook inválida |
| `SESSION_BALANCE_PENDING` | 409 | Saldo pendiente impide cerrar |
