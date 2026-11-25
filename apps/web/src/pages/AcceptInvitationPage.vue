<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { api } from '../api/client'
import Button from '../components/ui/Button.vue'
import Card from '../components/ui/Card.vue'
import FormField from '../components/ui/FormField.vue'
import Input from '../components/ui/Input.vue'
import Logo from '../components/ui/Logo.vue'
import { useAction } from '../composables/useRequest'

const route = useRoute()
const router = useRouter()
const token = typeof route.query.token === 'string' ? route.query.token : ''
const password = ref('')
const done = ref(false)
const { busy, error, invalid, run } = useAction()

// The token is a secret: keep it out of the address bar and the history.
onMounted(() => router.replace({ query: {} }))

async function submit() {
  done.value = await run(() => api('/auth/accept-invitation', { method: 'POST', auth: false, json: { token, password: password.value } }))
}
</script>

<template>
  <main class="grid min-h-screen place-items-center p-4">
    <Card class="w-full max-w-sm">
      <div class="mb-6 flex flex-col items-center gap-2"><Logo class="size-12" /><h1 class="text-xl font-semibold">Unirte al equipo</h1></div>
      <div v-if="done" class="flex flex-col items-center gap-4">
        <p role="status">Invitación aceptada.</p>
        <RouterLink to="/login" class="rounded-md underline underline-offset-4">Entrar en Mestaro</RouterLink>
      </div>
      <p v-else-if="!token" role="alert" class="text-danger">El enlace está incompleto. Ábrelo desde el correo de invitación.</p>
      <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <FormField v-slot="f" label="Contraseña" hint="Cuenta nueva: elige una de 12 caracteres o más. Si ya tienes cuenta, usa la actual." :error="invalid.password ? 'Debe tener 12 caracteres o más.' : undefined" required>
          <Input v-bind="f" v-model="password" type="password" autocomplete="current-password" required />
        </FormField>
        <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
        <Button type="submit" variant="primary" size="lg" :loading="busy">Aceptar invitación</Button>
      </form>
    </Card>
  </main>
</template>
