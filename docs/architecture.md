# Arquitectura de Mestaro

Estado: Fase 1 (borrador para aprobación). Fuente de verdad funcional: `docs/spec.md`.

## 1. Stack confirmado

| Pieza | Versión | Nota |
|---|---|---|
| PHP | 8.4 (mínimo 8.3) | Laravel 13 exige ^8.3. Imagen dev ya existente usa 8.4.x |
| Laravel | 13 | Desvío respecto a la spec (decía 12). Ver ADR-001 |
| Filament | 5.x | Compatible con Laravel 11/12/13. Ver ADR-002 |
| Base de datos | PostgreSQL 17 | Ver ADR-003 |
| Redis | 8.x | Caché, colas, rate limiting, Reverb escalado |
| Tiempo real | Reverb + Echo | Detrás de `RealtimeClient` en el frontend |
| Frontend | Vue 3 + Vite + TS + Pinia + Tailwind | SPA para cliente QR, KDS, camarero |
| Tests / calidad | Pest, Larastan (nivel ≥6), Pint | CI en Fase 2 |

Versiones exactas de dependencias: se fijan y verifican en la Fase 2 contra Packagist antes de instalar.

## 2. Entorno Docker

Todo el desarrollo y los tests se ejecutan dentro de contenedores; no se instala PHP/Composer en el host. Se reutilizan las imágenes ya disponibles en la máquina de desarrollo:

| Servicio | Imagen | Función |
|---|---|---|
| `app` | imagen propia `php:8.4-fpm-bookworm` (+ pdo_pgsql, redis, intl, zip, opcache, pcntl, bcmath, gd, exif) | PHP-FPM; mismo patrón multi-target (`dev`/`prod`) que el proyecto Matchly |
| `web` | `nginx:1.31.8-alpine` | Proxy a PHP-FPM y estáticos |
| `postgres` | `postgres:17.11-alpine` | BD; volumen propio, red interna |
| `redis` | `redis:8.10.x-alpine` | Persistencia AOF, sin puerto público |
| `queue` | misma imagen `app` | `queue:work` (o Horizon, a decidir en Fase 2) |
| `scheduler` | misma imagen `app` | `schedule:work` |
| `reverb` | misma imagen `app` | Servidor WebSocket |
| `vite` | misma imagen `app` (dev) | HMR |
| `mailpit` | `axllent/mailpit` | Correo en desarrollo |

Reglas: una sola imagen para todos los procesos PHP; usuario sin privilegios; sin secretos en la imagen ni en el repositorio (`.env.example` con valores vacíos); red interna para Postgres/Redis (solo `web` y `reverb` expuestos); puertos del host distintos de los de otros proyectos locales. Los Dockerfile, compose y comandos (`make`) se implementan en la **Fase 2**.

## 3. Capas y estructura de carpetas

Monolito modular. Cada módulo agrupa su dominio; la presentación (HTTP, Filament, broadcasting) queda fuera del dominio.

```
app/
├── Domain/                 # Dominio + aplicación, por módulo
│   ├── Restaurant/         #   Models/ Enums/ Actions/ DTOs/ Policies/ Events/ Listeners/ Jobs/
│   ├── Identity/           # usuarios, roles, permisos, pertenencia a restaurantes
│   ├── Floor/              # zonas, mesas, QR
│   ├── Menu/               # categorías, productos, variantes, complementos, destinos
│   ├── Ordering/           # sesiones de mesa, participantes, pedidos, estados
│   ├── Service/            # llamadas al camarero, solicitudes de cuenta
│   ├── Inventory/
│   ├── Billing/            # cuentas, división, pagos, pasarelas
│   ├── Reporting/          # métricas y agregados
│   ├── Printing/           # contrato de impresión (ESC/POS futuro)
│   └── Audit/
├── Support/                # Tenancy (CurrentRestaurant, BelongsToRestaurant), Money, Idempotency
├── Http/                   # Presentación API: Controllers/Api/V1, Requests, Resources, Middleware
├── Filament/               # Panel administrativo (multi-tenant nativo)
├── Broadcasting/           # Canales y eventos (broadcastAs estable)
└── Providers/
resources/js/               # Vue: apps cliente-QR, staff (KDS, camarero, POS)
  └── realtime/             # RealtimeClient (interfaz) + EchoAdapter
resources/brand/            # Logo y favicon (pendientes de aportar)
docs/
```

