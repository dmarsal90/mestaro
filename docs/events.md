# Eventos de dominio

Transporte: Redis Stream `mestaro.events` (`EVENTS_STREAM`). Cada evento se publica con *transactional outbox* y el relay (`outbox:relay`) lo copia al stream, **al menos una vez**: los consumidores deben ser idempotentes.

Campos de cada entrada del stream: `event_id` (ULID, único por evento), `type`, `occurred_at`, `payload` (JSON), `sequence` (id del outbox del que salió).

Consumo: grupo de consumidores (`XREADGROUP`), `XACK` solo tras procesar. Los mensajes pendientes se reclaman tras 60 s (`XAUTOCLAIM`). Un tipo de evento desconocido se confirma e ignora. Un mensaje cuyo handler falla más de 5 veces (`--max-deliveries`) pasa al stream `mestaro.events.dead` (`EVENTS_DEAD_STREAM`) con sus campos originales más `source_id` y `dead_at`, y se confirma; revisar ese stream es parte de la operación.

## Provisión de tenants

Flujo (ADR-004/013): `platform` publica `TenantRegistered`; cada servicio crea su rol y BD y responde. `platform` activa el restaurante cuando han respondido todos los servicios de `TENANCY_SERVICES` (por defecto `platform`).

| Evento | Publica | Payload | Efecto en `platform` |
|---|---|---|---|
| `TenantRegistered` | `platform` | `tenant_id`, `slug` | Cada servicio provisiona su BD. También se emite de nuevo en un reintento. |
| `TenantProvisioned` | cada servicio | `tenant_id`, `service` | Registra el servicio; activa el restaurante si ya respondieron todos. |
| `TenantProvisioningFailed` | cada servicio | `tenant_id`, `service`, `reason` | Deja el restaurante en `provisioning_failed` con `service: reason` (máx. 200 caracteres). |

Reglas para quien responda:

- `tenant_id` es el ULID del restaurante; `service` debe estar en `TENANCY_SERVICES`, si no `platform` descarta el evento. Al incorporar un servicio nuevo hay que añadirlo a `TENANCY_SERVICES` **antes** de que empiece a responder (y antes de registrar restaurantes que deba provisionar, o se activarían sin él).
- Responder es idempotente: repetir `TenantProvisioned` no cambia nada. Un resultado que llega cuando el restaurante ya no está en `provisioning` se ignora.
- `reason` se expone en la API de administración: sin mensajes de drivers, credenciales ni rutas.
- Un evento para un restaurante desconocido se registra y se descarta.

## Catálogo y sala

`catalog` escribe estos eventos en el `outbox` de la base de datos del restaurante, en la misma transacción que el cambio; `catalog-relay` recorre los restaurantes provisionados y los publica. Cada payload lleva `tenant_id` y el **estado completo** de la fila (no el cambio), con la misma forma que la respuesta de la API.

| Evento | Payload | Se publica al |
|---|---|---|
| `CategoryChanged` | `tenant_id`, `category` | Crear o editar una categoría. |
| `ProductChanged` | `tenant_id`, `product` (con `variants` y `addons`) | Crear o editar un producto, su foto o sus opciones. Al borrarlo, `product` es `{id, deleted: true}`. |
| `ZoneChanged` | `tenant_id`, `zone` | Crear o editar una zona. |
| `TableChanged` | `tenant_id`, `table` (incluye `qr_token`) | Crear o editar una mesa y rotar su QR. |

Reglas para quien consuma:

- Aplicar un evento solo si su `sequence` es mayor que la guardada para esa fila de ese restaurante: la entrega puede repetirse o desordenarse. `sequence` no se compara entre restaurantes ni entre servicios.
- Una entidad no incluye el estado de otra: desactivar una categoría o una zona no reemite sus productos o mesas.
- `php artisan catalog:publish-state` (en `catalog`) vuelve a publicar el estado actual de todos los restaurantes: carga inicial de una proyección o reparación. Se puede repetir.

### Proyecciones en `operations`

El consumidor de `operations` guarda cada estado en `catalog_projection` (base de datos del restaurante) y, para las mesas, el hash del `qr_token` en el índice central `qr_tokens` (restaurante y mesa). El token en claro no se almacena. Un evento de un restaurante que `operations` no ha provisionado queda pendiente y acaba en el stream de descartados.

**Despliegue**, en este orden:

1. `operations`: `php artisan migrate` y `php artisan tenants:migrate`; reiniciar su consumidor.
2. `catalog`: `php artisan tenants:migrate`; reiniciar su relay.
3. `catalog`: `php artisan catalog:publish-state`.
