<script setup lang="ts">
import { reactive } from 'vue'
import { api } from '../api/client'
import PageState from '../components/PageState.vue'
import Button from '../components/ui/Button.vue'
import Card from '../components/ui/Card.vue'
import FormField from '../components/ui/FormField.vue'
import Input from '../components/ui/Input.vue'
import Switch from '../components/ui/Switch.vue'
import { useAction, useLoad } from '../composables/useRequest'
import { decimalText, parseDecimal } from '../lib/money'

type Range = { open: string; close: string }
type Settings = {
  currency: string; timezone: string; locale: string; opening_hours: Record<string, Range[]> | null
  auto_accept_orders: boolean; require_waiter_approval_for_session: boolean; prices_include_tax: boolean
  default_tax_bps: number; join_code_reuse_cooldown_minutes: number; order_alert_threshold_seconds: number
}

const days = [['mon', 'Lunes'], ['tue', 'Martes'], ['wed', 'Miércoles'], ['thu', 'Jueves'], ['fri', 'Viernes'], ['sat', 'Sábado'], ['sun', 'Domingo']] as const
const timezones = Intl.supportedValuesOf('timeZone')

const form = reactive({
  currency: '', timezone: '', locale: '', auto_accept_orders: false, require_waiter_approval_for_session: true, prices_include_tax: true,
  tax: '', cooldown: '', threshold: '', hours: {} as Record<string, Range[]>,
})
function fill(s: Settings) {
  Object.assign(form, {
    currency: s.currency, timezone: s.timezone, locale: s.locale, auto_accept_orders: s.auto_accept_orders,
    require_waiter_approval_for_session: s.require_waiter_approval_for_session, prices_include_tax: s.prices_include_tax,
    tax: decimalText(s.default_tax_bps, 2), cooldown: String(s.join_code_reuse_cooldown_minutes), threshold: String(s.order_alert_threshold_seconds),
    hours: Object.fromEntries(days.map(([d]) => [d, (s.opening_hours?.[d] ?? []).map((r) => ({ ...r }))])),
  })
}

const { data, error: loadError, load } = useLoad(async () => {
  const settings = (await api<{ data: Settings }>('/settings')).data
  fill(settings)
  return settings
})
const { busy, error, invalid, run } = useAction()

async function save() {
  const tax = parseDecimal(form.tax, 2)
  const ranges = Object.entries(form.hours).filter(([, list]) => list.length > 0)
  await run(async () => {
    const json = {
      currency: form.currency.trim().toUpperCase(), timezone: form.timezone, locale: form.locale,
      auto_accept_orders: form.auto_accept_orders, require_waiter_approval_for_session: form.require_waiter_approval_for_session,
      prices_include_tax: form.prices_include_tax,
      // An unreadable number goes as typed, so the server rejects it and marks the field.
      default_tax_bps: tax ?? form.tax,
      join_code_reuse_cooldown_minutes: Number(form.cooldown), order_alert_threshold_seconds: Number(form.threshold),
      opening_hours: ranges.length ? Object.fromEntries(ranges) : null,
    }
    fill((await api<{ data: Settings }>('/settings', { method: 'PATCH', json })).data)
  }, 'Ajustes guardados.')
}
const hoursError = () => Object.keys(invalid.value).some((k) => k.startsWith('opening_hours'))
</script>

<template>
  <h1 class="text-xl font-semibold">Ajustes</h1>
  <PageState :ready="data !== undefined" :error="loadError" @retry="load">
    <form class="mt-4 flex flex-col gap-4" novalidate @submit.prevent="save">
      <Card class="grid gap-4 sm:grid-cols-2">
        <FormField v-slot="f" label="Moneda" hint="Código de 3 letras, por ejemplo EUR." :error="invalid.currency" required><Input v-bind="f" v-model="form.currency" maxlength="3" /></FormField>
        <FormField v-slot="f" label="Zona horaria" :error="invalid.timezone" required><Input v-bind="f" v-model="form.timezone" list="timezones" /></FormField>
        <datalist id="timezones"><option v-for="z in timezones" :key="z" :value="z" /></datalist>
        <FormField v-slot="f" label="Idioma y región" hint="Por ejemplo es o es-ES." :error="invalid.locale" required><Input v-bind="f" v-model="form.locale" /></FormField>
        <FormField v-slot="f" label="Impuesto por defecto (%)" :error="invalid.default_tax_bps" required><Input v-bind="f" v-model="form.tax" inputmode="decimal" /></FormField>
        <FormField v-slot="f" label="Espera para reutilizar un código de mesa (minutos)" :error="invalid.join_code_reuse_cooldown_minutes" required><Input v-bind="f" v-model="form.cooldown" inputmode="numeric" /></FormField>
        <FormField v-slot="f" label="Aviso de pedido sin atender (segundos)" :error="invalid.order_alert_threshold_seconds" required><Input v-bind="f" v-model="form.threshold" inputmode="numeric" /></FormField>
        <div class="sm:col-span-2">
          <Switch v-model="form.prices_include_tax" label="Los precios incluyen impuestos" />
          <Switch v-model="form.require_waiter_approval_for_session" label="Un camarero aprueba cada mesa nueva" />
          <Switch v-model="form.auto_accept_orders" label="Aceptar los pedidos automáticamente" />
        </div>
      </Card>
      <Card>
        <h2 class="text-lg font-semibold">Horario de apertura</h2>
        <fieldset v-for="[day, name] in days" :key="day" class="mt-3 flex min-w-0 flex-wrap items-center gap-2 border-b border-border pb-3">
          <legend class="float-left mr-2 w-24 font-medium">{{ name }}</legend>
          <span v-if="!form.hours[day]?.length" class="text-muted">Sin horario</span>
          <div v-for="(range, i) in form.hours[day]" :key="i" class="flex flex-wrap items-center gap-1">
            <Input v-model="range.open" type="time" class="w-28" :aria-label="`${name}, apertura ${i + 1}`" />
            <span aria-hidden="true">–</span>
            <Input v-model="range.close" type="time" class="w-28" :aria-label="`${name}, cierre ${i + 1}`" />
            <Button size="sm" variant="tertiary" :aria-label="`Quitar el tramo ${i + 1} del ${name.toLowerCase()}`" @click="form.hours[day].splice(i, 1)">Quitar</Button>
          </div>
          <Button v-if="(form.hours[day]?.length ?? 0) < 4" size="sm" :aria-label="`Añadir tramo el ${name.toLowerCase()}`" @click="form.hours[day].push({ open: '12:00', close: '16:00' })">Añadir tramo</Button>
        </fieldset>
        <p v-if="hoursError()" role="alert" class="mt-2 text-sm text-danger">Revisa las horas: cada tramo necesita apertura y cierre.</p>
      </Card>
      <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
      <Button type="submit" variant="primary" class="self-start" :loading="busy">Guardar ajustes</Button>
    </form>
  </PageState>
</template>
