import { DOMWrapper, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { provisionalPrice } from '../src/diner/cart'
import DinerApp from '../src/diner/DinerApp.vue'
import ServicePage from '../src/pages/ServicePage.vue'
import { createAppRouter } from '../src/router'
import { useSession } from '../src/stores/session'
import { expectAccessible } from './a11y'

type Reply = { status?: number; body?: unknown } | 'offline'
let calls: Array<{ key: string; body: unknown; headers: Headers }> = []
let routes: Record<string, Reply> = {}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  calls = []
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const key = `${init.method ?? 'GET'} ${url.replace('/api/v1', '')}`
    calls.push({ key, body: typeof init.body === 'string' ? JSON.parse(init.body) : undefined, headers: new Headers(init.headers) })
    const reply = routes[key] ?? { status: 500, body: { error: { code: 'UNSTUBBED', message: key } } }
    if (reply === 'offline') throw new TypeError('offline')
    return new Response(JSON.stringify(reply.body), { status: reply.status ?? 200 })
  }))
})
let wrapper: VueWrapper
afterEach(() => { wrapper?.unmount(); vi.unstubAllGlobals(); document.body.innerHTML = '' })

const body = () => new DOMWrapper(document.body)
const click = async (name: string) => {
  const button = body().findAll('button').filter((b) => b.attributes('aria-label') === name || b.text().startsWith(name)).at(-1)
  expect(button, `button "${name}"`).toBeTruthy()
  await button!.trigger('click')
  await flushPromises()
}

const product = {
  id: 'p1', name: 'Tortilla', description: null, image_url: null, price: 850, allergens: ['eggs'], ingredients: [], orderable: true,
  variants: [{ id: 'v1', name: 'Entera', price_delta: 200, is_default: true, is_available: true }],
  addons: [{ id: 'a1', name: 'Pan', price: 50, max_quantity: 2, is_available: true }],
}
const sessionRoutes = (status = 'open', orders: unknown[] = []): Record<string, Reply> => ({
  'GET /session/menu': { body: { data: { currency: 'EUR', prices_include_tax: true, categories: [{ id: 'c1', name: 'Platos', description: null, products: [product] }] } } },
  'GET /session': { body: { data: { session: { id: 's1', status, table_label: 'Mesa 1', join_code: status === 'open' ? 'ABC234' : null }, me: { id: 'me' } } } },
  'GET /session/orders': { body: { data: orders } },
})
async function seated() {
  localStorage.setItem('mestaro-diner:qr1', 'tenant.secret')
  wrapper = mount(DinerApp, { props: { qr: 'qr1' }, attachTo: document.body })
  await flushPromises()
}

it('prices a line from the menu', () => {
  expect(provisionalPrice(product, { product_id: 'p1', variant_id: 'v1', addons: [{ addon_id: 'a1', quantity: 2 }], quantity: 3, notes: '' })).toBe((850 + 200 + 100) * 3)
})

