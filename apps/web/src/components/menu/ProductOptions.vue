<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { api } from '../../api/client'
import { useAction } from '../../composables/useRequest'
import { formatMoney, moneyText, parseMoney } from '../../lib/money'
import Button from '../ui/Button.vue'
import ConfirmDialog from '../ui/ConfirmDialog.vue'
import FormField from '../ui/FormField.vue'
import Input from '../ui/Input.vue'
import Switch from '../ui/Switch.vue'
import type { Addon, Product, Variant } from './types'

/** Variants (price supplement, one default) or add-ons (extra with a maximum quantity) of one product. */
const props = defineProps<{ product: Product; kind: 'variants' | 'addons'; currency: string }>()
const emit = defineEmits<{ changed: [] }>()

const isVariant = props.kind === 'variants'
const title = isVariant ? 'Variantes' : 'Complementos'
const noun = isVariant ? 'variante' : 'complemento'
const items = computed<Array<Variant | Addon>>(() => props.product[props.kind])
const priceOf = (o: Variant | Addon) => ('price_delta' in o ? o.price_delta : o.price)

const { busy, error, invalid, run, reset } = useAction()
const editing = ref<{ id?: string } | null>(null)
const removing = ref<Variant | Addon | null>(null)
const form = reactive({ name: '', price: '', max_quantity: '1', is_default: false, is_available: true })

function edit(o?: Variant | Addon) {
  reset()
  Object.assign(form, {
    name: o?.name ?? '', price: moneyText(o ? priceOf(o) : 0, props.currency), max_quantity: String(o && 'max_quantity' in o ? o.max_quantity : 1),
    is_default: !!(o && 'is_default' in o && o.is_default), is_available: o?.is_available ?? true,
  })
  editing.value = { id: o?.id }
}

const base = () => `/products/${props.product.id}/${props.kind}`
async function save() {
  const id = editing.value!.id
  const price = parseMoney(form.price, props.currency) ?? form.price
  const json = {
    name: form.name, is_available: form.is_available,
    ...(isVariant ? { price_delta: price, is_default: form.is_default } : { price, max_quantity: Number(form.max_quantity) }),
  }
  if (!(await run(() => api(id ? `${base()}/${id}` : base(), { method: id ? 'PATCH' : 'POST', json })))) return
  editing.value = null
  emit('changed')
}
async function remove() {
  if (!(await run(() => api(`${base()}/${removing.value!.id}`, { method: 'DELETE' })))) return
  removing.value = null
  emit('changed')
}
</script>

<template>
  <section class="border-t border-border pt-3" :aria-label="title">
    <div class="flex items-center justify-between gap-2">
      <h3 class="font-semibold">{{ title }}</h3>
      <Button size="sm" :aria-label="`Añadir ${noun}`" @click="edit()">Añadir</Button>
    </div>
    <ul class="divide-y divide-border">
      <li v-for="o in items" :key="o.id" class="flex flex-wrap items-center gap-2 py-2">
        <span>{{ o.name }}</span>
        <span class="text-sm text-muted">{{ isVariant ? '+' : '' }}{{ formatMoney(priceOf(o), currency) }}<template v-if="'is_default' in o && o.is_default">, por defecto</template><template v-if="!o.is_available">, no disponible</template></span>
        <div class="ml-auto flex gap-1">
          <Button size="sm" variant="tertiary" :aria-label="`Editar ${o.name}`" @click="edit(o)">Editar</Button>
          <Button size="sm" variant="tertiary" :aria-label="`Quitar ${o.name}`" @click="reset(); removing = o">Quitar</Button>
        </div>
      </li>
    </ul>
    <form v-if="editing" class="mt-2 flex flex-col gap-3 rounded-md border border-border p-3" novalidate @submit.prevent="save">
      <FormField v-slot="f" :label="isVariant ? 'Nombre de la variante' : 'Nombre del complemento'" :error="invalid.name" required><Input v-bind="f" v-model="form.name" maxlength="80" /></FormField>
      <FormField v-slot="f" :label="isVariant ? 'Suplemento' : 'Precio'" :error="invalid.price_delta ?? invalid.price"><Input v-bind="f" v-model="form.price" inputmode="decimal" /></FormField>
      <Switch v-if="isVariant" v-model="form.is_default" label="Variante por defecto" />
      <FormField v-else v-slot="f" label="Cantidad máxima" :error="invalid.max_quantity"><Input v-bind="f" v-model="form.max_quantity" inputmode="numeric" /></FormField>
      <Switch v-model="form.is_available" label="Disponible" />
      <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
      <div class="flex gap-2"><Button type="submit" :loading="busy">Guardar {{ noun }}</Button><Button variant="tertiary" @click="editing = null">Cancelar</Button></div>
    </form>
    <ConfirmDialog :open="removing !== null" :title="`¿Quitar ${removing?.name}?`" :description="error || undefined" confirm-label="Quitar" :loading="busy" @update:open="removing = null" @confirm="remove" />
  </section>
</template>
