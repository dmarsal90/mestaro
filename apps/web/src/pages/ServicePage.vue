<script setup lang="ts">
import { computed, ref } from 'vue'
import { api } from '../api/client'
import OrderLines from '../components/OrderLines.vue'
import PageState from '../components/PageState.vue'
import Badge from '../components/ui/Badge.vue'
import Button from '../components/ui/Button.vue'
import Card from '../components/ui/Card.vue'
import Dialog from '../components/ui/Dialog.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import FormField from '../components/ui/FormField.vue'
import Input from '../components/ui/Input.vue'
import { usePolling } from '../composables/usePolling'
import { useAction } from '../composables/useRequest'
import { orderStatus, type Order } from '../lib/orders'
import { useSession } from '../stores/session'

/** The dining room during service: tables waiting or open, and the orders to decide on. */
type TableSession = { id: string; table_label: string | null; status: 'pending_approval' | 'open' | 'bill_requested'; join_code: string; participants: number }

const session = useSession()
const canDecide = computed(() => session.can('orders.accept'))
const { data, error: loadError, load } = usePolling(async () => {
  const list = (status: string) => api<{ data: Order[] }>(`/orders?filter[status]=${status}&per_page=100`).then((r) => r.data)
  const [sessions, pending, accepted] = await Promise.all([api<{ data: TableSession[] }>('/sessions').then((r) => r.data), list('pending'), list('accepted')])
  const live = new Set(sessions.map((s) => s.id))
  // shortcut: accepted orders stay until their table closes; item states will move them out sooner.
  return { sessions, pending: [...pending].reverse(), accepted: accepted.filter((o) => live.has(o.session_id)) }
})

const { busy, error, run, reset } = useAction()
async function act(path: string, success: string, json?: unknown): Promise<boolean> {
  const ok = await run(() => api(path, { method: 'POST', json }), success)
  // Also after a failure: someone else may have changed it.
  await load()
  return ok
}

const closing = ref<TableSession | null>(null)
const declining = ref<{ order: Order; action: 'reject' | 'cancel' } | null>(null)
const reason = ref('')
const waitingOn = computed(() => data.value?.pending.filter((o) => o.session_id === closing.value?.id).length ?? 0)

function ask(order: Order, action: 'reject' | 'cancel') {
  reset()
  reason.value = ''
  declining.value = { order, action }
}
async function decline() {
  const { order, action } = declining.value!
  if (await act(`/orders/${order.id}/${action}`, action === 'reject' ? 'Pedido rechazado.' : 'Pedido cancelado.', { reason: reason.value.trim() || null })) declining.value = null
}
async function close() {
  if (await act(`/sessions/${closing.value!.id}/close`, 'Mesa cerrada.')) closing.value = null
}
const time = (value: string) => new Date(value).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })
</script>

