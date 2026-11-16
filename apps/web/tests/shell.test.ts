import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { ApiError, api } from '../src/api/client'
import { useTheme } from '../src/composables/useTheme'
import LoginPage from '../src/pages/LoginPage.vue'
import { createAppRouter, safeRedirect } from '../src/router'
import { useSession } from '../src/stores/session'
import { expectAccessible } from './a11y'

type Reply = { status?: number; body?: unknown }
const json = ({ status = 200, body }: Reply) => new Response(body === undefined ? null : JSON.stringify(body), { status })
const tokens = (n: number) => ({ data: { access_token: `a${n}`, refresh_token: `r${n}`, token_type: 'Bearer', expires_in: 600 } })
const me = (tenant: string | null = 'T1', permissions = ['orders.view']) => ({ data: { id: 'u1', name: 'Ana', email: 'ana@example.com', role: 'waiter', tenant_id: tenant, permissions } })

/** Fetch stub answering by "METHOD /path"; records every call. */
function stubFetch(routes: Record<string, Reply | ((req: Request) => Reply)>) {
  const calls: Array<{ key: string; auth: string | null; body: string | null }> = []
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const req = new Request(`http://x${url}`, init)
    const key = `${req.method} ${new URL(req.url).pathname.replace('/api/v1', '')}`
    calls.push({ key, auth: req.headers.get('Authorization'), body: init.body ? String(init.body) : null })
    const handler = routes[key]
    if (!handler) return json({ status: 500, body: { error: { code: 'UNSTUBBED', message: key } } })
    return json(typeof handler === 'function' ? handler(req) : handler)
  }))
  return calls
}

beforeEach(() => { setActivePinia(createPinia()); sessionStorage.clear(); localStorage.clear(); document.documentElement.removeAttribute('data-theme') })
afterEach(() => { vi.unstubAllGlobals(); document.body.innerHTML = '' })

describe('api client', () => {
  it('refreshes once and retries when the access token expired', async () => {
    sessionStorage.setItem('mestaro-refresh', 'r0') // before the store is created: it reads storage on creation
    const s = useSession()
    let served = 0
    const calls = stubFetch({
      'GET /auth/me': (req) => (req.headers.get('Authorization') === 'Bearer a1' ? { body: me() } : { status: 401, body: { error: { code: 'UNAUTHENTICATED', message: 'x' } } }),
      'POST /auth/refresh': () => { served++; return { body: tokens(1) } },
    })
    await s.restore()
    expect(s.isAuthenticated).toBe(true)
    expect(served).toBe(1)
    expect(calls.map((c) => c.key)).toEqual(['POST /auth/refresh', 'GET /auth/me']) // no doomed request after a reload
    expect(sessionStorage.getItem('mestaro-refresh')).toBe('r1') // rotated token stored
  })

  it('shares ONE refresh between simultaneous 401s: a single-use token must not be burned twice', async () => {
    sessionStorage.setItem('mestaro-refresh', 'r0')
    useSession()
    let refreshes = 0
    stubFetch({
      'GET /a': (req) => (req.headers.get('Authorization') === 'Bearer a1' ? { body: { ok: 1 } } : { status: 401, body: { error: { code: 'UNAUTHENTICATED', message: 'x' } } }),
      'GET /b': (req) => (req.headers.get('Authorization') === 'Bearer a1' ? { body: { ok: 2 } } : { status: 401, body: { error: { code: 'UNAUTHENTICATED', message: 'x' } } }),
      'POST /auth/refresh': () => { refreshes++; return { body: tokens(1) } },
    })
    const [a, b] = await Promise.all([api('/a'), api('/b')])
    expect([a, b]).toEqual([{ ok: 1 }, { ok: 2 }])
    expect(refreshes).toBe(1)
  })

  it('ends the session instead of looping when the refresh fails', async () => {
    sessionStorage.setItem('mestaro-refresh', 'dead')
    const s = useSession()
    const calls = stubFetch({
      'GET /auth/me': { status: 401, body: { error: { code: 'UNAUTHENTICATED', message: 'x' } } },
      'POST /auth/refresh': { status: 401, body: { error: { code: 'UNAUTHENTICATED', message: 'x' } } },
    })
    await s.restore()
    expect(s.isAuthenticated).toBe(false)
    expect(sessionStorage.getItem('mestaro-refresh')).toBeNull()
    expect(calls.filter((c) => c.key === 'POST /auth/refresh')).toHaveLength(1)
  })

  it('turns an error envelope into ApiError', async () => {
    stubFetch({ 'POST /x': { status: 422, body: { error: { code: 'VALIDATION_FAILED', message: 'Invalid', details: { email: ['bad'] } } } } })
    await expect(api('/x', { method: 'POST', auth: false })).rejects.toMatchObject({ status: 422, code: 'VALIDATION_FAILED', details: { email: ['bad'] } })
    expect(new ApiError(1, 'c', 'm')).toBeInstanceOf(Error)
  })
})

