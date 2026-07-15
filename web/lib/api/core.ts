export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly details?: unknown
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

type ApiEnvelope<T> = {
  success?: boolean
  message?: string
  data?: T
}

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])
let csrfPromise: Promise<void> | null = null
let csrfToken: string | null = null
let socketIdProvider: (() => string | undefined) | null = null

export function setSocketIdProvider(provider: (() => string | undefined) | null): void {
  socketIdProvider = provider
}

async function ensureCsrfToken(force = false): Promise<void> {
  if (typeof window === 'undefined') return
  if (!force && csrfToken) return

  csrfPromise ??= fetch('/api/auth/csrf', {
    credentials: 'include',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  })
    .then(async response => {
      if (!response.ok) throw new Error('无法初始化安全会话')
      const payload = (await response.json()) as ApiEnvelope<{ token?: string }>
      const token = payload.data?.token
      if (!token) throw new Error('安全会话没有返回 CSRF token')
      csrfToken = token
    })
    .finally(() => {
      csrfPromise = null
    })

  await csrfPromise
}

function unwrap<T>(payload: T | ApiEnvelope<T>): T {
  if (
    payload &&
    typeof payload === 'object' &&
    'data' in payload &&
    ('success' in payload || 'message' in payload)
  ) {
    return (payload as ApiEnvelope<T>).data as T
  }
  return payload as T
}

async function parseError(response: Response): Promise<ApiError> {
  let details: unknown
  let message = `请求失败 (${response.status})`
  try {
    details = await response.json()
    if (details && typeof details === 'object' && 'message' in details) {
      message = String((details as { message?: unknown }).message || message)
    }
  } catch {
    // 非 JSON 错误响应沿用状态码消息。
  }
  return new ApiError(message, response.status, details)
}

export async function apiRequest<T>(
  endpoint: string,
  method = 'GET',
  data?: unknown,
  options: { suppressUnauthorizedRedirect?: boolean } = {}
): Promise<T> {
  const normalizedMethod = method.toUpperCase()
  const normalizedEndpoint = endpoint.replace(/^\/+/, '')

  if (!SAFE_METHODS.has(normalizedMethod)) await ensureCsrfToken()

  const execute = () => {
    const headers = new Headers({
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    })
    const isFormData = data instanceof FormData
    if (!isFormData && data !== undefined) headers.set('Content-Type', 'application/json')
    if (!SAFE_METHODS.has(normalizedMethod) && csrfToken) {
      headers.set('X-CSRF-TOKEN', csrfToken)
    }
    const socketId = socketIdProvider?.()
    if (socketId) headers.set('X-Socket-ID', socketId)

    return fetch(`/api/${normalizedEndpoint}`, {
      method: normalizedMethod,
      credentials: 'include',
      headers,
      body:
        data === undefined || SAFE_METHODS.has(normalizedMethod)
          ? undefined
          : isFormData
            ? data
            : JSON.stringify(data),
    })
  }

  let response = await execute()
  if (response.status === 419 && !SAFE_METHODS.has(normalizedMethod)) {
    await ensureCsrfToken(true)
    response = await execute()
  }

  if (!response.ok) {
    const error = await parseError(response)
    if (error.status === 401 && !options.suppressUnauthorizedRedirect && typeof window !== 'undefined') {
      window.dispatchEvent(new Event('repo-watch:unauthorized'))
    }
    throw error
  }

  if (response.status === 204) return {} as T
  const text = await response.text()
  if (!text) return {} as T
  return unwrap<T>(JSON.parse(text) as T | ApiEnvelope<T>)
}

export const apiGet = <T = unknown>(endpoint: string) => apiRequest<T>(endpoint)
export const get = apiGet
export const post = <T = unknown>(endpoint: string, data?: unknown) =>
  apiRequest<T>(endpoint, 'POST', data)
export const put = <T = unknown>(endpoint: string, data?: unknown) =>
  apiRequest<T>(endpoint, 'PUT', data)
export const del = <T = unknown>(endpoint: string, data?: unknown) =>
  apiRequest<T>(endpoint, 'DELETE', data)

export async function authenticatedFetch(input: RequestInfo | URL, init: RequestInit = {}) {
  const method = (init.method || 'GET').toUpperCase()
  if (!SAFE_METHODS.has(method)) await ensureCsrfToken()
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  headers.set('X-Requested-With', 'XMLHttpRequest')
  if (!SAFE_METHODS.has(method) && csrfToken) headers.set('X-CSRF-TOKEN', csrfToken)
  const socketId = socketIdProvider?.()
  if (socketId) headers.set('X-Socket-ID', socketId)
  return fetch(input, { ...init, headers, credentials: 'include' })
}
