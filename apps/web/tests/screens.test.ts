import { DOMWrapper, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Component } from 'vue'
import { createMemoryHistory } from 'vue-router'
import { formatMoney, moneyText, parseDecimal, parseMoney } from '../src/lib/money'
import AcceptInvitationPage from '../src/pages/AcceptInvitationPage.vue'
import FloorPage from '../src/pages/FloorPage.vue'
import SettingsPage from '../src/pages/SettingsPage.vue'
import StaffPage from '../src/pages/StaffPage.vue'
import { createAppRouter } from '../src/router'
import { expectAccessible } from './a11y'

type Reply = { status?: number; body?: unknown }
let calls: Array<{ key: string; body: unknown }> = []

function stubFetch(routes: Record<string, Reply>) {
  calls = []
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const key = `${init.method ?? 'GET'} ${url.replace('/api/v1', '')}`
    calls.push({ key, body: typeof init.body === 'string' ? JSON.parse(init.body) : init.body })
    const { status = 200, body } = routes[key] ?? { status: 500, body: { error: { code: 'UNSTUBBED', message: key } } }
    return new Response(body === undefined ? null : JSON.stringify(body), { status })
  }))
}
const sent = (key: string) => calls.filter((c) => c.key === key).map((c) => c.body)

let wrapper: VueWrapper
async function open(page: Component, path = '/') {
  const router = createAppRouter(createMemoryHistory())
  await router.push(path)
  wrapper = mount(page, { attachTo: document.body, global: { plugins: [router] } })
  await flushPromises()
  return { w: wrapper, router }
}
// Dialogs are teleported to <body>, outside the wrapper.
const body = () => new DOMWrapper(document.body)
const click = async (name: string) => {
  // The last match: dialogs are appended after the page, so theirs wins over a row button with the same text.
  const button = body().findAll('button').filter((b) => b.attributes('aria-label') === name || b.text() === name).at(-1)
  expect(button, `button "${name}"`).toBeTruthy()
  await button!.trigger('click')
  await flushPromises()
}
const field = (label: string) => {
  const found = body().findAll('label').find((l) => l.text().replace(' *', '') === label)
  expect(found, `field "${label}"`).toBeTruthy()
  return body().find(`[id="${found!.attributes('for')}"]`)
}
const submit = async (label: string) => {
  await field(label).element.closest('form')!.dispatchEvent(new Event('submit', { cancelable: true }))
  await flushPromises()
}

beforeEach(() => { setActivePinia(createPinia()); sessionStorage.clear() })
afterEach(() => { wrapper?.unmount(); vi.unstubAllGlobals(); document.body.innerHTML = '' })

describe('money', () => {
  it('converts text to minor units without floating point errors, per currency', () => {
    expect(parseMoney('19,99', 'EUR')).toBe(1999)
    expect(parseMoney(' 1.5 ', 'EUR')).toBe(150)
    expect(parseMoney('500', 'JPY')).toBe(500)
    expect(parseDecimal('10', 2)).toBe(1000)
    for (const bad of ['', '-1', '1,999', 'abc', '1.000,00', '1e3']) expect(parseMoney(bad, 'EUR'), bad).toBeNull()
    expect(parseMoney('5,5', 'JPY')).toBeNull()
    expect(moneyText(1999, 'EUR')).toBe('19,99')
    expect(formatMoney(1999, 'EUR')).toContain('19,99')
  })
})

const settings = {
  currency: 'EUR', timezone: 'Europe/Madrid', locale: 'es', opening_hours: null, auto_accept_orders: false,
  require_waiter_approval_for_session: true, prices_include_tax: true, default_tax_bps: 1000,
  join_code_reuse_cooldown_minutes: 15, order_alert_threshold_seconds: 300, preferences: null,
}
const floorRoutes = {
  'GET /zones': { body: { data: [{ id: 'z1', name: 'Terraza', sort_order: 0, is_active: true }] } },
  'GET /tables': { body: { data: [{ id: 't1', zone_id: 'z1', label: 'M1', capacity: 4, is_active: true }] } },
}