describe('session store', () => {
  it('logs in, keeps only the refresh token in storage and loads the profile', async () => {
    const s = useSession()
    stubFetch({ 'POST /auth/login': { body: tokens(1) }, 'GET /auth/me': { body: me('T1', ['menu.manage']) } })
    expect(await s.login('ana@example.com', 'pw')).toEqual({ status: 'ok' })
    expect(s.can('menu.manage')).toBe(true)
    expect(s.can('users.manage')).toBe(false)
    expect(sessionStorage.getItem('mestaro-refresh')).toBe('r1')
    expect(JSON.stringify(sessionStorage)).not.toContain('a1') // the access token never touches storage
    expect(localStorage.length).toBe(0)
  })
  it('asks which restaurant when there are several, and sends the choice', async () => {
    const s = useSession()
    const calls = stubFetch({ 'POST /auth/login': (req) => ({ body: req.url ? { data: { restaurants: [{ id: 'R1', name: 'Alfa' }, { id: 'R2', name: 'Beta' }] } } : {} }) })
    expect(await s.login('ana@example.com', 'pw')).toEqual({ status: 'choose', restaurants: [{ id: 'R1', name: 'Alfa' }, { id: 'R2', name: 'Beta' }] })
    expect(s.isAuthenticated).toBe(false)
    expect(JSON.parse(calls[0].body!)).toEqual({ email: 'ana@example.com', password: 'pw' })
  })
  it('turns platform administrators away: they use the admin panel', async () => {
    const s = useSession()
    stubFetch({ 'POST /auth/login': { body: tokens(1) }, 'GET /auth/me': { body: me(null, []) } })
    await expect(s.login('root@example.com', 'pw')).rejects.toMatchObject({ code: 'PLATFORM_ADMIN' })
    expect(s.isAuthenticated).toBe(false)
    expect(sessionStorage.getItem('mestaro-refresh')).toBeNull()
  })
  it('does not call the server on load without a stored session', async () => {
    const calls = stubFetch({})
    const s = useSession()
    await s.restore()
    expect(calls).toHaveLength(0)
    expect(s.ready).toBe(true)
  })
  it('ends the local session even if logout cannot reach the server', async () => {
    const s = useSession()
    stubFetch({ 'POST /auth/login': { body: tokens(1) }, 'GET /auth/me': { body: me() }, 'POST /auth/logout': { status: 500 } })
    await s.login('a@example.com', 'pw')
    await s.logout()
    expect(s.isAuthenticated).toBe(false)
    expect(sessionStorage.getItem('mestaro-refresh')).toBeNull()
  })
  it('works when storage throws', async () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('denied') })
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('denied') })
    const s = useSession()
    stubFetch({ 'POST /auth/login': { body: tokens(1) }, 'GET /auth/me': { body: me() } })
    await expect(s.login('a@example.com', 'pw')).resolves.toEqual({ status: 'ok' })
    vi.restoreAllMocks()
  })
})

