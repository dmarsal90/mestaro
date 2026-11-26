export class ApiError extends Error {
  constructor(public status: number, public code: string, message: string, public details?: Record<string, string[]>) {
    super(message)
  }
}

type Tokens = { access_token: string; refresh_token: string }
/** What the client needs from the session; the store implements it (kept as an interface so the client does not import Pinia). */
export interface TokenSource {
  accessToken(): string | null
  refreshToken(): string | null
  replace(tokens: Tokens): void
  clear(): void
}

let source: TokenSource | null = null
export const useTokenSource = (s: TokenSource) => { source = s }

async function raw(path: string, init: RequestInit, token: string | null): Promise<Response> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (typeof init.body === 'string') headers.set('Content-Type', 'application/json')
  if (token) headers.set('Authorization', `Bearer ${token}`)
  return fetch(`/api/v1${path}`, { ...init, headers })
}

async function parse<T>(res: Response): Promise<T> {
  if (res.status === 204) return undefined as T
  const body = await res.json().catch(() => null)
  if (!res.ok) {
    const e = body?.error
    throw new ApiError(res.status, e?.code ?? 'UNKNOWN', e?.message ?? 'Unexpected error', e?.details)
  }
  return body as T
}

// Refresh tokens are single use: concurrent 401s must share ONE refresh, or the second would burn the rotated token and log the user out.
let refreshing: Promise<boolean> | null = null
function refresh(): Promise<boolean> {
  refreshing ??= (async () => {
    const token = source?.refreshToken()
    if (!token) return false
    try {
      const res = await raw('/auth/refresh', { method: 'POST', body: JSON.stringify({ refresh_token: token }) }, null)
      if (!res.ok) return false
      source!.replace((await res.json()).data)
      return true
    } catch {
      return false
    }
  })().finally(() => { refreshing = null })
  return refreshing
}

/** JSON request with the session's token; a 401 triggers one refresh and one retry, then the session ends. */
export async function api<T>(path: string, init: RequestInit & { json?: unknown; auth?: boolean } = {}): Promise<T> {
  const { json, auth = true, ...rest } = init
  const options: RequestInit = { ...rest, ...(json !== undefined ? { body: JSON.stringify(json) } : {}) }
  // After a page reload only the refresh token survives: renew first instead of sending a request that must fail.
  if (auth && !source?.accessToken() && source?.refreshToken() && !(await refresh())) source.clear()
  let res = await raw(path, options, auth ? (source?.accessToken() ?? null) : null)
  if (res.status === 401 && auth && source?.refreshToken()) {
    if (await refresh()) res = await raw(path, options, source.accessToken())
    else source.clear()
  }
  return parse<T>(res)
}
