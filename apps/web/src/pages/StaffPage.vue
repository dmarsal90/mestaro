<script setup lang="ts">
import { reactive, ref } from 'vue'
import { api } from '../api/client'
import PageState from '../components/PageState.vue'
import Badge from '../components/ui/Badge.vue'
import Button from '../components/ui/Button.vue'
import DataTable from '../components/ui/DataTable.vue'
import Dialog from '../components/ui/Dialog.vue'
import FormField from '../components/ui/FormField.vue'
import Input from '../components/ui/Input.vue'
import Select from '../components/ui/Select.vue'
import Switch from '../components/ui/Switch.vue'
import { useAction, useLoad } from '../composables/useRequest'
import { useSession } from '../stores/session'

type Member = { id: string; name: string; email: string; role: string; is_active: boolean }

const roles: Record<string, string> = { restaurant_admin: 'Administrador', manager: 'Gerente', waiter: 'Camarero', kitchen: 'Cocina', bar: 'Barra' }
const roleOptions = Object.entries(roles).map(([value, label]) => ({ value, label }))
const columns = [
  { key: 'name', label: 'Nombre', sortable: true }, { key: 'email', label: 'Correo', sortable: true },
  { key: 'role', label: 'Rol', sortable: true }, { key: 'is_active', label: 'Estado' }, { key: 'id', label: 'Acciones' },
] as const

const session = useSession()
const { data, error: loadError, load } = useLoad(async () => (await api<{ data: Member[] }>('/staff')).data)
const { busy, error, invalid, run, reset } = useAction()

const inviting = ref(false)
const invitation = reactive({ name: '', email: '', role: 'waiter' })
const editing = ref<Member | null>(null)
const draft = reactive({ role: '', is_active: true })

function openInvite() {
  reset()
  Object.assign(invitation, { name: '', email: '', role: 'waiter' })
  inviting.value = true
}
function openEdit(member: Member) {
  reset()
  Object.assign(draft, { role: member.role, is_active: member.is_active })
  editing.value = member
}
async function invite() {
  if (await run(() => api('/staff', { method: 'POST', json: { ...invitation } }), 'Invitación enviada.')) inviting.value = false
}
async function save() {
  if (!(await run(() => api(`/staff/${editing.value!.id}`, { method: 'PATCH', json: { ...draft } }), 'Cambios guardados.'))) return
  editing.value = null
  await load()
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-2">
    <h1 class="text-xl font-semibold">Equipo</h1>
    <Button variant="primary" @click="openInvite">Invitar persona</Button>
  </div>
  <PageState :ready="data !== undefined" :error="loadError" @retry="load">
    <DataTable class="mt-4" caption="Personas del restaurante" :columns="[...columns]" :rows="data!">
      <template #cell-role="{ row }">{{ roles[row.role] ?? row.role }}</template>
      <template #cell-is_active="{ row }"><Badge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? 'Activo' : 'Sin acceso' }}</Badge></template>
      <template #cell-id="{ row }">
        <span v-if="row.id === session.me?.id" class="text-muted">Tú</span>
        <Button v-else size="sm" :aria-label="`Editar a ${row.name}`" @click="openEdit(row)">Editar</Button>
      </template>
    </DataTable>
  </PageState>

  <Dialog v-model:open="inviting" title="Invitar persona" description="Recibirá un correo con un enlace válido durante 7 días.">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="invite">
      <FormField v-slot="f" label="Nombre" :error="invalid.name" required><Input v-bind="f" v-model="invitation.name" maxlength="120" /></FormField>
      <FormField v-slot="f" label="Correo" :error="invalid.email" required><Input v-bind="f" v-model="invitation.email" type="email" /></FormField>
      <FormField v-slot="f" label="Rol" :error="invalid.role" required><Select v-bind="f" v-model="invitation.role" :options="roleOptions" /></FormField>
      <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
      <Button type="submit" variant="primary" :loading="busy">Enviar invitación</Button>
    </form>
  </Dialog>

  <Dialog :open="editing !== null" :title="`Editar a ${editing?.name}`" description="Al guardar se cierran sus sesiones abiertas en este restaurante." @update:open="editing = null">
    <form class="flex flex-col gap-4" @submit.prevent="save">
      <FormField v-slot="f" label="Rol" :error="invalid.role"><Select v-bind="f" v-model="draft.role" :options="roleOptions" /></FormField>
      <Switch v-model="draft.is_active" label="Puede entrar" />
      <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
      <Button type="submit" variant="primary" :loading="busy">Guardar</Button>
    </form>
  </Dialog>
</template>
