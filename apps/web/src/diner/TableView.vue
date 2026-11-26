<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError, api } from '../api/client'
import { explain } from '../api/errors'
import OrderLines from '../components/OrderLines.vue'
import PageState from '../components/PageState.vue'
import Badge from '../components/ui/Badge.vue'
import Button from '../components/ui/Button.vue'
import Card from '../components/ui/Card.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import { usePolling } from '../composables/usePolling'
import { useLoad } from '../composables/useRequest'
import { formatMoney } from '../lib/money'
import { orderStatus, type Order } from '../lib/orders'
import { MAX_LINES, provisionalPrice, useCart, type Line } from './cart'
import ProductSheet from './ProductSheet.vue'
import type { DinerSession, Menu, MenuProduct } from './types'

/** The diner at its table: menu, cart and the orders of the table. */
const props = defineProps<{ qr: string; token: string }>()
const emit = defineEmits<{ over: [] }>()

const call = <T>(path: string, init: Parameters<typeof api>[1] = {}) =>
  api<{ data: T }>(path, { ...init, auth: false, headers: { Authorization: `Bearer ${props.token}`, ...init.headers } }).then((r) => r.data)

/** The credential stops working when the staff closes the table. */
function checkOver(e: unknown): boolean {
  const over = e instanceof ApiError && (e.status === 401 || e.code === 'SESSION_CLOSED')
  if (over) emit('over')
  return over
}
const checked = <T>(request: Promise<T>) => request.catch((e: unknown) => { checkOver(e); throw e })

const { data: menu, error: menuError, load: loadMenu } = useLoad(() => checked(call<Menu>('/session/menu')))
const { data: table, error: tableError, load: refresh } = usePolling(async () => {
  const [session, orders] = await Promise.all([
    call<{ session: DinerSession; me: { id: string } }>('/session'),
    call<Order[]>('/session/orders'),
  ])
  return { ...session, orders: [...orders].reverse() }
}, checkOver)

const cart = useCart(`mestaro-cart:${props.qr}`)
const products = computed(() => new Map(menu.value?.categories.flatMap((c) => c.products).map((p) => [p.id, p])))
const total = computed(() => cart.lines.value.reduce((sum, l) => {
  const product = products.value.get(l.product_id)
  return sum + (product ? provisionalPrice(product, l) : 0)
}, 0))
const describe = (line: Line) => {
  const product = products.value.get(line.product_id)
  const variant = product?.variants.find((v) => v.id === line.variant_id)?.name
  const extras = line.addons.map((a) => `${a.quantity} × ${product?.addons.find((x) => x.id === a.addon_id)?.name ?? '?'}`)
  return [variant, ...extras, line.notes].filter(Boolean).join(' · ')
}

const choosing = ref<MenuProduct | null>(null)
const sending = ref(false)
const failure = ref('')
const rejected = ref<number[]>([])
const sent = ref(false)

function add(line: Line) {
  cart.add(line)
  choosing.value = null
  sent.value = false
}
function remove(index: number) {
  cart.remove(index)
  // The server's marks point at positions that just moved.
  rejected.value = []
  failure.value = ''
}