Reglas de dependencia: `Http`/`Filament`/`Broadcasting` → `Domain` (nunca al revés); un módulo usa a otro solo a través de sus Actions, Enums o eventos públicos; la lógica de precios, impuestos y totales vive en un único servicio (`Domain/Billing` o `Support/Money`) usado por pedidos y cuenta.

Convenciones: controladores delgados → Action de un solo propósito; Form Request por endpoint de escritura; Policy por modelo; Enums respaldados por string en snake_case; DTOs en la frontera Action; `DB::transaction` en operaciones críticas; eventos `ShouldDispatchAfterCommit`.

## 4. Multi-tenancy (BD compartida)

- Toda tabla de negocio lleva `restaurant_id` (FK, `NOT NULL`) y todo índice de consulta empieza por él.
- Trait `BelongsToRestaurant`: global scope `where restaurant_id = CurrentRestaurant::id()` y relleno automático en `creating`. Si no hay tenant activo y no se pidió saltarlo, el scope **falla cerrado** (excepción), no devuelve todo. Saltarse el scope (`withoutTenancy()`) exige una llamada explícita y comentada.
- `CurrentRestaurant`: servicio con ámbito de petición. Lo rellena un middleware desde (a) usuario autenticado + restaurante seleccionado verificado en `restaurant_user`, o (b) la sesión de mesa del flujo QR. Nunca desde un ID enviado por el cliente sin comprobar pertenencia.
- Integridad cruzada: FK compuestas `(restaurant_id, id)` en relaciones entre tablas de negocio para que la propia BD impida enlazar recursos de restaurantes distintos.
- Jobs y listeners: trait `TenantAware` serializa `restaurant_id` y restaura el contexto antes de `handle()`.
- Filament: `->tenant(Restaurant::class)` nativo; cada Resource verifica por Policy.
- Rutas con binding: los modelos usan el scope también en route model binding, de modo que un ID ajeno devuelve 404 (no 403, para no confirmar existencia).
- Archivos y exportaciones: ruta `restaurants/{restaurant_id}/…` en disco privado, descarga mediante controlador con Policy.
- Futuro SaaS: tabla `plans/subscriptions` no se crea ahora; `Restaurant` expone un punto único (`Restaurant::limits()`) para consultar límites.

## 5. Identidad y autorización

- Dos mecanismos de credencial: **Sanctum** (personal access tokens/SPA) para personal y administración; **credencial de participante** (token opaco ligado a `table_session_participants`) para clientes QR, con abilities restringidas.
- Roles y permisos propios (tablas `roles`, `permissions`, pivotes) con `restaurant_id` nullable (NULL = rol de sistema) para permitir roles personalizados por restaurante a futuro. Los permisos globales de plataforma están separados de los de restaurante.
- Toda comprobación pasa por Policies/Gates que consultan permiso **y** pertenencia al tenant.
- Contraseñas: Argon2id. MFA: columnas y puntos de extensión preparados, activación posterior.

## 6. Flujo QR y sesiones

1. `GET /api/v1/public/tables/{qr_token}` → identifica la mesa (sin autorizar nada). `qr_token`: 32 bytes aleatorios, base64url, único; rotable.
2. `POST …/sessions` → crea o recupera la sesión abierta y emite una credencial de participante. Si el restaurante exige confirmación del camarero, la sesión queda `pending_approval`.
3. Otros clientes se unen con `join_code` (6 caracteres, alfabeto sin ambiguos, CSPRNG, único entre sesiones abiertas) → devuelven un token de participante de un solo uso que se canjea por la credencial. El `join_code` nunca autoriza peticiones.
4. Rate limiting por IP y por mesa (5 fallos/10 min) con bloqueo temporal, en Redis.

## 7. Pedidos: máquina de estados

