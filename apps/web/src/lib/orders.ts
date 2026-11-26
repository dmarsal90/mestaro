export type OrderStatus = 'pending' | 'accepted' | 'rejected' | 'cancelled'
export type Order = {
  id: string; status: OrderStatus; notes: string | null; currency: string; total: number; reason: string | null; created_at: string
  session_id: string; table_label: string | null; participant_id: string | null; participant_name: string | null
  items: Array<{
    id: string; name: string; variant_name: string | null; quantity: number; line_total: number; notes: string | null
    addons: Array<{ name: string; quantity: number }>
  }>
}

export const orderStatus: Record<OrderStatus, { label: string; tone: 'warning' | 'success' | 'danger' | 'neutral' }> = {
  pending: { label: 'Esperando al camarero', tone: 'warning' },
  accepted: { label: 'Confirmado', tone: 'success' },
  rejected: { label: 'Rechazado', tone: 'danger' },
  cancelled: { label: 'Cancelado', tone: 'neutral' },
}
