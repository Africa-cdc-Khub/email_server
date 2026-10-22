import axios, { type AxiosError, type InternalAxiosRequestConfig } from 'axios'

const TOKEN_KEY = 'email_server.token'

type RetryConfig = InternalAxiosRequestConfig & {
  /** Attempts already made (0 = first try). */
  __retryCount?: number
}

const MAX_NETWORK_RETRIES = 2
const RETRY_BASE_DELAY_MS = 350

/**
 * Prefer sessionStorage so the admin bearer token does not survive browser restarts.
 * Migrate any legacy localStorage token once, then clear it.
 */
function tokenStore(): Storage {
  return window.sessionStorage
}

function migrateLegacyToken(): void {
  try {
    const legacy = window.localStorage.getItem(TOKEN_KEY)
    if (legacy && !window.sessionStorage.getItem(TOKEN_KEY)) {
      window.sessionStorage.setItem(TOKEN_KEY, legacy)
    }
    window.localStorage.removeItem(TOKEN_KEY)
  } catch {
    // ignore storage access errors
  }
}

migrateLegacyToken()

export const api = axios.create({
  baseURL: '/api/v1',
  timeout: 30_000,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  // Avoid Axios treating empty 204 bodies as parse failures in some browsers.
  transitional: {
    clarifyTimeoutError: true,
  },
})

api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

function isRetryableNetworkError(error: AxiosError): boolean {
  // No HTTP response: dropped connection, DNS, CORS opaque failure, proxy reset, etc.
  if (!error.response) {
    const code = String(error.code || '')
    if (code === 'ERR_CANCELED') return false
    return (
      code === 'ECONNABORTED' ||
      code === 'ETIMEDOUT' ||
      code === 'ERR_NETWORK' ||
      error.message === 'Network Error' ||
      /timeout/i.test(error.message)
    )
  }

  // Transient gateway / upstream blips behind nginx.
  return [502, 503, 504].includes(error.response.status)
}

function shouldRetry(error: AxiosError, config: RetryConfig | undefined): boolean {
  if (!config) return false
  const method = (config.method || 'get').toLowerCase()
  // Only auto-retry safe / idempotent reads — never login, send-mail, etc.
  if (!['get', 'head', 'options'].includes(method)) return false
  const attempt = config.__retryCount ?? 0
  if (attempt >= MAX_NETWORK_RETRIES) return false
  return isRetryableNetworkError(error)
}

api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const config = error.config as RetryConfig | undefined

    if (shouldRetry(error, config) && config) {
      config.__retryCount = (config.__retryCount ?? 0) + 1
      const delay = RETRY_BASE_DELAY_MS * config.__retryCount
      await sleep(delay)
      return api.request(config)
    }

    // Expired / revoked session — drop token so the UI does not keep hammering /me.
    if (error.response?.status === 401) {
      const url = String(config?.url || '')
      const isAuthBootstrap =
        url.includes('/admin/auth/login') ||
        url.includes('/admin/auth/verify-2fa') ||
        url.includes('/admin/auth/forgot-password') ||
        url.includes('/admin/auth/reset-password')
      if (!isAuthBootstrap) {
        setToken(null)
      }
    }

    return Promise.reject(error)
  },
)

export function setToken(token: string | null) {
  try {
    if (token) tokenStore().setItem(TOKEN_KEY, token)
    else tokenStore().removeItem(TOKEN_KEY)
    window.localStorage.removeItem(TOKEN_KEY)
  } catch {
    // ignore
  }
}

export function getToken(): string | null {
  try {
    return tokenStore().getItem(TOKEN_KEY)
  } catch {
    return null
  }
}