describe('router', () => {
  const at = async (path: string, permissions?: string[]) => {
    const session = useSession()
    stubFetch(permissions ? { 'POST /auth/login': { body: tokens(1) }, 'GET /auth/me': { body: me('T1', permissions) } } : {})
    if (permissions) await session.login('a@example.com', 'pw')
    session.$patch({})
    const router = createAppRouter(createMemoryHistory())
    await router.push(path)
    return router
  }
  it('sends a visitor to login and remembers where they were going', async () => {
    const r = await at('/ajustes?x=1')
    expect(r.currentRoute.value.name).toBe('login')
    expect(r.currentRoute.value.query.redirect).toBe('/ajustes?x=1')
  })
  it('lets a signed-in user in and keeps them out of the login page', async () => {
    const r = await at('/', ['orders.view'])
    expect(r.currentRoute.value.name).toBe('home')
    await r.push('/login')
    expect(r.currentRoute.value.name).toBe('home')
  })
  it('only follows same-site redirects', () => {
    expect(safeRedirect('/carta')).toBe('/carta')
    for (const bad of ['https://evil.example', '//evil.example', '/\\evil.example', 'javascript:alert(1)', undefined, 5, ['/x']]) expect(safeRedirect(bad)).toBe('/')
  })
})

describe('theme', () => {
  it('applies and remembers an explicit choice, and "system" returns to the preference', () => {
    const { set } = useTheme()
    set('light')
    expect(document.documentElement.dataset.theme).toBe('light')
    expect(localStorage.getItem('mestaro-theme')).toBe('light')
    set('system')
    expect(document.documentElement.dataset.theme).toBeUndefined()
    expect(localStorage.getItem('mestaro-theme')).toBeNull()
  })
  it('still applies the choice when storage throws', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('denied') })
    useTheme().set('dark')
    expect(document.documentElement.dataset.theme).toBe('dark')
    vi.restoreAllMocks()
  })
})

describe('LoginPage', () => {
  const page = async () => {
    const router = createAppRouter(createMemoryHistory())
    await router.push('/login')
    const w = mount(LoginPage, { attachTo: document.body, global: { plugins: [router] } })
    return { w, router }
  }
  const fill = async (w: ReturnType<typeof mount>) => {
    await w.find('input[type=email]').setValue('ana@example.com')
    await w.find('input[type=password]').setValue('secret')
    await w.find('form').trigger('submit')
    await flushPromises()
  }

  it('is accessible and labels its fields', async () => {
    const { w } = await page()
    expect(w.findAll('label').map((l) => l.text())).toEqual(['Correo *', 'Contraseña *'])
    await expectAccessible(w.element)
  })
  it('shows one generic message for a wrong password', async () => {
    stubFetch({ 'POST /auth/login': { status: 401, body: { error: { code: 'INVALID_CREDENTIALS', message: 'no' } } } })
    const { w } = await page()
    await fill(w)
    expect(w.find('[role=alert]').text()).toBe('Correo o contraseña incorrectos.')
  })
  it('says so when rate limited or offline', async () => {
    stubFetch({ 'POST /auth/login': { status: 429, body: { error: { code: 'RATE_LIMITED', message: 'x' } } } })
    let { w } = await page()
    await fill(w)
    expect(w.find('[role=alert]').text()).toContain('Demasiados intentos')
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('offline') }))
    ;({ w } = await page())
    await fill(w)
    expect(w.find('[role=alert]').text()).toContain('No se pudo conectar')
  })
  it('asks for the restaurant, then signs in and goes home', async () => {
    let step = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
      const key = `${init.method ?? 'GET'} ${url.replace('/api/v1', '')}`
      if (key === 'POST /auth/login') return json(step++ === 0 ? { body: { data: { restaurants: [{ id: 'R1', name: 'Alfa' }, { id: 'R2', name: 'Beta' }] } } } : { body: tokens(1) })
      if (key === 'GET /auth/me') return json({ body: me() })
      return json({ status: 500 })
    }))
    const { w, router } = await page()
    await fill(w)
    expect(w.text()).toContain('¿En qué restaurante trabajas hoy?')
    await expectAccessible(w.element)
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('home')
  })
})
