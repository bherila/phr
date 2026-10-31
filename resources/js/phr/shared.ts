import type { ZodError, ZodType } from 'zod'

import { fetchWrapper } from '@/fetchWrapper'

export interface ApiError {
  message?: string
}

export interface DetailFetchResult<T> {
  data: T | null
  notFound: boolean
}

export function errorMessage(caught: unknown): string {
  if (typeof caught === 'string') {
    return caught
  }

  if (caught && typeof caught === 'object' && 'message' in caught) {
    return String((caught as ApiError).message)
  }

  return 'Request failed.'
}

export async function fetchPhrDetail<T>(url: string, schema: ZodType<T>): Promise<DetailFetchResult<T>> {
  // getRaw sends the same request, and answers a detail the page preloaded without one.
  const response = await fetchWrapper.getRaw(url)

  if (response.status === 404) {
    return { data: null, notFound: true }
  }

  const text = await response.text()
  let payload: unknown = null

  if (text) {
    try {
      payload = JSON.parse(text)
    } catch {
      payload = text
    }
  }

  if (!response.ok) {
    throw (payload && typeof payload === 'object' && 'message' in payload)
      ? String((payload as ApiError).message)
      : response.statusText
  }

  return { data: schema.parse(payload), notFound: false }
}

export function compactPayload<T extends Record<string, unknown>>(data: T): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(data).map(([key, value]) => [key, value === '' ? null : value])
  )
}

export function zodErrorMessage(caught: ZodError): string {
  return caught.issues[0]?.message ?? 'Please check the form values.'
}

export function numericPayload<T extends Record<string, unknown>>(data: T, numericKeys: string[]): Record<string, unknown> {
  const payload = compactPayload(data)

  for (const key of numericKeys) {
    const value = payload[key]
    payload[key] = typeof value === 'string' && value.trim() !== '' ? Number(value) : null
  }

  return payload
}

export function readPatientIdFromQuery(): number | null {
  const params = new URLSearchParams(window.location.search)
  const value = params.get('patient_id')
  const parsed = value ? Number.parseInt(value, 10) : Number.NaN
  return Number.isFinite(parsed) ? parsed : null
}

export function setPatientIdInQuery(patientId: number | null): void {
  const url = new URL(window.location.href)
  if (patientId === null) {
    url.searchParams.delete('patient_id')
  } else {
    url.searchParams.set('patient_id', String(patientId))
  }
  window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`)
}
