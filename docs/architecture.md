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
| Tests / calidad | Pest (+arch plugin), Larastan (nivel ≥6), Pint | TDD; CI en Fase 2 |

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

## 3. Estilo arquitectónico: hexagonal + DDD, listo para extraer servicios

Un solo despliegue inicial, pero con **bounded contexts aislados** de forma que cada uno pueda convertirse en un servicio independiente sin reescribirlo (ADR-007). Cada contexto es una arquitectura hexagonal: el dominio no conoce el framework; los adaptadores dependen del dominio, nunca al revés.

### 3.1 Bounded contexts

| Contexto | Responsabilidad | Agregados principales |
|---|---|---|
| `Platform` | restaurantes, ajustes, identidad, roles y permisos, auditoría | Restaurant, User, Role |
| `Catalog` | zonas, mesas/QR, categorías, productos, variantes, complementos | Table, Product, Category |
| `Ordering` | sesiones de mesa, participantes, pedidos, estados, llamadas | TableSession, Order, WaiterCall |
| `Billing` | cuenta, división, pagos, pasarelas | Bill, Payment |
| `Inventory` | existencias y movimientos | InventoryItem |
| `Reporting` | modelos de lectura y métricas (solo consulta, CQRS) | — |
| `Printing` | cola y drivers de impresión | PrintJob |

Posible agrupación futura al extraer servicios: Platform+Catalog / Ordering / Billing+Inventory / Reporting.

### 3.2 Estructura de cada contexto

```
app/Contexts/<Contexto>/
├── Domain/          # PHP puro: Entities/Aggregates, ValueObjects, Enums, DomainEvents,
│                    #   Policies de negocio (state machine, pricing), interfaces de Repository
├── Application/     # Casos de uso: Commands + Handlers (escritura), Queries + Handlers (lectura), DTOs, Ports
├── Infrastructure/  # Adaptadores: Eloquent models/repositories, gateways, drivers, mappers, migraciones
└── Interface/       # Http (Controllers, Requests, Resources), Filament, Broadcasting, Console
app/Shared/          # Kernel compartido mínimo: Money, Ulid, Clock, TenantId, Outbox, EventBus
```

Reglas (verificadas por tests de arquitectura, ver §14):
1. `Domain` no depende de `Illuminate\*`, `Eloquent`, `Http` ni `Filament` (solo de `Shared` puro).
2. `Application` depende de `Domain` y define **puertos** (interfaces) que implementa `Infrastructure`.
3. Un contexto **no** importa clases de otro. Se comunican por (a) eventos de integración publicados vía outbox y (b) una API pública del contexto (interfaz en su `Application/Contracts`) que devuelve DTOs.
4. Sin claves foráneas entre contextos (ver §4). Se referencian por ULID.
5. Controladores y recursos Filament solo despachan Commands/Queries.

### 3.3 Principios aplicados

- **SRP:** un Handler por caso de uso; agregados pequeños.
- **OCP/LSP:** variaciones (pasarelas, drivers de impresión, adaptadores de tiempo real) por interfaz + implementaciones intercambiables.
- **ISP:** puertos pequeños (`OrderRepository`, `PaymentGateway`, `Clock`), no interfaces gordas.
- **DIP:** el dominio y la aplicación declaran lo que necesitan; el contenedor de Laravel enlaza adaptadores en un ServiceProvider por contexto.
- **Clean code:** nombres del lenguaje ubicuo del dominio, funciones cortas, sin comentarios que repitan el código, invariantes dentro del agregado.

### 3.4 Agregados, VOs y eventos

- Agregados con raíz explícita y límite transaccional: `Order` (+`OrderItem`), `TableSession` (+participantes), `Payment`, `InventoryItem`. Una transacción modifica un solo agregado; el resto se coordina por eventos.
- Value objects inmutables: `Money`, `TaxRate`, `JoinCode`, `QrToken`, `Ulid`.
- Eventos de dominio emitidos por el agregado; un *dispatcher* tras commit los publica. Los de interés para otros contextos se guardan en la tabla `outbox` en la misma transacción (**transactional outbox**) y un worker los publica en Redis Streams (consumidores idempotentes por `event_id`). Los eventos de tiempo real (`docs/realtime.md`) son un adaptador más sobre estos eventos.
- Los modelos Eloquent viven en `Infrastructure` como persistencia del agregado, con mappers a las entidades de dominio donde la lógica lo justifique. Filament usa los modelos Eloquent de infraestructura vía Queries/Commands, no lógica propia.

