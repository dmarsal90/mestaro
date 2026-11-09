# Arquitectura de Mestaro

Estado: Fase 1 (borrador para aprobación). Fuente de verdad funcional: `docs/spec.md`. Las desviaciones de la spec están en `docs/decisions.md`.

## 1. Resumen

- **Servicios independientes**, cada uno desplegable y escalable por separado, con arquitectura **hexagonal + DDD** por dentro.
- **Multi-tenant con una base de datos por restaurante.** Un administrador de plataforma registra el negocio y el sistema crea sus bases de datos.
- **TDD** obligatorio; tests de arquitectura que imponen las fronteras.
- Todo se ejecuta en **Docker**.

## 2. Stack

| Pieza | Versión | Nota |
|---|---|---|
| PHP | 8.4 (mín. 8.3) | Laravel 13 exige ^8.3 |
| Laravel | 13 | Desvío de la spec (decía 12). ADR-001 |
| Filament | 5.x | ADR-002; alcance en ADR-013 |
| PostgreSQL | 17 | ADR-003 |
| Redis | 8.x | Caché, colas, rate limiting, Streams (bus de eventos) |
| Tiempo real | Reverb + Echo | Alojado en `operations` |
| Frontend | Vue 3 + Vite + TS + Pinia + Tailwind | Apps cliente QR y personal |
| Calidad | Pest (+arch plugin), Larastan ≥6, Pint | CI en Fase 2 |

Versiones exactas de dependencias: se fijan y verifican en la Fase 2 antes de instalar.

## 3. Servicios

| Servicio | Responsabilidad | Datos |
|---|---|---|
| `platform` | Registro y provisión de restaurantes (tenants), planes/límites futuros, usuarios, roles y permisos, emisión de tokens, auditoría de plataforma, panel Filament de superadmin | BD **central** (`mestaro_platform`) |
| `catalog` | Zonas, mesas y QR, categorías, productos, variantes, complementos, disponibilidad | BD **por tenant** |
| `operations` | Sesiones de mesa, participantes, pedidos y estados, llamadas al camarero, KDS, tiempo real (Reverb), impresión | BD **por tenant** |
| `billing` | Cuenta, división, pagos, pasarelas, inventario | BD **por tenant** |
| `reporting` (Fase 7) | Modelos de lectura y métricas (CQRS) alimentados por eventos | BD por tenant, solo lectura/agregados |
| `gateway` | nginx: enruta `/api/v1/...` al servicio dueño, TLS, límites de tamaño y cabeceras de seguridad | — |
| `apps/web` | SPA Vue (cliente QR, KDS, camarero, POS) | — |

Reglas entre servicios:
1. Ningún servicio lee la BD de otro. Se comunican por (a) **API interna** síncrona (solo consultas necesarias y con timeout/circuit breaker) y (b) **eventos de integración** asíncronos.
2. Los eventos se publican con *transactional outbox* y se transportan por **Redis Streams**; consumidores idempotentes por `event_id`. Contratos en `docs/events.md` (Fase 2).
3. Cada servicio guarda las **proyecciones** que necesita de otros (p. ej. `operations` mantiene una copia local de productos y mesas actualizada por eventos de `catalog`, para validar pedidos sin llamadas síncronas).
4. Las referencias entre servicios son ULID públicos, sin FK.
5. Código compartido mínimo en `packages/shared-kernel` (Composer path package): `Money`, `Ulid`, `Clock`, envoltorio de eventos, outbox, resolución de tenant, verificación de JWT. Versionado; no contiene reglas de negocio.

## 4. Estructura del repositorio

```
services/
  platform/ catalog/ operations/ billing/     # cada uno, una app Laravel 13
    app/
      Domain/          # PHP puro: aggregates, value objects, enums, eventos de dominio,
      │                #   state machines, políticas de negocio, interfaces de repositorio
      Application/     # Commands/Queries + Handlers, DTOs, puertos
      Infrastructure/  # Eloquent, repositorios, gateways, outbox, adaptadores externos
      Interface/       # Http (controllers, requests, resources), Filament, Console, Broadcasting
    tests/
packages/shared-kernel/
apps/web/                                      # Vue; el tiempo real solo vía RealtimeClient
gateway/                                       # nginx
docker/  docs/  resources/brand/
```

Reglas de dependencia (impuestas por tests de arquitectura): `Domain` no usa `Illuminate\*`, `Http` ni `Filament`; `Application` solo depende de `Domain` y de sus puertos; `Infrastructure` e `Interface` dependen hacia dentro; un servicio no importa código de otro (solo `shared-kernel`).

Principios: SRP (un Handler por caso de uso, agregados pequeños), OCP/LSP (pasarelas, drivers de impresión y transporte en tiempo real intercambiables por interfaz), ISP (puertos pequeños), DIP (cada servicio enlaza sus adaptadores en su ServiceProvider). Clean code: lenguaje ubicuo, funciones cortas, invariantes dentro del agregado.

## 5. Multi-tenancy: una BD por restaurante

