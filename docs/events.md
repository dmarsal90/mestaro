# Eventos de dominio

Transporte: Redis Stream `mestaro.events` (`EVENTS_STREAM`). Cada evento se publica con *transactional outbox* y el relay (`outbox:relay`) lo copia al stream, **al menos una vez**: los consumidores deben ser idempotentes.

Campos de cada entrada del stream: `event_id` (ULID, único por evento), `type`, `occurred_at`, `payload` (JSON).

Consumo: grupo de consumidores (`XREADGROUP`), `XACK` solo tras procesar. Los mensajes pendientes se reclaman tras 60 s (`XAUTOCLAIM`). Un tipo de evento desconocido se confirma e ignora.

## Provisión de tenants

Flujo (ADR-004/013): `platform` publica `TenantRegistered`; cada servicio crea su rol y BD y responde. `platform` activa el restaurante cuando han respondido todos los servicios de `TENANCY_SERVICES` (por defecto `platform`).

| Evento | Publica | Payload | Efecto en `platform` |
|---|---|---|---|
| `TenantRegistered` | `platform` | `tenant_id`, `slug` | Cada servicio provisiona su BD. También se emite de nuevo en un reintento. |
| `TenantProvisioned` | cada servicio | `tenant_id`, `service` | Registra el servicio; activa el restaurante si ya respondieron todos. |
| `TenantProvisioningFailed` | cada servicio | `tenant_id`, `service`, `reason` | Deja el restaurante en `provisioning_failed` con `service: reason` (máx. 200 caracteres). |

Reglas para quien responda:

- `tenant_id` es el ULID del restaurante; `service` debe estar en `TENANCY_SERVICES`, si no `platform` descarta el evento.
- Responder es idempotente: repetir `TenantProvisioned` no cambia nada. Un resultado que llega cuando el restaurante ya no está en `provisioning` se ignora.
- `reason` se expone en la API de administración: sin mensajes de drivers, credenciales ni rutas.
- Un evento para un restaurante desconocido se registra y se descarta.
