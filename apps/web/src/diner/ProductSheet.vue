<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { allergens } from '../components/menu/types'
import Button from '../components/ui/Button.vue'
import Dialog from '../components/ui/Dialog.vue'
import FormField from '../components/ui/FormField.vue'
import Input from '../components/ui/Input.vue'
import RadioGroup from '../components/ui/RadioGroup.vue'
import { formatMoney } from '../lib/money'
import { provisionalPrice, type Line } from './cart'
import Stepper from './Stepper.vue'
import type { MenuProduct } from './types'

/** Choose variant, extras and quantity of one product. */
const props = defineProps<{ product: MenuProduct; currency: string }>()
const emit = defineEmits<{ add: [line: Line]; close: [] }>()

const variants = computed(() => props.product.variants.filter((v) => v.is_available))
const variant = ref((variants.value.find((v) => v.is_default) ?? variants.value[0])?.id)
const addons = computed(() => props.product.addons.filter((a) => a.is_available))
const extras = reactive<Record<string, number>>({})
const quantity = ref(1)
const notes = ref('')

const line = computed<Line>(() => ({
  product_id: props.product.id, variant_id: variant.value ?? null, quantity: quantity.value, notes: notes.value.trim(),
  addons: addons.value.filter((a) => extras[a.id]).map((a) => ({ addon_id: a.id, quantity: extras[a.id]! })),
}))
// Every variant sold out leaves nothing to choose.
const possible = computed(() => props.product.orderable && (props.product.variants.length === 0 || variant.value !== undefined))
</script>

<template>
  <Dialog :open="true" :title="product.name" :description="product.description ?? undefined" @update:open="emit('close')">
    <div class="flex flex-col gap-4">
      <p v-if="product.ingredients.length" class="text-sm text-muted">Ingredientes: {{ product.ingredients.join(', ') }}</p>
      <p class="text-sm text-muted">Alérgenos: {{ product.allergens.length ? product.allergens.map((a) => allergens[a] ?? a).join(', ') : 'ninguno indicado' }}</p>
      <RadioGroup
        v-if="variants.length" v-model="variant" legend="Elige"
        :options="variants.map((v) => ({ value: v.id, label: v.price_delta ? `${v.name} (+${formatMoney(v.price_delta, currency)})` : v.name }))"
      />
      <ul v-if="addons.length" class="flex flex-col gap-2" aria-label="Extras">
        <li v-for="a in addons" :key="a.id" class="flex items-center justify-between gap-2">
          <span>{{ a.name }} (+{{ formatMoney(a.price, currency) }})</span>
          <Stepper :model-value="extras[a.id] ?? 0" :label="a.name" :max="a.max_quantity" @update:model-value="extras[a.id] = $event" />
        </li>
      </ul>
      <FormField v-slot="f" label="Nota para cocina"><Input v-bind="f" v-model="notes" maxlength="255" /></FormField>
      <div class="flex items-center justify-between gap-2">
        <span>Cantidad</span>
        <Stepper v-model="quantity" label="Cantidad" :min="1" :max="50" />
      </div>
      <p v-if="!possible" role="alert" class="text-danger">Ahora no se puede pedir.</p>
      <Button size="lg" variant="primary" :disabled="!possible" @click="emit('add', line)">Añadir · {{ formatMoney(provisionalPrice(product, line), currency) }}</Button>
    </div>
  </Dialog>
</template>