### 5.1 Datos
- `platform` guarda el **registro de tenants** (id ULID, slug, estado, config, y por servicio: nombre de BD y credenciales cifradas) además de usuarios, roles y membresías.
- Cada servicio por tenant (`catalog`, `operations`, `billing`, `reporting`) tiene **una BD por restaurante**: `mestaro_<tenant>_<servicio>`, con un rol de BD propio con permisos solo sobre esa BD. Esto aísla datos aunque falle una consulta o un `where`.
- Las tablas de tenant **no necesitan `restaurant_id` para aislar** (la BD ya es el límite). Los eventos, logs y tokens sí llevan `tenant_id`.
- Cada servicio mantiene una tabla local `tenants` (réplica del registro, con credenciales cifradas) para conectar sin llamar a `platform` en cada petición.

### 5.2 Resolución del tenant
1. El token (JWT del personal, o credencial de participante) lleva `tenant_id`; el middleware lo valida y lo fija en `CurrentTenant` (ámbito de petición).
2. Un middleware cambia la conexión por defecto a la BD del tenant. Si no hay tenant y la ruta lo requiere, **falla cerrado**, nunca usa una conexión por defecto.
3. El `tenant_id` nunca se acepta del cuerpo ni de la URL como autorización; solo del token verificado (y para personal con varios restaurantes, `X-Restaurant` debe estar en la membresía del token).
4. Jobs, listeners y consumidores de Streams llevan `tenant_id` en el mensaje y restauran el contexto antes de ejecutarse.
5. Archivos: ruta `tenants/<tenant_id>/…` en disco privado, descarga por controlador con Policy.
6. Pooling: PgBouncer en modo transacción delante de Postgres para no agotar conexiones con muchas BD.

### 5.3 Provisión (alta de un restaurante)
1. Superadmin crea el restaurante en el panel de `platform` → estado `provisioning`.
2. `platform` publica `TenantRegistered`.
3. Cada servicio por tenant consume el evento: crea rol y BD, ejecuta las migraciones de tenant y los datos base, y publica `TenantProvisioned`. Idempotente y reintentable.
4. Cuando todos responden, `platform` pasa el tenant a `active`; si alguno falla, a `provisioning_failed` con reintento manual. Suspender/eliminar sigue el flujo inverso (solo desactivar por defecto; el borrado de datos requiere acción explícita registrada).
5. Migraciones: un comando recorre todas las BD de tenants de un servicio (en paralelo y con registro de fallos); las migraciones deben ser compatibles hacia atrás durante el despliegue.

## 6. Identidad y autorización

- `platform` autentica al personal (Sanctum para el login) y emite **JWT de acceso de vida corta** (firma asimétrica; las claves públicas se publican por JWKS interno). Los demás servicios verifican localmente, sin llamar a `platform`. Claims: usuario, `tenant_id`, rol, permisos. Revocación: refresh tokens revocables + lista de revocación en Redis.
- El **cliente QR** no tiene cuenta: `operations` emite una credencial de participante opaca ligada a la sesión (hash en BD).
- Roles y permisos viven en `platform`; los permisos efectivos viajan en el token. Cada servicio los comprueba con Policies. Permisos de plataforma separados de los de restaurante.
- Argon2id para contraseñas; MFA preparada para cuentas administrativas.

## 7. Flujo QR y sesiones (`operations`)

1. `GET /api/v1/public/tables/{qr_token}`: `operations` resuelve el tenant a partir del token (índice central de `qr_token` → tenant, mantenido por eventos de `catalog`) y devuelve la mesa; no autoriza nada.
2. `POST …/sessions` crea o recupera la sesión y emite credencial de participante. Opcionalmente queda `pending_approval` hasta que el camarero confirme.
3. Otros clientes entran con `join_code` (6 caracteres sin ambiguos, CSPRNG, único entre sesiones abiertas), que devuelve un token de un solo uso canjeable por credencial. El `join_code` nunca autoriza peticiones.
4. Rate limit por IP y mesa (5 fallos/10 min) en Redis.

## 8. Pedidos: máquina de estados

En `operations`, definida en un único lugar del dominio, con tests unitarios.
- `orders.status`: `pending → accepted | rejected`; `pending|accepted → cancelled`.
- `order_items.status`: `queued → preparing → ready → delivered`; `queued|preparing → cancelled`.
- El progreso del pedido se calcula, no se almacena.
- Concurrencia: bloqueo de fila en la transición + `version` expuesta en API y eventos.
- Cada transición escribe `order_status_history` y las marcas de tiempo/usuario de spec 7.7.
- `Idempotency-Key` obligatorio en creación de pedidos y pagos.
- Validación de precios y disponibilidad contra la proyección local del catálogo; el precio queda copiado en el artículo.

## 9. Dinero y pagos (`billing`)