describe('FloorPage', () => {
  it('lists zones and tables accessibly', async () => {
    stubFetch(floorRoutes)
    const { w } = await open(FloorPage)
    expect(w.text()).toContain('M1')
    await expectAccessible(w.element)
  })
  it('rotates a QR only after confirmation', async () => {
    stubFetch({ ...floorRoutes, 'POST /tables/t1/qr/rotate': { body: { data: {} } } })
    await open(FloorPage)
    await click('Renovar el QR de la mesa M1')
    expect(sent('POST /tables/t1/qr/rotate')).toHaveLength(0)
    await click('Renovar QR')
    expect(sent('POST /tables/t1/qr/rotate')).toHaveLength(1)
  })
  it('shows why a table could not be saved', async () => {
    stubFetch({ ...floorRoutes, 'POST /tables': { status: 409, body: { error: { code: 'TABLE_LABEL_TAKEN', message: 'x' } } } })
    await open(FloorPage)
    await click('Añadir mesa en Terraza')
    await field('Nombre o número').setValue('M1')
    await submit('Nombre o número')
    expect(sent('POST /tables')).toEqual([{ label: 'M1', capacity: 4, zone_id: 'z1' }])
    expect(document.body.textContent).toContain('Ya hay una mesa con ese nombre.')
  })
})

describe('StaffPage', () => {
  const routes = { 'GET /staff': { body: { data: [{ id: 'u2', name: 'Leo', email: 'leo@example.com', role: 'waiter', is_active: true }] } } }
  it('lists the team accessibly', async () => {
    stubFetch(routes)
    const { w } = await open(StaffPage)
    expect(w.text()).toContain('Camarero')
    await expectAccessible(w.element)
  })
  it('invites a person', async () => {
    stubFetch({ ...routes, 'POST /staff': { status: 201, body: null } })
    await open(StaffPage)
    await click('Invitar persona')
    await field('Nombre').setValue('Mar')
    await field('Correo').setValue('mar@example.com')
    await submit('Correo')
    expect(sent('POST /staff')).toEqual([{ name: 'Mar', email: 'mar@example.com', role: 'waiter' }])
  })
})

describe('SettingsPage', () => {
  it('is accessible and sends tax as basis points and hours only for days with ranges', async () => {
    stubFetch({ 'GET /settings': { body: { data: settings } }, 'PATCH /settings': { body: { data: settings } } })
    const { w } = await open(SettingsPage)
    await expectAccessible(w.element)
    await field('Impuesto por defecto (%)').setValue('21')
    await click('Añadir tramo el lunes')
    await submit('Moneda')
    const [json] = sent('PATCH /settings') as Array<Record<string, unknown>>
    expect(json.default_tax_bps).toBe(2100)
    expect(json.opening_hours).toEqual({ mon: [{ open: '12:00', close: '16:00' }] })
  })
})

describe('AcceptInvitationPage', () => {
  it('sends the token from the link and removes it from the address bar', async () => {
    stubFetch({ 'POST /auth/accept-invitation': { status: 204 } })
    const { w, router } = await open(AcceptInvitationPage, '/accept-invitation?token=abc')
    expect(router.currentRoute.value.query).toEqual({})
    await expectAccessible(w.element)
    await field('Contraseña').setValue('una clave muy larga')
    await submit('Contraseña')
    expect(sent('POST /auth/accept-invitation')).toEqual([{ token: 'abc', password: 'una clave muy larga' }])
    expect(w.text()).toContain('Invitación aceptada.')
  })
  it('says when the link is incomplete', async () => {
    const { w } = await open(AcceptInvitationPage, '/accept-invitation')
    expect(w.find('[role=alert]').text()).toContain('incompleto')
  })
})
