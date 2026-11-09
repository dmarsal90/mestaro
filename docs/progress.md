# Progreso

## Fase 1 — Análisis y arquitectura (completada, pendiente de aprobación)

Hecho:
- `architecture.md`, `data-model.md` (ER en Mermaid), `decisions.md` (ADR-001…009), borradores de `api.md`, `realtime.md` y `openapi.yaml`.
- Revisión tras feedback: arquitectura cambiada a hexagonal + DDD con contextos extraíbles, TDD y tests de arquitectura (ADR-007, ADR-010).
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
