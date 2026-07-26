import { fetchWrapper } from '@/fetchWrapper'

import {
  type HealthLog,
  HealthLogEntriesResponseSchema,
  type HealthLogEntry,
  HealthLogEntryResponseSchema,
  HealthLogResponseSchema,
  HealthLogsResponseSchema,
} from './healthLogSchemas'

export interface HealthLogListResult {
  healthLogs: HealthLog[]
  canManage: boolean
}

export interface HealthLogEntryListResult {
  entries: HealthLogEntry[]
  canManage: boolean
}

function healthLogsEndpoint(patientId: number): string {
  return `/api/phr/patients/${patientId}/health-logs`
}

function healthLogEndpoint(patientId: number, healthLogId: number): string {
  return `${healthLogsEndpoint(patientId)}/${healthLogId}`
}

function entriesEndpoint(patientId: number, healthLogId: number): string {
  return `${healthLogEndpoint(patientId, healthLogId)}/entries`
}

export async function listHealthLogs(patientId: number): Promise<HealthLogListResult> {
  const raw: unknown = await fetchWrapper.get(healthLogsEndpoint(patientId))
  const parsed = HealthLogsResponseSchema.parse(raw)
  return { healthLogs: parsed.health_logs, canManage: parsed.can_manage }
}

export async function createHealthLog(patientId: number, payload: Record<string, unknown>): Promise<HealthLog> {
  const raw: unknown = await fetchWrapper.post(healthLogsEndpoint(patientId), payload)
  return HealthLogResponseSchema.parse(raw).health_log
}

export async function updateHealthLog(
  patientId: number,
  healthLogId: number,
  payload: Record<string, unknown>,
): Promise<HealthLog> {
  const raw: unknown = await fetchWrapper.patch(healthLogEndpoint(patientId, healthLogId), payload)
  return HealthLogResponseSchema.parse(raw).health_log
}

export async function deleteHealthLog(patientId: number, healthLogId: number): Promise<void> {
  await fetchWrapper.delete(healthLogEndpoint(patientId, healthLogId), {})
}

export async function listHealthLogEntries(patientId: number, healthLogId: number): Promise<HealthLogEntryListResult> {
  const raw: unknown = await fetchWrapper.get(entriesEndpoint(patientId, healthLogId))
  const parsed = HealthLogEntriesResponseSchema.parse(raw)
  return { entries: parsed.entries, canManage: parsed.can_manage }
}

export async function createHealthLogEntry(
  patientId: number,
  healthLogId: number,
  payload: Record<string, unknown>,
): Promise<HealthLogEntry> {
  const raw: unknown = await fetchWrapper.post(entriesEndpoint(patientId, healthLogId), payload)
  return HealthLogEntryResponseSchema.parse(raw).entry
}

export async function updateHealthLogEntry(
  patientId: number,
  healthLogId: number,
  entryId: number,
  payload: Record<string, unknown>,
): Promise<HealthLogEntry> {
  const raw: unknown = await fetchWrapper.patch(`${entriesEndpoint(patientId, healthLogId)}/${entryId}`, payload)
  return HealthLogEntryResponseSchema.parse(raw).entry
}

export async function deleteHealthLogEntry(patientId: number, healthLogId: number, entryId: number): Promise<void> {
  await fetchWrapper.delete(`${entriesEndpoint(patientId, healthLogId)}/${entryId}`, {})
}
