import { useCallback } from 'react'

export const BASE = import.meta.env.VITE_API_BASE ?? 'http://127.0.0.1:8765'

export async function api<T>(path: string, method: string = 'GET', body?: unknown, token?: string | null, signal?: AbortSignal): Promise<T> {
  const res = await fetch(BASE + path, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
    signal,
  })
  if (!res.ok) {
    if (res.status === 429) {
      const retry = res.headers.get('Retry-After')
      throw new Error(`محاولات كثيرة — انتظر ${retry ?? '60'} ثانية وحاول مجددًا`)
    }
    let detail = res.statusText
    try {
      const j = await res.json()
      detail = j.message_ar ?? j.message ?? JSON.stringify(j)
    } catch { /* ignore */ }
    throw new Error(`${res.status} ${detail}`)
  }
  return res.json() as Promise<T>
}

export function useAuthedApi(token: string | null) {
  return useCallback(
    <T,>(path: string, method?: string, body?: unknown, opts?: { signal?: AbortSignal }) =>
      api<T>(path, method, body, token, opts?.signal),
    [token],
  )
}