Definida en un único lugar (`Domain/Ordering/StateMachine`), con tablas de transiciones por enum y tests unitarios:

- `orders.status`: `pending → accepted | rejected`; `pending|accepted → cancelled` (motivo + permiso).
- `order_items.status`: `queued → preparing → ready → delivered`; `queued|preparing → cancelled`.
- Progreso del pedido: calculado desde los artículos, no almacenado.
- Concurrencia: `lockForUpdate` sobre el pedido/artículo en la transición y columna `version` (optimistic) expuesta en la API y en los eventos.
- Cada transición escribe `order_status_history` y las marcas de tiempo/usuario de 7.7 en la misma transacción.
- Idempotencia: `Idempotency-Key` en creación de pedidos y pagos; tabla `idempotency_keys` con hash del cuerpo (misma clave + cuerpo distinto → 422/409).

## 8. Dinero

- Importes como `bigint` en unidad mínima + código ISO 4217 por restaurante; value object `Money`. Sin floats.
- Impuestos (porcentaje en basis points, incluido/excluido según config), descuentos y totales calculados por un único servicio. El precio aplicado y el tipo impositivo se copian al `order_item` en el momento de crear el pedido.
- División de cuenta: reparto determinista del resto (los céntimos sobrantes se asignan por orden estable) para que la suma sea exacta.

## 9. Pagos

Interfaz `PaymentGateway` (crear intención, confirmar, reembolsar, verificar webhook). El dominio guarda solo referencias de la pasarela (tokens/ids), nunca datos de tarjeta. Webhook: verificación de firma → registro del `event_id` externo único (descarta duplicados) → procesamiento idempotente en cola. Pasarela concreta: decisión en Fase 6 (ADR pendiente).

## 10. Tiempo real

Contrato en `docs/realtime.md`. Eventos con `broadcastAs`, `ShouldDispatchAfterCommit`, payload con `event_id`, `occurred_at`, `restaurant_id`, `version`. Canales privados `private-restaurant.{ulid}.…` autorizados contra membresía y permiso. El frontend solo conoce `RealtimeClient`; al reconectar recarga estado por API.

## 11. Impresión

Interfaz `PrintDriver` + tabla de destinos de impresión por restaurante; los tickets se encolan como Jobs. ESC/POS es un driver futuro; el dominio no conoce fabricantes.

## 12. Informes

Consultas sobre las marcas de tiempo de 7.7. Para no bloquear la operación: índices `(restaurant_id, created_at)`, agregados diarios precalculados por job programado (`report_daily_*`, se justifican en Fase 7), y lecturas sin lock.

## 13. Riesgos de seguridad principales

| Riesgo | Mitigación de diseño | Test requerido |
|---|---|---|
| Fuga entre restaurantes (BOLA/IDOR) | Scope fail-closed + FK compuestas + 404 en binding | Acceso cruzado por cada recurso |
| QR/join_code abusado | Tokens opacos, rate limit, aprobación opcional, expiración | Fuerza bruta, reutilización |
| Manipulación de precios | Cálculo solo en backend, precio copiado al pedido | Payload con importes falsos |
| Doble pago / webhooks repetidos | Idempotency-Key, `event_id` único, firmas | Duplicados y reintentos |
| Canales WS abiertos | Autorización por canal, nombres con ULID | Suscripción a canal ajeno |
| Subida de archivos | MIME real, nombres internos, disco privado | Path traversal, descarga ajena |
| Job sin contexto de tenant | `TenantAware` | Job ejecutado sin contexto falla |
| Secretos en repo/imagen | `.env.example` vacío, secretos por entorno | CI: escaneo de secretos |
| Dependencias vulnerables | `composer audit`, `npm audit` en CI | CI |
| Escalada de privilegios | Policies + permisos granulares, separación global/restaurante | Rol bajo en endpoints admin |

## 14. Asunciones abiertas

- Cliente web QR y apps de personal: una SPA Vue con rutas por rol, o dos entradas Vite. Se decide en Fase 2.
- Horizon frente a `queue:work`: se decide en Fase 2.
- Pasarela de pago: Fase 6.
