# Decisiones técnicas (ADR)

Formato: contexto, opciones, decisión, consecuencias.

## ADR-001 — Laravel 13 en lugar de Laravel 12
- **Contexto:** la spec (§3) indica Laravel 12. A fecha 2026-11-08 la rama estable es Laravel 13 (v13.40), que exige PHP ^8.3. El desarrollador pidió expresamente Laravel 13.
- **Opciones:** Laravel 12 (v12.71, soportado); Laravel 13.
- **Decisión:** Laravel 13. Desvío consciente de la spec, ordenado por el desarrollador.
- **Consecuencias:** Filament 5, Reverb 1.12 y Sanctum soportan ^13. Verificar en Fase 2 el resto de dependencias antes de instalar; documentar cualquier ajuste. La réplica Java no se ve afectada (los contratos son independientes del framework).

## ADR-002 — Filament 5.x
- **Contexto:** la spec pide comprobar 3.x o 4.x. Existe también 5.x (v5.11.2, requiere Laravel ^11.28|^12|^13, PHP ^8.2).
- **Opciones:** 3.3 (mantenimiento), 4.16, 5.11.
- **Decisión:** Filament 5, indicado por el desarrollador y necesario para ser compatible con Laravel 13 sin forzar versiones.
- **Consecuencias:** consultar la guía de actualización y la documentación vigente de v5 antes de escribir cada recurso (no fiarse de ejemplos de v3). Multi-tenancy nativa de Filament para el panel.

## ADR-003 — PostgreSQL 17
- **Contexto:** la spec admite MySQL 8 o PostgreSQL. La imagen `postgres:17.11-alpine` ya está disponible.
- **Decisión:** PostgreSQL. Aporta índices únicos parciales (join_code, una sesión abierta por mesa), `CHECK` e `jsonb`.
- **Consecuencias:** la réplica Java usa el mismo esquema. Pruebas contra PostgreSQL real, no SQLite.

## ADR-004 — Multi-tenancy: una base de datos por restaurante (sustituye a BD compartida con `restaurant_id`)
- **Contexto:** la spec (§5) fijaba una BD compartida con `restaurant_id` y desaconsejaba la tenancy con BD por tenant "salvo que se justifique". El desarrollador decidió que cada restaurante tenga su propia BD, creada al registrarlo desde un administrador.
- **Opciones:** BD compartida con scope; esquema por tenant; BD por tenant.
- **Decisión:** BD por tenant y por servicio (`mestaro_<tenant>_<servicio>`), con rol de BD propio. `platform` mantiene la BD central y el registro de tenants. Se implementa a medida (conexión dinámica por petición), sin paquetes de tenancy, que no encajan con varios servicios. Ver `architecture.md` §5.
- **Consecuencias:** aislamiento fuerte y borrado/backup por restaurante. Coste: provisión y migraciones en N bases, pooling (PgBouncer), consultas globales imposibles (informes agregados de plataforma van por eventos). Las tablas de tenant ya no llevan `restaurant_id` ni FK compuestas. Esto contradice la spec §5; queda registrado aquí.

## ADR-005 — Identificadores públicos ULID
- **Decisión:** `public_id` ULID en columna única; el `id` autoincremental nunca sale de la API. ULID es ordenable por tiempo y compatible con la réplica Java.

## ADR-006 — Importes en enteros
- **Decisión:** `bigint` en unidad mínima + ISO 4217 por restaurante; tipos impositivos en puntos básicos (`tax_bps`). Value object `Money`.

## ADR-007 — Servicios independientes con arquitectura hexagonal + DDD
- **Contexto:** se pidió SOLID, clean code, DDD y TDD, y que cada parte sea un servicio propio para no concentrar todo en un único despliegue.
- **Opciones:** monolito modular; hexagonal listo para extraer; servicios gruesos; microservicios finos.
- **Decisión:** servicios gruesos por contexto delimitado: `platform`, `catalog`, `operations`, `billing` (y `reporting` en Fase 7), cada uno desplegable por separado y hexagonal por dentro (Domain/Application/Infrastructure/Interface). Comunicación por API interna y eventos (outbox + Redis Streams); sin acceso a BD ajenas; código compartido mínimo en `shared-kernel`.
- **Consecuencias:** escalado y despliegue independientes. Coste: consistencia eventual y sagas, proyecciones locales, contratos de eventos, más infraestructura (compose con varios servicios) y tests de extremo a extremo. Se descartan los microservicios finos por exceso de carga operativa para el equipo actual.

## ADR-008 — Docker como único entorno de ejecución
- **Contexto:** el desarrollador ya dispone de imágenes de PHP, nginx, PostgreSQL, Redis y Mailpit.
- **Decisión:** todo se ejecuta en contenedores; una imagen propia multi-target (`dev`/`prod`) para todos los procesos PHP, siguiendo el patrón del proyecto Matchly, con un servicio de compose por servicio de negocio; imágenes oficiales para nginx, postgres, redis y mailpit. Implementación en Fase 2.
- **Consecuencias:** sin PHP/Composer requeridos en el host; comandos mediante `make`/`docker compose`.

## ADR-009 — Credenciales: Sanctum para personal, token de participante para clientes QR
- **Decisión:** el cliente QR no tiene cuenta. El `qr_token` identifica la mesa; la autorización la da una credencial de participante opaca (hash SHA-256 en BD) ligada a una sesión. El `join_code` solo se canjea una vez.

## ADR-010 — TDD y tests de arquitectura obligatorios
- **Decisión:** ciclo rojo-verde-refactor por Handler y objeto de dominio; pirámide de pruebas en `architecture.md` §14; `pest-plugin-arch` impone las reglas de dependencia entre capas y contextos en CI.
- **Consecuencias:** más disciplina al inicio; la violación de una frontera rompe el build.

## ADR-011 — Provisión de tenants dirigida por eventos
- **Decisión:** el alta de un restaurante en `platform` publica `TenantRegistered`; cada servicio crea su BD y rol, migra y responde `TenantProvisioned`. `platform` activa el tenant al recibir todas las respuestas; fallos dejan `provisioning_failed` con reintento idempotente.
- **Consecuencias:** altas asíncronas (el restaurante no está disponible al instante); requiere un registro de tenants replicado en cada servicio y cifrado de credenciales.

## ADR-012 — Autenticación entre servicios: JWT de vida corta emitido por `platform`
- **Contexto:** los tokens personales de Sanctum solo se pueden validar consultando su base, que ahora es central. La spec nombra Sanctum.
- **Decisión:** Sanctum se usa en `platform` para el login; `platform` emite JWT de acceso (firma asimétrica, JWKS interno) que los demás servicios verifican localmente. Los tokens de participante QR los emite y valida `operations`.
- **Consecuencias:** desviación parcial de la spec (§3, §11.3); revocación vía refresh tokens y lista en Redis. Debe confirmarse en la Fase 2 antes de implementarse.

## ADR-013 — Alcance de Filament (PENDIENTE de confirmar)
- **Contexto:** Filament trabaja sobre Eloquent y una BD local; con servicios y BD por tenant, un único panel no puede ver datos de todos.
- **Propuesta:** Filament en `platform` (superadmin: restaurantes, usuarios, planes) y en `catalog` (menú, mesas, QR: el CRUD principal del restaurante). Pedidos, inventario, pagos e informes se gestionan desde la app Vue de personal contra las APIs de cada servicio.
- **Consecuencias:** desvío de la spec §8 (un panel Filament único con todo). Alternativa: un servicio `admin` (BFF) con Filament sobre las APIs, más lento y más código.
