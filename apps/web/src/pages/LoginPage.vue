<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError } from '../api/client'
import Button from '../components/ui/Button.vue'
import Card from '../components/ui/Card.vue'
import FormField from '../components/ui/FormField.vue'
import Input from '../components/ui/Input.vue'
import Logo from '../components/ui/Logo.vue'
import RadioGroup from '../components/ui/RadioGroup.vue'
import { safeRedirect } from '../router'
import { useSession, type Restaurant } from '../stores/session'

const session = useSession()
const router = useRouter()
const route = useRoute()
const email = ref('')
const password = ref('')
const restaurants = ref<Restaurant[]>([])
const restaurantId = ref<string>()
const loading = ref(false)
const error = ref('')

function explain(e: unknown) {
  if (!(e instanceof ApiError)) return 'No se pudo conectar. Revisa tu conexión e inténtalo de nuevo.'
  if (e.status === 401) return 'Correo o contraseña incorrectos.' // the same text for unknown accounts: no enumeration
  if (e.status === 429) return 'Demasiados intentos. Espera un minuto.'
  if (e.status === 422) return 'Revisa el correo y la contraseña.'
  return e.message
}

async function submit() {
  error.value = ''
  loading.value = true
  try {
    const result = await session.login(email.value.trim(), password.value, restaurantId.value)
    if (result.status === 'choose') {
      restaurants.value = result.restaurants
      restaurantId.value = result.restaurants[0]?.id
      return
    }
    await router.replace(safeRedirect(route.query.redirect))
  } catch (e) {
    error.value = explain(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="grid min-h-screen place-items-center p-4">
    <Card class="w-full max-w-sm">
      <div class="mb-6 flex flex-col items-center gap-2"><Logo class="size-12" /><h1 class="text-xl font-semibold">Entrar en Mestaro</h1></div>
      <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <template v-if="restaurants.length === 0">
          <FormField v-slot="f" label="Correo" required><Input v-bind="f" v-model="email" type="email" autocomplete="username" required /></FormField>
          <FormField v-slot="f" label="Contraseña" required><Input v-bind="f" v-model="password" type="password" autocomplete="current-password" required /></FormField>
        </template>
        <RadioGroup v-else v-model="restaurantId" legend="¿En qué restaurante trabajas hoy?" :options="restaurants.map((r) => ({ value: r.id, label: r.name }))" />
        <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
        <Button type="submit" variant="primary" size="lg" :loading="loading">{{ restaurants.length ? 'Continuar' : 'Entrar' }}</Button>
      </form>
    </Card>
  </main>
</template>
