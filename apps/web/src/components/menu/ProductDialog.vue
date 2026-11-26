<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { api } from '../../api/client'
import { useAction } from '../../composables/useRequest'
import { decimalText, moneyText, parseDecimal, parseMoney } from '../../lib/money'
import Button from '../ui/Button.vue'
import Checkbox from '../ui/Checkbox.vue'
import ConfirmDialog from '../ui/ConfirmDialog.vue'
import Dialog from '../ui/Dialog.vue'
import FormField from '../ui/FormField.vue'
import Input from '../ui/Input.vue'
import Select from '../ui/Select.vue'
import Switch from '../ui/Switch.vue'
import ProductOptions from './ProductOptions.vue'
import type { Category, Product } from './types'

/** Create or edit one product. Photo, variants and add-ons need the product to exist, so they appear once it is saved. */
const props = defineProps<{ product?: Product; categoryId: string; categories: Category[]; currency: string }>()
const emit = defineEmits<{ saved: [id: string]; changed: []; close: [] }>()

// The 14 allergens of EU Regulation 1169/2011, with the keys the API accepts.
const allergens: Record<string, string> = {
  gluten: 'Gluten', crustaceans: 'Crustáceos', eggs: 'Huevos', fish: 'Pescado', peanuts: 'Cacahuetes', soy: 'Soja', milk: 'Lácteos',
  nuts: 'Frutos de cáscara', celery: 'Apio', mustard: 'Mostaza', sesame: 'Sésamo', sulphites: 'Sulfitos', lupin: 'Altramuces', molluscs: 'Moluscos',
}
const destinations = [{ value: 'kitchen', label: 'Cocina' }, { value: 'bar', label: 'Barra' }, { value: 'other', label: 'Otro' }]
const categoryOptions = computed(() => props.categories
  .filter((c) => c.is_active || c.id === props.product?.category_id)
  .map((c) => ({ value: c.id, label: c.is_active ? c.name : `${c.name} (inactiva)` })))

// The form takes the values it opened with; photo and options read the live product, which changes after each reload.
const p = props.product
const live = computed(() => props.product)
const form = reactive({
  category_id: p?.category_id ?? props.categoryId, name: p?.name ?? '', description: p?.description ?? '',
  price: p ? moneyText(p.price, props.currency) : '', tax: p?.tax_bps == null ? '' : decimalText(p.tax_bps, 2),
  destination: p?.destination ?? 'kitchen', allergens: [...(p?.allergens ?? [])], ingredients: (p?.ingredients ?? []).join(', '),
  available_from: p?.available_from ?? '', available_until: p?.available_until ?? '',
  is_available: p?.is_available ?? true, is_sold_out: p?.is_sold_out ?? false, sort_order: String(p?.sort_order ?? 0),
})
const toggleAllergen = (key: string, on: boolean) => { form.allergens = on ? [...form.allergens, key] : form.allergens.filter((a) => a !== key) }

const { busy, error, invalid, run, reset } = useAction()
const confirming = ref(false)

async function save() {
  let id = p?.id
  const ok = await run(async () => {
    const json = {
      // Sent only when it changes: the server refuses an inactive category even if the product already sits there.
      ...(form.category_id !== p?.category_id ? { category_id: form.category_id } : {}),
      name: form.name, description: form.description.trim() || null,
      // An unreadable number goes as typed, so the server rejects it and marks the field.
      price: parseMoney(form.price, props.currency) ?? form.price,
      tax_bps: form.tax.trim() === '' ? null : (parseDecimal(form.tax, 2) ?? form.tax),
      destination: form.destination, allergens: form.allergens,
      ingredients: form.ingredients.split(',').map((i) => i.trim()).filter(Boolean),
      available_from: form.available_from || null, available_until: form.available_until || null,
      is_available: form.is_available, is_sold_out: form.is_sold_out, sort_order: Number(form.sort_order),
    }
    id = (await api<{ data: Product }>(p ? `/products/${p.id}` : '/products', { method: p ? 'PATCH' : 'POST', json })).data.id
  }, 'Producto guardado.')
  if (ok) emit('saved', id!)
}

