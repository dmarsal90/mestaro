import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError, api, useTokenSource } from '../api/client'

export type Me = { id: string; name: string; email: string; role: string; tenant_id: string | null; permissions: string[] }
export type Restaurant = { id: string; name: string }
export type LoginResult = { status: 'ok' } | { status: 'choose'; restaurants: Restaurant[] }

const KEY = 'mestaro-refresh'
// sessionStorage, not localStorage: a shared tablet forgets the session when the browser closes. Every access is guarded: storage can throw.
const read = () => { try { return sessionStorage.getItem(KEY) } catch { return null } }
const write = (v: string | null) => { try { v === null ? sessionStorage.removeItem(KEY) : sessionStorage.setItem(KEY, v) } catch { /* the session then lives until reload */ } }

export const useSession = defineStore('session', () => {
  // The access token (10 minutes) lives only in memory; the refresh token (single use, rotated) in sessionStorage.
  const access = ref<string | null>(null)
  const refresh = ref<string | null>(read())
  const me = ref<Me | null>(null)
  const ready = ref(false)

  const isAuthenticated = computed(() => me.value !== null)
  const can = (permission: string) => me.value?.permissions.includes(permission) ?? false

  function replace(tokens: { access_token: string; refresh_token: string }) {
    access.value = tokens.access_token
    refresh.value = tokens.refresh_token
    write(tokens.refresh_token)
  }
  function clear() {
    access.value = refresh.value = null
    me.value = null
    write(null)
  }
  useTokenSource({ accessToken: () => access.value, refreshToken: () => refresh.value, replace, clear })

  async function loadMe() {
    me.value = (await api<{ data: Me }>('/auth/me')).data
  }

  async function login(email: string, password: string, restaurantId?: string): Promise<LoginResult> {
    const { data } = await api<{ data: { access_token?: string; refresh_token?: string; restaurants?: Restaurant[] } }>('/auth/login', {
      method: 'POST', auth: false, json: { email, password, ...(restaurantId ? { restaurant_id: restaurantId } : {}) },
    })
    if (data.restaurants) return { status: 'choose', restaurants: data.restaurants }
    replace(data as { access_token: string; refresh_token: string })
    await loadMe()
    if (me.value?.tenant_id === null) {
      // Platform administrators work in the admin panel, not in the restaurant staff app.
      clear()
      throw new ApiError(403, 'PLATFORM_ADMIN', 'Las cuentas de plataforma usan el panel de administración.')
    }
    return { status: 'ok' }
  }

  /** On page load: trade the stored refresh token for a session, or stay signed out. */
  async function restore() {
    try {
      if (refresh.value) {
        // A 401 here goes through the client's single refresh, which also rotates the token.
        await loadMe()
      }
    } catch {
      clear()
    } finally {
      ready.value = true
    }
  }

  async function logout() {
    try {
      await api('/auth/logout', { method: 'POST' })
    } catch { /* the token may already be dead: the local session ends either way */ }
    clear()
  }

  return { me, ready, isAuthenticated, can, login, restore, logout }
})
