# Progreso

## Fase 1 — Análisis y arquitectura (completada, pendiente de aprobación)

Hecho:
- `architecture.md`, `data-model.md` (ER en Mermaid), `decisions.md` (ADR-001…013), borradores de `api.md`, `realtime.md` y `openapi.yaml`.
- Revisión tras feedback: servicios independientes (`platform`, `catalog`, `operations`, `billing`) con arquitectura hexagonal + DDD, TDD y tests de arquitectura; multi-tenancy con una BD por restaurante provisionada desde un admin (ADR-004, 007, 010, 011, 012, 013).
- Decisiones del desarrollador: Laravel 13 (desvío de la spec, que decía 12), Filament 5, todo en Docker reutilizando imágenes existentes.

No verificado:
- `openapi.yaml` no se ha validado con ninguna herramienta (no hay tooling hasta la Fase 2).
- Compatibilidad real de todas las dependencias con Laravel 13: solo comprobadas Filament 5 y Reverb en Packagist.

Pendiente / para fases siguientes:
- **Logo y favicon:** el equipo los aportará. Hasta entonces, marcador temporal (M dorada sobre cuadrado Obsidian) en Fase 2. Copiar los archivos con los nombres de spec §2.3 a `resources/brand/`.
- **Fase 2:** Dockerfile multi-target, compose, instalación de Laravel 13 + Filament 5, CI. Decidir Horizon vs `queue:work` y SPA única vs dos entradas Vite.
- **Fase 3:** menús especiales / menú del día; destinos operativos configurables (¿tabla `destinations`?).
- **Fase 6:** tabla `payment_items`, pasarela de pago concreta.
- **Fase 7:** tablas de agregados de informes.
- **Fase 2 crece:** varios servicios, shared-kernel, provisión de tenants, JWT/JWKS, bus de eventos y `docs/events.md`. **Partición confirmada:** 2a = esqueleto del monorepo, Docker, CI, shared-kernel, `platform` (auth, JWT/JWKS, registro y provisión de tenants, auditoría, tokens de diseño); 2b = esqueletos de `catalog`, `operations` y `billing` con resolución de tenant, bus de eventos, `docs/events.md`.
- ADR-012 (JWT entre servicios) y ADR-013 (alcance de Filament) **confirmadas**.
- `data-model.md` conserva menciones al `restaurant_id` del diseño anterior; se depuran al implementar.

## Fase 2a — avance