### 3.5 Sagas y consistencia

Flujos que cruzan contextos son eventualmente consistentes: `OrderAccepted` (Ordering) → Inventory descuenta stock → si falla, evento compensatorio. El cierre de sesión consulta a Billing por su API pública. Cada paso es idempotente.

## 4. Multi-tenancy (BD compartida)

- Toda tabla de negocio lleva `restaurant_id` (FK, `NOT NULL`) y todo índice de consulta empieza por él.
- Trait `BelongsToRestaurant`: global scope `where restaurant_id = CurrentRestaurant::id()` y relleno automático en `creating`. Si no hay tenant activo y no se pidió saltarlo, el scope **falla cerrado** (excepción), no devuelve todo. Saltarse el scope (`withoutTenancy()`) exige una llamada explícita y comentada.
- `CurrentRestaurant`: servicio con ámbito de petición. Lo rellena un middleware desde (a) usuario autenticado + restaurante seleccionado verificado en `restaurant_user`, o (b) la sesión de mesa del flujo QR. Nunca desde un ID enviado por el cliente sin comprobar pertenencia.
- Integridad: dentro de un contexto, FK compuestas `(restaurant_id, id)` para que la BD impida enlazar recursos de restaurantes distintos. Entre contextos no hay FK (cada contexto tiene su esquema PostgreSQL: `platform`, `catalog`, `ordering`, `billing`, `inventory`, `reporting`); `restaurant_id` es columna indexada y las referencias entre contextos son ULID validados por la API pública del contexto dueño.
- Jobs y listeners: trait `TenantAware` serializa `restaurant_id` y restaura el contexto antes de `handle()`.
- Filament: `->tenant(Restaurant::class)` nativo; cada Resource verifica por Policy y despacha Commands/Queries.
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

Consultas sobre las marcas de tiempo de 7.7. Para no bloquear la operación: índices `(restaurant_id, created_at)`, agregados diarios precalculados por job programado (`report_daily_*`, se justifican en Fase 7). `Reporting` es un modelo de lectura alimentado por eventos de integración (CQRS), nunca consulta tablas de otros contextos, y lecturas sin lock.

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

## 14. Estrategia de pruebas (TDD)

Ciclo obligatorio rojo → verde → refactor: el test falla antes de escribir el código, por Handler y por objeto de dominio.

| Nivel | Qué | Cómo |
|---|---|---|
| Dominio | VOs, agregados, state machine, pricing, reparto de cuenta | Pest unit, sin framework ni BD; milisegundos |
| Aplicación | Handlers con puertos falsos (in-memory) | Pest unit; verifica eventos emitidos |
| Infraestructura | Repositorios, outbox, consumidores idempotentes | Pest feature contra PostgreSQL real en Docker |
| Interfaz / flujos | Endpoints, flujos completos, seguridad y acceso cruzado entre tenants | Pest feature; comprueba también cambios en BD |
| Contrato | Respuestas reales vs `docs/openapi.yaml` | Pest feature |
| Arquitectura | Reglas de §3.2 | `pest-plugin-arch`: `Domain` no usa `Illuminate`/`Http`/`Filament`; un contexto no importa otro |

Cada PR incluye los tests que lo motivan en el mismo cambio; CI exige Pest, Larastan ≥6, Pint y `composer audit`/`npm audit`.

## 15. Asunciones abiertas

- Cliente web QR y apps de personal: una SPA Vue con rutas por rol, o dos entradas Vite. Se decide en Fase 2.
- Horizon frente a `queue:work`: se decide en Fase 2.
- Pasarela de pago: Fase 6.
- Broker del outbox: Redis Streams por defecto; reevaluar RabbitMQ/Kafka si se extraen servicios (ADR cuando ocurra).