const maxImageBytes = 2 * 1024 * 1024
async function upload(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = '' // so choosing the same file again fires change
  if (!file) return
  if (file.size > maxImageBytes) { reset(); error.value = 'La foto no puede pasar de 2 MB.'; return }
  const body = new FormData()
  body.append('image', file)
  if (await run(() => api(`/products/${p!.id}/image`, { method: 'POST', body }), 'Foto actualizada.')) emit('changed')
}
async function removeImage() {
  if (await run(() => api(`/products/${p!.id}/image`, { method: 'DELETE' }), 'Foto quitada.')) emit('changed')
}
async function remove() {
  if (!(await run(() => api(`/products/${p!.id}`, { method: 'DELETE' }), 'Producto eliminado.'))) return
  confirming.value = false
  emit('changed')
  emit('close')
}
</script>

<template>
  <Dialog open :title="p ? `Editar ${p.name}` : 'Nuevo producto'" @update:open="emit('close')">
    <form class="flex flex-col gap-4 pb-4" novalidate @submit.prevent="save">
      <FormField v-slot="f" label="Nombre" :error="invalid.name" required><Input v-bind="f" v-model="form.name" maxlength="120" /></FormField>
      <FormField v-slot="f" label="Categoría" :error="invalid.category_id" required><Select v-bind="f" v-model="form.category_id" :options="categoryOptions" /></FormField>
      <FormField v-slot="f" label="Descripción" :error="invalid.description"><Input v-bind="f" v-model="form.description" maxlength="1000" /></FormField>
      <FormField v-slot="f" :label="`Precio (${currency})`" :error="invalid.price" required><Input v-bind="f" v-model="form.price" inputmode="decimal" /></FormField>
      <FormField v-slot="f" label="Impuesto (%)" hint="Vacío: el del restaurante." :error="invalid.tax_bps"><Input v-bind="f" v-model="form.tax" inputmode="decimal" /></FormField>
      <FormField v-slot="f" label="Se prepara en" :error="invalid.destination"><Select v-bind="f" v-model="form.destination" :options="destinations" /></FormField>
      <FormField v-slot="f" label="Ingredientes" hint="Separados por comas." :error="invalid.ingredients"><Input v-bind="f" v-model="form.ingredients" /></FormField>
      <fieldset class="grid grid-cols-2 gap-x-3">
        <legend class="text-sm font-medium">Alérgenos</legend>
        <Checkbox v-for="(label, key) in allergens" :key="key" :model-value="form.allergens.includes(key)" :label="label" @update:model-value="toggleAllergen(key, $event)" />
      </fieldset>
      <div class="grid grid-cols-2 gap-3">
        <FormField v-slot="f" label="Disponible desde" :error="invalid.available_from"><Input v-bind="f" v-model="form.available_from" type="time" /></FormField>
        <FormField v-slot="f" label="Disponible hasta" :error="invalid.available_until"><Input v-bind="f" v-model="form.available_until" type="time" /></FormField>
      </div>
      <FormField v-slot="f" label="Orden" :error="invalid.sort_order"><Input v-bind="f" v-model="form.sort_order" inputmode="numeric" /></FormField>
      <div>
        <Switch v-model="form.is_available" label="En la carta" />
        <Switch v-model="form.is_sold_out" label="Agotado" />
      </div>
      <p v-if="error && !confirming" role="alert" class="text-sm text-danger">{{ error }}</p>
      <Button type="submit" variant="primary" :loading="busy">Guardar producto</Button>
    </form>

    <div v-if="live && p" class="flex flex-col gap-4">
      <section class="border-t border-border pt-3" aria-label="Foto">
        <h3 class="font-semibold">Foto</h3>
        <img v-if="live!.image_url" :src="live!.image_url!" :alt="`Foto de ${p.name}`" class="my-2 max-h-40 rounded-md" />
        <label class="block text-sm">Subir JPEG, PNG o WebP (máximo 2 MB)
          <input type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full" :disabled="busy" @change="upload" />
        </label>
        <Button v-if="live!.image_url" size="sm" variant="tertiary" :loading="busy" @click="removeImage">Quitar foto</Button>
      </section>
      <ProductOptions :product="live!" kind="variants" :currency="currency" @changed="emit('changed')" />
      <ProductOptions :product="live!" kind="addons" :currency="currency" @changed="emit('changed')" />
      <Button variant="danger" class="self-start" @click="reset(); confirming = true">Eliminar producto</Button>
      <ConfirmDialog v-model:open="confirming" :title="`¿Eliminar ${p.name}?`" :description="error || 'Desaparece de la carta. Los pedidos anteriores lo conservan.'" confirm-label="Eliminar" :loading="busy" @confirm="remove" />
    </div>
  </Dialog>
</template>