Hecho (PR #4): stack Docker, esqueleto `platform` (Laravel 13), CI, tests de arquitectura.
Hecho (rama `feat/phase-2a-tenant-registry`): agregado `Restaurant` (estados provisioning/active/provisioning_failed/suspended), caso de uso `RegisterRestaurant`, persistencia en PostgreSQL y outbox transaccional (`TenantRegistered`).

Decisión: `packages/shared-kernel` se pospone hasta que un segundo servicio (2b) necesite código común; con un único consumidor sería una abstracción especulativa.

Hecho: JWT Ed25519 + JWKS y `/api/v1/admin/restaurants` (POST 202, GET, suspend, retry-provisioning) protegido por middleware que exige JWT con rol `platform_admin` sin tenant. 

Hecho: usuarios (`public_id`, `is_platform_admin`, `is_active`), login/refresh/logout/me con Sanctum (refresh tokens rotatorios) emitiendo el JWT, comando `platform:create-admin`. Hash Argon2id vía `HASH_DRIVER` en compose. Solo admins de plataforma pueden iniciar sesión (personal de restaurante requiere membresías/roles, pendiente). Sin MFA ni denylist de JWT (atajos documentados en código).

Hecho: relay del outbox (`php artisan outbox:relay`, servicio compose `platform-relay`): `XADD` al stream `mestaro.events` (campos `event_id`, `type`, `occurred_at`, `payload` JSON), entrega al menos una vez; los consumidores deben deduplicar por `event_id`. Un solo relay garantiza orden de `id`; con varios solo hay orden aproximado.

Hecho: auditoría de plataforma (`audit_logs`, solo inserción reforzada con trigger de Postgres; puerto `AuditTrail`). Registra login, fallo de login, refresh, logout, alta/suspensión/reintento de restaurantes (en la misma transacción que el cambio) y accesos denegados (token inválido o 403). Los metadatos descartan claves tipo password/token. Sin auditar aún: respuestas 429 y peticiones sin token; sin política de retención ni alertas (el job de retención debe desactivar el trigger a propósito).

Hecho: tokens de diseño en `apps/web/src/styles/tokens.css` con test de contraste WCAG AA (`apps/web/tests/tokens.test.ts`, antes `DesignTokensTest`) y assets de marca en `resources/brand/`. Regla: `--titanium-muted` (4,1:1 sobre `--carbon-surface`) solo para iconos o sobre `--carbon`.

Pendiente de 2a: nada de producto; falta pushear `fix/docker-drop-gd` (build de `platform-worker`). El tema Filament que consume los tokens llega cuando se instale Filament.

## Fase 3 (2026-11-16, PR #21-#35)

Hecho: identidad del personal (login con elección de restaurante, recuperación de contraseña, denylist de JWT por restaurante), ajustes, personal con invitación y aceptación, contrato OpenAPI con test en `platform` y `catalog` (checker común en el kernel); migraciones por restaurante en el kernel; `catalog` con zonas, mesas, QR rotable, categorías, productos, variantes, complementos, disponibilidad y fotos; panel Filament de `platform` (2FA obligatorio); `apps/web` con tokens (modo claro/oscuro, `--titanium-deep`), 23 componentes del sistema de diseño con tests de accesibilidad y el *shell* del personal (login, sesión, guardas, layout, tema); marca recoloreada a Modena.

Pendiente de Fase 3: panel Filament de `catalog` (ADR-013: necesita un puente de autenticación JWT y la BD del restaurante por petición; se recomienda reevaluarlo frente a las pantallas Vue); tema Filament completo con tokens; servir `apps/web` desde el gateway; D-11 (animación de entrada, requiere autorización) y D-12 (objetivo LCP); evento del índice central de `qr_token` (Fase 4); listado y cancelación de invitaciones; recorte de re-codificación de fotos cuando haya extensión de imagen.

Pantallas de trabajo (PR posteriores a #37): carta, salón, equipo, ajustes y aceptación de invitación en `apps/web`, probadas contra los servicios reales a 360, 768 y 1280 px. Sin hacer: página de restablecer contraseña (el correo ya enlaza a `/reset-password`), imagen del QR de mesa (llega con el cliente QR, Fase 4), edición de `preferences`, claves i18n (D-15: los textos están en el código) y revisión con lector de pantalla.

## Fase 4 — avance

Partición aprobada (2026-11-26): 4a eventos de `catalog`; 4b proyecciones en `operations`, índice central de `qr_token` y carga inicial; 4c sesiones de mesa, participantes, `join_code` y límites; 4d carta pública y pedidos (precios del servidor, `Idempotency-Key`, máquina de estados); 4e cliente QR. El carrito se guarda en el navegador del comensal.

### 4a — eventos de catálogo

`catalog` publica `CategoryChanged`, `ProductChanged`, `ZoneChanged` y `TableChanged` desde un `outbox` por restaurante (`events.md`). Al desplegar: `php artisan tenants:migrate` en `catalog`.

### 4b — proyecciones

`operations` mantiene `catalog_projection` por restaurante y el índice central `qr_tokens`; `catalog:publish-state` hace la carga inicial. Pasos de despliegue en `events.md`.

### 4c — sesiones de mesa

`operations`: apertura por QR, incorporación con `join_code`, aprobación y cierre por el personal, credenciales de participante y límites de intentos. `platform` publica `SettingsChanged`.

Decisiones: el código admite varios comensales mientras la sesión está abierta y la respuesta entrega ya la credencial (sin el token intermedio de un solo uso de `spec.md` §7.6); una petición sin aprobar caduca a los 15 minutos; las sesiones abiertas no caducan solas; solo el personal cierra.

Al desplegar: `php artisan tenants:migrate` en `operations` y `php artisan platform:publish-settings` en `platform`. Pendiente: suspender un restaurante no bloquea todavía sus rutas públicas; no hay pantalla de personal para las sesiones.

### 4d — carta pública y pedidos

`operations`: carta desde la proyección, pedidos con precios e impuestos calculados en el servidor, `Idempotency-Key`, auto-aceptación y aceptar, rechazar o cancelar por el personal con historial de solo inserción.

Decisiones: el comensal lee sus pedidos en `GET /session/orders` (los de toda la mesa), así `/orders` queda solo para el personal salvo el `POST`; el impuesto se redondea por línea (mitad hacia arriba); sin variante elegida se usa la de defecto; los límites son 50 líneas, 50 unidades por línea y 20 extras distintos.

Al desplegar: `php artisan tenants:migrate` en `operations`. Los restaurantes que no hayan cambiado ajustes usan los valores por defecto de `platform`. Pendiente: estados por artículo y etiqueta de progreso, pedidos desde TPV, eventos y tiempo real, limpieza de claves de idempotencia.
