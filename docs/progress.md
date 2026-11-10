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

Pendiente de 2a: relay del outbox a Redis Streams, auditoría, tokens de diseño con test de contraste, marcador temporal de marca.
