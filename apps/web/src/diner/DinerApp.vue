<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ApiError, api } from '../api/client'
import { explain } from '../api/errors'
import PageState from '../components/PageState.vue'
import Button from '../components/ui/Button.vue'
import ErrorState from '../components/ui/ErrorState.vue'
import FormField from '../components/ui/FormField.vue'
import Input from '../components/ui/Input.vue'
import Logo from '../components/ui/Logo.vue'
import { useAction } from '../composables/useRequest'
import TableView from './TableView.vue'

/** Behind a table's QR: open the table or join it, then stay in it with the participant's credential. */
const props = defineProps<{ qr: string }>()

const storageKey = `mestaro-diner:${props.qr}`
const stored = () => { try { return localStorage.getItem(storageKey) } catch { return null } }
const token = ref(stored())
function keep(value: string | null) {
  token.value = value
  try {
    if (value) localStorage.setItem(storageKey, value)
    else {
      localStorage.removeItem(storageKey)
      localStorage.removeItem(`mestaro-cart:${props.qr}`)
    }
  } catch { /* the credential then lives until reload */ }
}

type Table = { table: { label: string }; has_session: boolean }
const table = ref<Table>()
const unknown = ref(false)
const loadError = ref('')
const notice = ref('')
async function look() {
  loadError.value = ''
  try {
    table.value = (await api<{ data: Table }>(`/public/tables/${encodeURIComponent(props.qr)}`, { auth: false })).data
  } catch (e) {
    unknown.value = e instanceof ApiError && e.status === 404
    loadError.value = explain(e)
  }
}
onMounted(() => { if (!token.value) void look() })

function over() {
  keep(null)
  notice.value = 'La mesa se ha cerrado.'
  void look()
}

const form = reactive({ name: '', code: '' })
const { busy, error, run, reset } = useAction()
async function enter() {
  const name = form.name.trim()
  const display = name ? { display_name: name } : {}
  const ok = await run(async () => {
    const path = table.value!.has_session ? '/public/sessions/join' : `/public/tables/${encodeURIComponent(props.qr)}/sessions`
    const json = table.value!.has_session ? { qr_token: props.qr, join_code: form.code.trim(), ...display } : display
    try {
      const { data } = await api<{ data: { participant_token: string } }>(path, { method: 'POST', auth: false, json })
      notice.value = ''
      keep(data.participant_token)
    } catch (e) {
      // Someone at the table opened it first: show the code field.
      if (e instanceof ApiError && e.code === 'SESSION_EXISTS') await look()
      throw e
    }
  })
  if (ok) reset()
}
</script>

<template>
  <header class="border-b border-border bg-surface">
    <div class="mx-auto flex max-w-xl items-center gap-2 px-4 py-2"><Logo /><span class="font-semibold">Mestaro</span></div>
  </header>
  <main class="mx-auto max-w-xl p-4">
    <TableView v-if="token" :qr="qr" :token="token" @over="over" />
    <ErrorState v-else-if="unknown" title="Este QR no es válido" cause="Escanea el código que está en tu mesa o avisa al camarero." />
    <PageState v-else :ready="table !== undefined" :error="loadError" @retry="look">
      <h1 class="text-xl font-semibold">{{ table!.table.label }}</h1>
      <p v-if="notice" role="status" class="mt-2 text-warning">{{ notice }}</p>
      <p class="mt-2 text-muted">{{ table!.has_session ? 'La mesa ya está abierta. Pide el código a quien la abrió.' : 'Abre la mesa para ver la carta y pedir.' }}</p>
      <form class="mt-4 flex flex-col gap-4" novalidate @submit.prevent="enter">
        <FormField v-if="table!.has_session" v-slot="f" label="Código de la mesa" required>
          <Input v-bind="f" v-model="form.code" maxlength="6" autocomplete="off" autocapitalize="characters" class="font-mono tracking-widest uppercase" />
        </FormField>
        <FormField v-slot="f" label="Tu nombre (opcional)"><Input v-bind="f" v-model="form.name" maxlength="40" autocomplete="given-name" /></FormField>
        <p v-if="error" role="alert" class="text-danger">{{ error }}</p>
        <Button type="submit" size="lg" variant="primary" :loading="busy" :disabled="table!.has_session && form.code.trim().length !== 6">
          {{ table!.has_session ? 'Unirme' : 'Abrir mesa' }}
        </Button>
      </form>
    </PageState>
  </main>
</template>
