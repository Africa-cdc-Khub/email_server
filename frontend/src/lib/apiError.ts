import axios from 'axios'

/** Human-readable message from an Axios / Laravel validation error. */
export function apiErrorMessage(err: unknown, fallback = 'Request failed'): string {
  if (!axios.isAxiosError(err)) {
    return err instanceof Error ? err.message : fallback
  }

  // No response: connection dropped, proxy restart, offline, timeout.
  if (!err.response) {
    const code = String(err.code || '')
    if (code === 'ECONNABORTED' || code === 'ETIMEDOUT' || /timeout/i.test(err.message)) {
      return 'The server took too long to respond. Please try again.'
    }
    if (code === 'ERR_NETWORK' || err.message === 'Network Error') {
      return 'Network error — check your connection and try again.'
    }
    if (code === 'ERR_CANCELED') {
      return 'Request was cancelled.'
    }
    return err.message || 'Network error — please try again.'
  }

  const data = err.response.data as
    | { message?: string; error?: string; errors?: Record<string, string[] | string> }
    | undefined
  if (!data) {
    if (err.response.status >= 500) {
      return 'The server is temporarily unavailable. Please try again.'
    }
    return err.message || fallback
  }
  if (data.errors && typeof data.errors === 'object') {
    const parts = Object.values(data.errors).flatMap((v) => (Array.isArray(v) ? v : [v]))
    if (parts.length) return parts.join(' ')
  }
  return data.message || data.error || err.message || fallback
}
