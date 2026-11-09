# Contrato de tiempo real (borrador Fase 1)

Independiente de Laravel: la réplica Java debe poder emitir y consumir lo mismo (adaptador STOMP). Transporte actual: Laravel Reverb (protocolo Pusher), alojado en el servicio `operations`. Los eventos de otros servicios llegan a `operations` por el bus de eventos y este los retransmite; `restaurant_id` en los payloads equivale a `tenant_id`.

## Reglas generales

- Los eventos **no son fuente de verdad**. Al reconectar o detectar un hueco, el cliente vuelve a pedir el estado por API.
- Se emiten solo tras el commit (`ShouldDispatchAfterCommit`).
- Nombre estable (`broadcastAs`), PascalCase, independiente del namespace PHP.
- Todo payload incluye:

```json
{
  "event_id": "uuid",
  "occurred_at": "2026-01-01T12:00:00Z",
  "restaurant_id": "01J...",
  "version": 3,
  "data": { }
}
```

- El cliente descarta eventos con `event_id` ya visto y eventos cuyo `version` sea menor que el que ya tiene del recurso.
- Los identificadores del payload son `public_id` (ULID); nunca IDs internos.
- Importes: enteros en unidad mínima + `currency`.

## Canales

Todos privados. Los nombres incluyen el ULID del restaurante; conocer el nombre no da acceso: se autoriza en el endpoint de auth del canal.

| Canal | Quién | Autorización |
|---|---|---|
| `private-restaurant.{restaurant}.orders` | camarero, admin | membresía activa + permiso `orders.view` |
| `private-restaurant.{restaurant}.destination.{destination}` | cocina/barra (y admin) | membresía + permiso `kds.{destination}.view` |
| `private-restaurant.{restaurant}.calls` | camarero, admin | membresía + permiso `calls.view` |
| `private-restaurant.{restaurant}.menu` | personal | membresía (disponibilidad de productos) |
| `private-session.{session}` | participantes de la sesión | credencial de participante de esa sesión |

## Eventos

Payload completo de `data` por evento (se concreta con el resto del contrato al implementarse en Fase 4–6):

| Evento | Canales | `data` (campos principales) |
|---|---|---|
| `OrderPlaced` | orders, destination.* (si auto-aceptado), session | `order_id`, `table_id`, `status`, `total`, `currency`, `items[]` |
| `OrderAccepted` | orders, destination.*, session | `order_id`, `accepted_by`, `accepted_at` |
| `OrderRejected` | orders, session | `order_id`, `reason` |
| `OrderItemStatusChanged` | orders, destination.{d}, session | `order_id`, `item_id`, `from`, `to`, `destination` |
| `ProductAvailabilityChanged` | menu, session | `product_id`, `is_available`, `is_sold_out` |
| `WaiterCalled` | calls | `call_id`, `table_id`, `kind`, `note` |
| `WaiterCallResolved` | calls, session | `call_id`, `resolved_by` |
| `BillRequested` | calls, orders | `session_id`, `table_id` |
| `PaymentRegistered` | orders, session | `payment_id`, `amount`, `tip_amount`, `currency`, `remaining` |
| `SessionStatusChanged` | orders, session | `session_id`, `status` |

## Cliente (Vue)

Interfaz propia `RealtimeClient { subscribe(channel, handler); unsubscribe(channel); onReconnect(cb) }` con `EchoAdapter`. Las vistas nunca importan Echo. Un `StompAdapter` para la réplica Java sustituye al adaptador sin tocar las vistas.