- Importes `bigint` en unidad mínima + ISO 4217; `Money` en shared-kernel. Impuestos en puntos básicos. Un único servicio de dominio calcula impuestos, descuentos y totales; los pedidos guardan los importes calculados y `billing` los usa.
- `billing` obtiene la cuenta de una sesión desde proyecciones de eventos de `operations` y confirma el estado final con una consulta síncrona a `operations` en el momento de cobrar.
- División de cuenta con reparto determinista de céntimos.
- `PaymentGateway` (puerto): crear intención, confirmar, reembolsar, verificar webhook. Webhook: firma → `external_event_id` único → procesamiento idempotente. Nada de datos de tarjeta. Pasarela concreta en Fase 6.
- Cierre de sesión: `operations` consulta el saldo a `billing`; con saldo pendiente solo se cierra con acción autorizada y registrada.

## 10. Tiempo real

Alojado en `operations` (Reverb). Los eventos de otros servicios (`PaymentRegistered`, `ProductAvailabilityChanged`…) llegan por Streams y `operations` los retransmite. Contrato en `docs/realtime.md`. Canales privados con nombres no predecibles y autorización por token; el frontend solo conoce `RealtimeClient`.

## 11. Impresión (`operations`)

Puerto `PrintDriver` + destinos por restaurante; tickets como Jobs; ESC/POS como driver futuro.

## 12. Informes (`reporting`, Fase 7)

Read models alimentados por eventos (CQRS); agregados precalculados por job; nunca consulta a las BD de otros servicios.

## 13. Interfaces de administración

- `platform`: panel **Filament** de superadmin (alta/estado de restaurantes, planes, usuarios).
- Panel del restaurante: ver ADR-013 (decisión pendiente de confirmar).

## 14. Estrategia de pruebas (TDD)

Ciclo rojo → verde → refactor, test primero por Handler y por objeto de dominio.

| Nivel | Qué | Cómo |
|---|---|---|
| Dominio | VOs, agregados, state machine, pricing, reparto | Pest unit, sin framework ni BD |
| Aplicación | Handlers con puertos in-memory | Pest unit; verifica eventos |
| Infraestructura | Repositorios, outbox, consumidores, provisión de BD | Pest feature con PostgreSQL real en Docker |
| Interfaz / seguridad | Endpoints, flujos, acceso cruzado entre tenants (token de A no puede alcanzar BD de B) | Pest feature; comprueba también la BD |
| Contrato | Respuestas reales vs OpenAPI y esquemas de eventos | Pest |
| Arquitectura | Reglas de §4 | `pest-plugin-arch` |
| Extremo a extremo | Flujo QR → pedido → cobro entre servicios | Compose de pruebas en CI |

CI: Pest, Larastan ≥6, Pint, `composer audit` y `npm audit` por servicio.

## 15. Docker

Todo en contenedores; sin PHP en el host. Reutiliza imágenes ya disponibles: `nginx:1.31.8-alpine`, `postgres:17.11-alpine`, `redis:8.10.x-alpine`, `axllent/mailpit`, y una imagen PHP propia multi-target (`dev`/`prod`) según el patrón de Matchly.

Servicios en compose: `gateway`, `platform`, `catalog`, `operations`, `billing` (cada uno con sus procesos `app`, `queue`, `scheduler` y, en operations, `reverb`), `postgres` (+ `pgbouncer`), `redis`, `vite`, `mailpit`. Postgres y Redis en red interna, sin puertos públicos. Un rol de BD por servicio; sin secretos en imágenes ni repo (`.env.example` vacío). Se implementa en la Fase 2.

## 16. Riesgos de seguridad principales

| Riesgo | Mitigación | Test |
|---|---|---|
| Fuga entre restaurantes | BD y rol propios por tenant; tenant solo del token; fallo cerrado sin tenant | Token de A contra recursos de B en cada servicio |
| Provisión incompleta o fallida | Estados explícitos, reintentos idempotentes | Fallo a mitad de provisión |
| Credenciales de BD de tenants | Cifradas en registro, rol mínimo, rotación | — |
| JWT robado/falsificado | Firma asimétrica, vida corta, revocación, validación de `iss`/`aud` | Token manipulado y caducado |
| QR / join_code abusados | Tokens opacos, rate limit, aprobación opcional | Fuerza bruta, reutilización |
| Manipulación de precios | Cálculo solo en backend, precio copiado | Payload con importes falsos |
| Doble pago / webhook repetido | Idempotency-Key, `event_id` único, firmas | Duplicados |
| Eventos duplicados o fuera de orden | Idempotencia por `event_id`, `version` | Reentrega |
| Canales WS abiertos | Autorización por canal y tenant | Suscripción ajena |
| Subida de archivos | MIME real, nombres internos, disco privado | Path traversal |
| Dependencias vulnerables | audits en CI | CI |

## 17. Puntos abiertos

- ADR-013: alcance de Filament (panel del restaurante).
- Horizon o `queue:work`; SPA única o varias entradas Vite (Fase 2).
- Broker: Redis Streams por defecto; reevaluar RabbitMQ/Kafka si hace falta.
- Pasarela de pago (Fase 6).
