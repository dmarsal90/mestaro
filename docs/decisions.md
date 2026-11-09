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

## ADR-004 — Multi-tenancy: BD compartida con `restaurant_id` y FK compuestas
- **Decisión:** según spec §5. Añadido: scope que falla cerrado sin tenant y FK compuestas `(restaurant_id, id)` como defensa en profundidad en BD.
- **Consecuencias:** migraciones algo más verbosas; cada tabla padre necesita `UNIQUE (restaurant_id, id)`. No se usa stancl/tenancy.

## ADR-005 — Identificadores públicos ULID
- **Decisión:** `public_id` ULID en columna única; el `id` autoincremental nunca sale de la API. ULID es ordenable por tiempo y compatible con la réplica Java.

## ADR-006 — Importes en enteros
- **Decisión:** `bigint` en unidad mínima + ISO 4217 por restaurante; tipos impositivos en puntos básicos (`tax_bps`). Value object `Money`.

## ADR-007 — Monolito modular por dominio
- **Decisión:** `app/Domain/<Modulo>` con Actions/DTOs/Enums/Models/Policies; presentación en `Http`, `Filament`, `Broadcasting`. Sin paquetes de módulos ni DDD completo (complejidad innecesaria, spec §4).

## ADR-008 — Docker como único entorno de ejecución
- **Contexto:** el desarrollador ya dispone de imágenes de PHP, nginx, PostgreSQL, Redis y Mailpit.
- **Decisión:** todo se ejecuta en contenedores; una imagen propia multi-target (`dev`/`prod`) para todos los procesos PHP, siguiendo el patrón del proyecto Matchly; imágenes oficiales para nginx, postgres, redis y mailpit. Implementación en Fase 2.
- **Consecuencias:** sin PHP/Composer requeridos en el host; comandos mediante `make`/`docker compose`.

## ADR-009 — Credenciales: Sanctum para personal, token de participante para clientes QR
- **Decisión:** el cliente QR no tiene cuenta. El `qr_token` identifica la mesa; la autorización la da una credencial de participante opaca (hash SHA-256 en BD) ligada a una sesión. El `join_code` solo se canjea una vez.
