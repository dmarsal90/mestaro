<script setup lang="ts">
import { formatMoney } from '../lib/money'
import type { Order } from '../lib/orders'

/** What an order holds, as the diner and the staff read it. */
defineProps<{ order: Order }>()
</script>

<template>
  <ul class="mt-2 flex flex-col gap-1">
    <li v-for="i in order.items" :key="i.id" class="flex justify-between gap-3">
      <div>
        <span>{{ i.quantity }} × {{ i.name }}<template v-if="i.variant_name"> ({{ i.variant_name }})</template></span>
        <p v-if="i.addons.length" class="text-sm text-muted">Con {{ i.addons.map((a) => (a.quantity > 1 ? `${a.quantity} × ${a.name}` : a.name)).join(', ') }}</p>
        <p v-if="i.notes" class="text-sm text-muted">Nota: {{ i.notes }}</p>
      </div>
      <span class="shrink-0">{{ formatMoney(i.line_total, order.currency) }}</span>
    </li>
  </ul>
  <p v-if="order.notes" class="mt-1 text-sm text-muted">Nota del pedido: {{ order.notes }}</p>
  <p v-if="order.reason" class="mt-1 text-sm">Motivo: {{ order.reason }}</p>
  <p class="mt-2 flex justify-between font-semibold"><span>Total</span><span>{{ formatMoney(order.total, order.currency) }}</span></p>
</template>