<template>
  <h1 class="text-xl font-semibold">Sala</h1>
  <PageState :ready="data !== undefined" :error="loadError" @retry="load">
    <p v-if="loadError" role="status" class="mt-2 text-warning">Sin conexión: puede que no veas los últimos cambios.</p>
    <p v-if="error && !closing && !declining" role="alert" class="mt-2 text-danger">{{ error }}</p>

    <section aria-labelledby="pending-title" class="mt-4">
      <h2 id="pending-title" class="text-lg font-semibold">Pedidos por aceptar</h2>
      <p v-if="data!.pending.length === 0" class="mt-2 text-muted">Ninguno.</p>
      <div class="mt-2 grid gap-2 md:grid-cols-2">
        <Card v-for="o in data!.pending" :key="o.id">
          <h3 class="font-semibold">{{ o.table_label ?? 'Mesa' }} · {{ time(o.created_at) }}</h3>
          <OrderLines :order="o" />
          <div v-if="canDecide" class="mt-3 flex justify-end gap-2">
            <Button size="lg" :aria-label="`Rechazar el pedido de ${o.table_label} de las ${time(o.created_at)}`" @click="ask(o, 'reject')">Rechazar</Button>
            <Button size="lg" variant="primary" :loading="busy" :aria-label="`Aceptar el pedido de ${o.table_label} de las ${time(o.created_at)}`" @click="act(`/orders/${o.id}/accept`, 'Pedido aceptado.')">Aceptar</Button>
          </div>
        </Card>
      </div>
    </section>

    <section aria-labelledby="tables-title" class="mt-6">
      <h2 id="tables-title" class="text-lg font-semibold">Mesas</h2>
      <EmptyState v-if="data!.sessions.length === 0" title="No hay mesas abiertas" description="Aparecen cuando un comensal escanea el QR de su mesa." />
      <ul v-else class="mt-2 divide-y divide-border">
        <li v-for="s in data!.sessions" :key="s.id" class="flex flex-wrap items-center gap-2 py-2">
          <span class="font-medium">{{ s.table_label }}</span>
          <Badge v-if="s.status === 'pending_approval'" tone="warning">Pide abrir</Badge>
          <Badge v-else tone="success">Abierta</Badge>
          <span class="text-sm text-muted">{{ s.participants }} {{ s.participants === 1 ? 'comensal' : 'comensales' }}</span>
          <span v-if="s.status !== 'pending_approval'" class="text-sm text-muted">Código <span class="font-mono tracking-widest text-text">{{ s.join_code }}</span></span>
          <div v-if="canDecide" class="ml-auto flex gap-2">
            <Button v-if="s.status === 'pending_approval'" size="lg" variant="primary" :loading="busy" :aria-label="`Aprobar la mesa ${s.table_label}`" @click="act(`/sessions/${s.id}/approve`, 'Mesa abierta.')">Aprobar</Button>
            <Button size="lg" variant="tertiary" :aria-label="`${s.status === 'pending_approval' ? 'Rechazar' : 'Cerrar'} la mesa ${s.table_label}`" @click="reset(); closing = s">{{ s.status === 'pending_approval' ? 'Rechazar' : 'Cerrar' }}</Button>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="data!.accepted.length" aria-labelledby="accepted-title" class="mt-6">
      <h2 id="accepted-title" class="text-lg font-semibold">Pedidos aceptados</h2>
      <div class="mt-2 grid gap-2 md:grid-cols-2">
        <Card v-for="o in data!.accepted" :key="o.id">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-semibold">{{ o.table_label ?? 'Mesa' }} · {{ time(o.created_at) }}</h3>
            <Badge :tone="orderStatus[o.status].tone">{{ orderStatus[o.status].label }}</Badge>
          </div>
          <OrderLines :order="o" />
          <div v-if="canDecide" class="mt-3 flex justify-end">
            <Button size="lg" variant="tertiary" :aria-label="`Cancelar el pedido de ${o.table_label} de las ${time(o.created_at)}`" @click="ask(o, 'cancel')">Cancelar pedido</Button>
          </div>
        </Card>
      </div>
    </section>
  </PageState>

  <Dialog :open="declining !== null" :title="declining?.action === 'cancel' ? '¿Cancelar el pedido?' : '¿Rechazar el pedido?'" :description="`${declining?.order.table_label ?? 'Mesa'}. El comensal verá el motivo. No se puede deshacer.`" @update:open="declining = null">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="decline">
      <FormField v-slot="f" label="Motivo (opcional)"><Input v-bind="f" v-model="reason" maxlength="255" /></FormField>
      <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
      <Button type="submit" size="lg" variant="danger" :loading="busy">{{ declining?.action === 'cancel' ? 'Cancelar pedido' : 'Rechazar pedido' }}</Button>
    </form>
  </Dialog>

  <Dialog
    :open="closing !== null" :title="`¿Cerrar la mesa ${closing?.table_label}?`"
    :description="waitingOn ? `Pedidos sin aceptar: ${waitingOn}. Los comensales tendrán que volver a escanear el QR.` : 'Los comensales tendrán que volver a escanear el QR.'"
    @update:open="closing = null"
  >
    <p v-if="error" role="alert" class="mb-2 text-sm text-danger">{{ error }}</p>
    <div class="flex justify-end gap-2">
      <Button size="lg" @click="closing = null">Volver</Button>
      <Button size="lg" variant="danger" :loading="busy" @click="close">Cerrar mesa</Button>
    </div>
  </Dialog>
</template>
