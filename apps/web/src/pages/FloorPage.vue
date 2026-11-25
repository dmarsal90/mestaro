<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { api } from '../api/client'
import PageState from '../components/PageState.vue'
import Badge from '../components/ui/Badge.vue'
import Button from '../components/ui/Button.vue'
import Card from '../components/ui/Card.vue'
import ConfirmDialog from '../components/ui/ConfirmDialog.vue'
import Dialog from '../components/ui/Dialog.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import FormField from '../components/ui/FormField.vue'
import Input from '../components/ui/Input.vue'
import Select from '../components/ui/Select.vue'
import Switch from '../components/ui/Switch.vue'
import { useAction, useLoad } from '../composables/useRequest'

type Zone = { id: string; name: string; sort_order: number; is_active: boolean }
type Table = { id: string; zone_id: string; label: string; capacity: number; is_active: boolean }

const { data, error: loadError, load } = useLoad(async () => {
  const [zones, tables] = await Promise.all([api<{ data: Zone[] }>('/zones'), api<{ data: Table[] }>('/tables')])
  return { zones: zones.data, tables: tables.data }
})
const { busy, error, invalid, run, reset } = useAction()

const zoneForm = ref<{ id?: string } | null>(null)
const zone = reactive({ name: '', sort_order: '0', is_active: true })
const tableForm = ref<{ id?: string; zone_id?: string } | null>(null)
const table = reactive({ zone_id: '', label: '', capacity: '4', is_active: true })
const rotating = ref<Table | null>(null)

// A table cannot move to an inactive zone, but the one it is already in must still be shown.
const zoneOptions = computed(() => (data.value?.zones ?? [])
  .filter((z) => z.is_active || z.id === tableForm.value?.zone_id)
  .map((z) => ({ value: z.id, label: z.is_active ? z.name : `${z.name} (inactiva)` })))
const tablesOf = (zoneId: string) => data.value!.tables.filter((t) => t.zone_id === zoneId)

function openZone(z?: Zone) {
  reset()
  Object.assign(zone, { name: z?.name ?? '', sort_order: String(z?.sort_order ?? 0), is_active: z?.is_active ?? true })
  zoneForm.value = { id: z?.id }
}
function openTable(zoneId: string, t?: Table) {
  reset()
  Object.assign(table, { zone_id: zoneId, label: t?.label ?? '', capacity: String(t?.capacity ?? 4), is_active: t?.is_active ?? true })
  tableForm.value = { id: t?.id, zone_id: t?.zone_id }
}

async function saveZone() {
  const id = zoneForm.value!.id
  const json = { name: zone.name, sort_order: Number(zone.sort_order), ...(id ? { is_active: zone.is_active } : {}) }
  if (!(await run(() => api(id ? `/zones/${id}` : '/zones', { method: id ? 'PATCH' : 'POST', json }), 'Zona guardada.'))) return
  zoneForm.value = null
  await load()
}
async function saveTable() {
  const { id, zone_id: before } = tableForm.value!
  // zone_id is sent only when it changes: the server refuses an inactive zone even if the table already sits there.
  const json = {
    label: table.label, capacity: Number(table.capacity),
    ...(!id || table.zone_id !== before ? { zone_id: table.zone_id } : {}),
    ...(id ? { is_active: table.is_active } : {}),
  }
  if (!(await run(() => api(id ? `/tables/${id}` : '/tables', { method: id ? 'PATCH' : 'POST', json }), 'Mesa guardada.'))) return
  tableForm.value = null
  await load()
}
async function rotate() {
  if (await run(() => api(`/tables/${rotating.value!.id}/qr/rotate`, { method: 'POST' }), 'QR renovado. El anterior ya no sirve.')) rotating.value = null
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-2">
    <h1 class="text-xl font-semibold">Salón</h1>
    <Button variant="primary" @click="openZone()">Nueva zona</Button>
  </div>
  <PageState :ready="data !== undefined" :error="loadError" @retry="load">
    <EmptyState v-if="data!.zones.length === 0" title="Aún no hay zonas" description="Crea una zona (sala, terraza…) para añadir mesas." />
    <Card v-for="z in data!.zones" :key="z.id" class="mt-4">
      <div class="flex flex-wrap items-center gap-2">
        <h2 class="text-lg font-semibold">{{ z.name }}</h2>
        <Badge v-if="!z.is_active">Inactiva</Badge>
        <div class="ml-auto flex gap-2">
          <Button size="sm" :aria-label="`Editar la zona ${z.name}`" @click="openZone(z)">Editar zona</Button>
          <Button v-if="z.is_active" size="sm" :aria-label="`Añadir mesa en ${z.name}`" @click="openTable(z.id)">Añadir mesa</Button>
        </div>
      </div>
      <p v-if="tablesOf(z.id).length === 0" class="mt-3 text-muted">Sin mesas.</p>
      <ul v-else class="mt-3 divide-y divide-border">
        <li v-for="t in tablesOf(z.id)" :key="t.id" class="flex flex-wrap items-center gap-2 py-2">
          <span class="font-medium">{{ t.label }}</span>
          <span class="text-sm text-muted">{{ t.capacity }} plazas</span>
          <Badge v-if="!t.is_active">Inactiva</Badge>
          <div class="ml-auto flex gap-2">
            <Button size="sm" :aria-label="`Editar la mesa ${t.label}`" @click="openTable(z.id, t)">Editar</Button>
            <Button size="sm" variant="tertiary" :aria-label="`Renovar el QR de la mesa ${t.label}`" @click="reset(); rotating = t">Renovar QR</Button>
          </div>
        </li>
      </ul>
    </Card>
  </PageState>

  <Dialog :open="zoneForm !== null" :title="zoneForm?.id ? 'Editar zona' : 'Nueva zona'" @update:open="zoneForm = null">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="saveZone">
      <FormField v-slot="f" label="Nombre" :error="invalid.name" required><Input v-bind="f" v-model="zone.name" maxlength="80" /></FormField>
      <FormField v-slot="f" label="Orden" :error="invalid.sort_order"><Input v-bind="f" v-model="zone.sort_order" inputmode="numeric" /></FormField>
      <Switch v-if="zoneForm?.id" v-model="zone.is_active" label="Activa" />
      <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
      <Button type="submit" variant="primary" :loading="busy">Guardar</Button>
    </form>
  </Dialog>

  <Dialog :open="tableForm !== null" :title="tableForm?.id ? 'Editar mesa' : 'Nueva mesa'" @update:open="tableForm = null">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="saveTable">
      <FormField v-slot="f" label="Nombre o número" :error="invalid.label" required><Input v-bind="f" v-model="table.label" maxlength="40" /></FormField>
      <FormField v-slot="f" label="Plazas" :error="invalid.capacity" required><Input v-bind="f" v-model="table.capacity" inputmode="numeric" /></FormField>
      <FormField v-slot="f" label="Zona" :error="invalid.zone_id" required><Select v-bind="f" v-model="table.zone_id" :options="zoneOptions" /></FormField>
      <Switch v-if="tableForm?.id" v-model="table.is_active" label="Activa" />
      <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
      <Button type="submit" variant="primary" :loading="busy">Guardar</Button>
    </form>
  </Dialog>

  <ConfirmDialog :open="rotating !== null" :title="`¿Renovar el QR de la mesa ${rotating?.label}?`" :description="error || 'El código impreso actual dejará de funcionar de inmediato.'" confirm-label="Renovar QR" :loading="busy" @update:open="rotating = null" @confirm="rotate" />
</template>