async function send() {
  if (sending.value) return
  sending.value = true
  failure.value = ''
  rejected.value = []
  const count = cart.lines.value.length
  try {
    await call<Order>('/orders', { method: 'POST', json: cart.body.value, headers: { 'Idempotency-Key': cart.key.value } })
    cart.dropSent(count)
    sent.value = true
    await refresh()
  } catch (e) {
    if (checkOver(e)) return
    // The cart stays as it is: sending it again with the same key is safe.
    failure.value = explain(e)
    if (e instanceof ApiError && e.details) {
      rejected.value = Object.keys(e.details).map((k) => Number(k.split('.')[1])).filter(Number.isInteger)
      await loadMenu()
    }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <PageState :ready="menu !== undefined && table !== undefined" :error="menuError || tableError" @retry="loadMenu(); refresh()">
    <h1 class="text-xl font-semibold">{{ table!.session.table_label ?? 'Tu mesa' }}</h1>
    <p v-if="tableError" role="status" class="mt-2 text-warning">Sin conexión: puede que no veas los últimos cambios.</p>
    <Card v-if="table!.session.status === 'pending_approval'" class="mt-3">
      <p role="status">Avisa al camarero para que abra la mesa. Mientras, puedes ir eligiendo.</p>
    </Card>
    <p v-else-if="table!.session.join_code" class="mt-1 text-muted">
      Código para unirse a la mesa: <strong class="font-mono text-lg tracking-widest text-text">{{ table!.session.join_code }}</strong>
    </p>

    <section v-if="table!.orders.length" aria-labelledby="orders-title" class="mt-6">
      <h2 id="orders-title" class="text-lg font-semibold">Pedidos de la mesa</h2>
      <Card v-for="o in table!.orders" :key="o.id" class="mt-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="text-sm text-muted">{{ o.participant_id === table!.me.id ? 'Tu pedido' : (o.participant_name ?? 'Otro comensal') }}</span>
          <Badge :tone="orderStatus[o.status].tone">{{ orderStatus[o.status].label }}</Badge>
        </div>
        <OrderLines :order="o" />
      </Card>
    </section>

    <EmptyState v-if="menu!.categories.length === 0" title="El restaurante aún no ha publicado su carta" />
    <section v-for="c in menu!.categories" :key="c.id" :aria-labelledby="`cat-${c.id}`" class="mt-6">
      <h2 :id="`cat-${c.id}`" class="text-lg font-semibold">{{ c.name }}</h2>
      <p v-if="c.description" class="text-muted">{{ c.description }}</p>
      <ul class="mt-2 flex flex-col gap-2">
        <li v-for="p in c.products" :key="p.id">
          <button type="button" class="flex min-h-[var(--touch-target)] w-full items-center gap-3 rounded-lg border border-border bg-surface p-3 text-left hover:border-control disabled:opacity-60" :disabled="!p.orderable" @click="choosing = p">
            <img v-if="p.image_url" :src="p.image_url" alt="" loading="lazy" width="64" height="64" class="size-16 shrink-0 rounded-md object-cover" />
            <span class="flex-1">
              <span class="block font-medium">{{ p.name }}</span>
              <span v-if="p.description" class="block text-sm text-muted">{{ p.description }}</span>
              <span v-if="!p.orderable" class="block text-sm text-warning">No disponible ahora</span>
            </span>
            <span class="shrink-0">{{ formatMoney(p.price, menu!.currency) }}</span>
          </button>
        </li>
      </ul>
    </section>
    <p v-if="!menu!.prices_include_tax" class="mt-4 text-sm text-muted">Precios sin impuestos.</p>

    <section aria-labelledby="cart-title" class="sticky bottom-0 mt-6 max-h-[50vh] overflow-auto border-t border-border bg-bg py-3">
      <h2 id="cart-title" class="font-semibold">Tu pedido</h2>
      <p v-if="sent" role="status" class="text-success">Pedido enviado.</p>
      <p v-if="cart.lines.value.length === 0" class="text-muted">Aún no has elegido nada.</p>
      <template v-else>
        <ul class="divide-y divide-border">
          <li v-for="(l, i) in cart.lines.value" :key="i" class="flex items-center gap-2 py-1">
            <div class="flex-1">
              <span>{{ l.quantity }} × {{ products.get(l.product_id)?.name ?? 'Producto retirado' }}</span>
              <p v-if="describe(l)" class="text-sm text-muted">{{ describe(l) }}</p>
              <p v-if="rejected.includes(i)" class="text-sm text-danger">No disponible: quítalo y elige otro.</p>
            </div>
            <Button size="lg" variant="tertiary" :disabled="sending" :aria-label="`Quitar ${products.get(l.product_id)?.name ?? 'producto retirado'} del pedido`" @click="remove(i)">Quitar</Button>
          </li>
        </ul>
        <p v-if="failure" role="alert" class="mt-2 text-danger">{{ failure }}</p>
        <Button size="lg" variant="primary" class="mt-2 w-full" :loading="sending" :disabled="table!.session.status === 'pending_approval' || cart.lines.value.length > MAX_LINES" @click="send">
          {{ sending ? 'Enviando…' : `Pedir · ${formatMoney(total, menu!.currency)}` }}
        </Button>
        <p v-if="table!.session.status === 'pending_approval'" class="mt-1 text-sm text-muted">Podrás enviarlo cuando el camarero abra la mesa.</p>
      </template>
    </section>

    <ProductSheet v-if="choosing" :product="choosing" :currency="menu!.currency" @add="add" @close="choosing = null" />
  </PageState>
</template>
