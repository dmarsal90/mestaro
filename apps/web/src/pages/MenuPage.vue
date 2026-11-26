<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { api } from '../api/client'
import ProductDialog from '../components/menu/ProductDialog.vue'
import type { Category, Product } from '../components/menu/types'
import PageState from '../components/PageState.vue'
import Badge from '../components/ui/Badge.vue'
import Button from '../components/ui/Button.vue'
import Card from '../components/ui/Card.vue'
import Dialog from '../components/ui/Dialog.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import FormField from '../components/ui/FormField.vue'
import Input from '../components/ui/Input.vue'
import Switch from '../components/ui/Switch.vue'
import { useAction, useLoad } from '../composables/useRequest'
import { formatMoney } from '../lib/money'

const { data, error: loadError, load } = useLoad(async () => {
  const [categories, products, settings] = await Promise.all([
    api<{ data: Category[] }>('/categories'), api<{ data: Product[] }>('/products'), api<{ data: { currency: string } }>('/settings'),
  ])
  return { categories: categories.data, products: products.data, currency: settings.data.currency }
})
const { busy, error, invalid, run, reset } = useAction()

const categoryForm = ref<{ id?: string } | null>(null)
const category = reactive({ name: '', description: '', sort_order: '0', is_active: true })
function openCategory(c?: Category) {
  reset()
  Object.assign(category, { name: c?.name ?? '', description: c?.description ?? '', sort_order: String(c?.sort_order ?? 0), is_active: c?.is_active ?? true })
  categoryForm.value = { id: c?.id }
}
async function saveCategory() {
  const id = categoryForm.value!.id
  const json = { name: category.name, description: category.description.trim() || null, sort_order: Number(category.sort_order), ...(id ? { is_active: category.is_active } : {}) }
  if (!(await run(() => api(id ? `/categories/${id}` : '/categories', { method: id ? 'PATCH' : 'POST', json }), 'Categoría guardada.'))) return
  categoryForm.value = null
  await load()
}

const productsOf = (categoryId: string) => data.value!.products.filter((p) => p.category_id === categoryId)
// The open product is looked up by id, so photo and option changes show up after each reload.
const open = ref<{ id?: string; categoryId: string } | null>(null)
const openProduct = computed(() => data.value?.products.find((p) => p.id === open.value?.id))
async function saved(id: string) {
  await load()
  if (open.value) open.value.id = id
}

async function toggleSoldOut(p: Product) {
  if (await run(() => api(`/products/${p.id}`, { method: 'PATCH', json: { is_sold_out: !p.is_sold_out } }))) await load()
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-2">
    <h1 class="text-xl font-semibold">Carta</h1>
    <Button variant="primary" @click="openCategory()">Nueva categoría</Button>
  </div>
  <PageState :ready="data !== undefined" :error="loadError" @retry="load">
    <p v-if="error && !categoryForm" role="alert" class="mt-2 text-danger">{{ error }}</p>
    <EmptyState v-if="data!.categories.length === 0" title="La carta está vacía" description="Crea una categoría (entrantes, bebidas…) para añadir productos." />
    <Card v-for="c in data!.categories" :key="c.id" class="mt-4">
      <div class="flex flex-wrap items-center gap-2">
        <h2 class="text-lg font-semibold">{{ c.name }}</h2>
        <Badge v-if="!c.is_active">Inactiva</Badge>
        <div class="ml-auto flex gap-2">
          <Button size="sm" :aria-label="`Editar la categoría ${c.name}`" @click="openCategory(c)">Editar categoría</Button>
          <Button v-if="c.is_active" size="sm" :aria-label="`Añadir producto en ${c.name}`" @click="open = { categoryId: c.id }">Añadir producto</Button>
        </div>
      </div>
      <p v-if="productsOf(c.id).length === 0" class="mt-3 text-muted">Sin productos.</p>
      <ul v-else class="mt-3 divide-y divide-border">
        <li v-for="p in productsOf(c.id)" :key="p.id" class="flex flex-wrap items-center gap-2 py-2">
          <img v-if="p.image_url" :src="p.image_url" alt="" class="size-10 rounded-md object-cover" />
          <span class="font-medium">{{ p.name }}</span>
          <span class="text-sm text-muted">{{ formatMoney(p.price, data!.currency) }}</span>
          <Badge v-if="p.is_sold_out" tone="warning">Agotado</Badge>
          <Badge v-if="!p.is_available">Fuera de carta</Badge>
          <div class="ml-auto flex gap-2">
            <Button size="sm" variant="tertiary" :aria-label="p.is_sold_out ? `${p.name}: vuelve a haber` : `${p.name}: marcar agotado`" :disabled="busy" @click="toggleSoldOut(p)">{{ p.is_sold_out ? 'Vuelve a haber' : 'Marcar agotado' }}</Button>
            <Button size="sm" :aria-label="`Editar ${p.name}`" @click="open = { id: p.id, categoryId: p.category_id }">Editar</Button>
          </div>
        </li>
      </ul>
    </Card>
  </PageState>

  <ProductDialog v-if="open" :key="open.id ?? 'new'" :product="openProduct" :category-id="open.categoryId" :categories="data!.categories" :currency="data!.currency" @saved="saved" @changed="load" @close="open = null" />

  <Dialog :open="categoryForm !== null" :title="categoryForm?.id ? 'Editar categoría' : 'Nueva categoría'" @update:open="categoryForm = null">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="saveCategory">
      <FormField v-slot="f" label="Nombre" :error="invalid.name" required><Input v-bind="f" v-model="category.name" maxlength="80" /></FormField>
      <FormField v-slot="f" label="Descripción" :error="invalid.description"><Input v-bind="f" v-model="category.description" maxlength="500" /></FormField>
      <FormField v-slot="f" label="Orden" :error="invalid.sort_order"><Input v-bind="f" v-model="category.sort_order" inputmode="numeric" /></FormField>
      <Switch v-if="categoryForm?.id" v-model="category.is_active" label="Activa" />
      <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
      <Button type="submit" variant="primary" :loading="busy">Guardar</Button>
    </form>
  </Dialog>
</template>