describe('diner', () => {
  it('opens a free table and keeps the credential', async () => {
    routes = { 'GET /public/tables/qr1': { body: { data: { table: { label: 'Mesa 1' }, has_session: false } } }, 'POST /public/tables/qr1/sessions': { status: 201, body: { data: { participant_token: 'tenant.secret' } } }, ...sessionRoutes('pending_approval') }
    wrapper = mount(DinerApp, { props: { qr: 'qr1' }, attachTo: document.body })
    await flushPromises()
    await expectAccessible(wrapper.element.parentElement!)

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(localStorage.getItem('mestaro-diner:qr1')).toBe('tenant.secret')
    expect(wrapper.text()).toContain('Avisa al camarero')
    expect(calls.find((c) => c.key === 'GET /session')!.headers.get('Authorization')).toBe('Bearer tenant.secret')
  })

  it('says so when the QR is not valid', async () => {
    routes = { 'GET /public/tables/qr1': { status: 404, body: { error: { code: 'NOT_FOUND', message: '' } } } }
    wrapper = mount(DinerApp, { props: { qr: 'qr1' } })
    await flushPromises()

    expect(wrapper.text()).toContain('Este QR no es válido')
  })

  it('sends the same order with the same key after a lost connection, and empties the cart once it arrives', async () => {
    routes = { ...sessionRoutes(), 'POST /orders': 'offline' }
    await seated()
    await click('Tortilla')
    await expectAccessible(document.body)
    await click('Añadir uno: Pan')
    await click('Añadir · ')

    await click('Pedir')
    expect(wrapper.text()).toContain('No se pudo conectar')
    routes['POST /orders'] = { status: 201, body: { data: {} } }
    await click('Pedir')

    const [first, second] = calls.filter((c) => c.key === 'POST /orders')
    expect(first!.body).toEqual({ items: [{ product_id: 'p1', variant_id: 'v1', quantity: 1, addons: [{ addon_id: 'a1', quantity: 1 }] }] })
    expect(second!.headers.get('Idempotency-Key')).toBe(first!.headers.get('Idempotency-Key'))
    expect(first!.headers.get('Idempotency-Key')).toMatch(/^[0-9a-f]{32}$/)
    expect(wrapper.text()).toContain('Aún no has elegido nada')
  })

  it('keeps what is added while an order is on its way', async () => {
    let arrive!: () => void
    routes = sessionRoutes()
    await seated()
    const answer = vi.mocked(fetch).getMockImplementation()!
    vi.mocked(fetch).mockImplementation((url, init) => (init?.method === 'POST'
      ? new Promise<Response>((resolve) => { arrive = () => resolve(new Response('{"data":{}}', { status: 201 })) })
      : answer(url, init)))
    await click('Tortilla')
    await click('Añadir · ')
    await click('Pedir')

    await click('Tortilla')
    await click('Añadir · ')
    arrive()
    await flushPromises()

    expect(JSON.parse(localStorage.getItem('mestaro-cart:qr1')!).lines).toHaveLength(1)
  })

  it('marks the line the server refuses and keeps the cart', async () => {
    routes = { ...sessionRoutes(), 'POST /orders': { status: 422, body: { error: { code: 'ORDER_PRODUCT_UNAVAILABLE', message: '', details: { 'items.0': ['x'] } } } } }
    await seated()
    await click('Tortilla')
    await click('Añadir · ')
    await click('Pedir')

    expect(wrapper.text()).toContain('quítalo y elige otro')
    expect(JSON.parse(localStorage.getItem('mestaro-cart:qr1')!).lines).toHaveLength(1)
  })

  it('forgets the credential when the table is closed', async () => {
    const closed = { status: 409, body: { error: { code: 'SESSION_CLOSED', message: '' } } }
    routes = { 'GET /session/menu': closed, 'GET /session': closed, 'GET /session/orders': closed, 'GET /public/tables/qr1': { body: { data: { table: { label: 'Mesa 1' }, has_session: false } } } }
    await seated()

    expect(localStorage.getItem('mestaro-diner:qr1')).toBeNull()
    expect(wrapper.text()).toContain('La mesa se ha cerrado')
  })
})

describe('ServicePage', () => {
  const order = { id: 'o1', status: 'pending', notes: null, currency: 'EUR', total: 850, reason: null, created_at: '2026-11-26T20:00:00Z', session_id: 's1', table_label: 'Mesa 1', participant_id: 'x', participant_name: null, items: [{ id: 'i1', name: 'Tortilla', variant_name: null, quantity: 1, line_total: 850, notes: null, addons: [] }] }
  const serviceRoutes = (): Record<string, Reply> => ({
    'GET /sessions': { body: { data: [{ id: 's1', table_label: 'Mesa 1', status: 'pending_approval', join_code: 'ABC234', participants: 1 }] } },
    'GET /orders?filter[status]=pending&per_page=100': { body: { data: [order] } },
    'GET /orders?filter[status]=accepted&per_page=100': { body: { data: [] } },
    'POST /sessions/s1/approve': { body: { data: {} } },
    'POST /orders/o1/reject': { body: { data: {} } },
  })
  async function open(permissions: string[]) {
    routes = serviceRoutes()
    useSession().me = { id: 'u', name: 'U', email: 'u@x.test', role: 'waiter', tenant_id: 't', permissions }
    wrapper = mount(ServicePage, { attachTo: document.body, global: { plugins: [createAppRouter(createMemoryHistory())] } })
    await flushPromises()
  }

  it('approves a table and rejects an order with its reason', async () => {
    await open(['orders.view', 'orders.accept'])
    await expectAccessible(wrapper.element.parentElement!)

    await click('Aprobar la mesa Mesa 1')
    await wrapper.findAll('button').find((b) => b.text() === 'Rechazar' && b.attributes('aria-label')?.includes('pedido'))!.trigger('click')
    await flushPromises()
    await body().find('input').setValue('Sin existencias')
    await body().find('form').trigger('submit')
    await flushPromises()

    expect(calls.map((c) => c.key)).toContain('POST /sessions/s1/approve')
    expect(calls.find((c) => c.key === 'POST /orders/o1/reject')!.body).toEqual({ reason: 'Sin existencias' })
  })

  it('shows no actions without permission to decide', async () => {
    await open(['orders.view'])

    expect(wrapper.text()).toContain('Tortilla')
    expect(wrapper.findAll('button')).toHaveLength(0)
  })
})
