import { useCallback } from 'react'

export const BASE = import.meta.env.VITE_API_BASE ?? 'http://127.0.0.1:8765'

export async function api<T>(path: string, method: string = 'GET', body?: unknown, token?: string | null): Promise<T> {
  const res = await fetch(BASE + path, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  })
  if (!res.ok) {
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
    <T,>(path: string, method?: string, body?: unknown) =>
      api<T>(path, method, body, token),
    [token],
  )
}