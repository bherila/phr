import { Clock3, Pencil, Trash2 } from 'lucide-react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'

import { HealthLogEntryForm } from './HealthLogEntryForm'
import type { HealthLog, HealthLogEntry } from './healthLogSchemas'

interface HealthLogTimelineProps {
  healthLog: HealthLog
  entries: HealthLogEntry[]
  canManage: boolean
  busy: boolean
  mutationKey: string | null
  onUpdate: (entryId: number, payload: Record<string, unknown>) => Promise<boolean>
  onDelete: (entryId: number) => Promise<boolean>
}

function formatOccurredAt(value: string): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date)
}

function detailValue(value: unknown): string {
  if (typeof value === 'string') {
    return value
  }
  return JSON.stringify(value)
}

export function HealthLogTimeline({
  healthLog,
  entries,
  canManage,
  busy,
  mutationKey,
  onUpdate,
  onDelete,
}: HealthLogTimelineProps) {
  const [editingId, setEditingId] = useState<number | null>(null)
  const [deletingId, setDeletingId] = useState<number | null>(null)

  async function update(entryId: number, payload: Record<string, unknown>): Promise<boolean> {
    const updated = await onUpdate(entryId, payload)
    if (updated) {
      setEditingId(null)
    }
    return updated
  }

  async function remove(entryId: number): Promise<void> {
    const deleted = await onDelete(entryId)
    if (deleted) {
      setDeletingId(null)
    }
  }

  if (busy) {
    return <p className="py-8 text-sm text-muted-foreground">Loading entries…</p>
  }

  if (entries.length === 0) {
    return (
      <div className="rounded-xl border border-dashed border-border px-4 py-12 text-center">
        <Clock3 className="mx-auto size-8 text-muted-foreground/60" />
        <p className="mt-3 font-medium text-foreground">No entries yet</p>
        <p className="mt-1 text-sm text-muted-foreground">
          {canManage ? `Record the first observation in ${healthLog.name}.` : 'Nothing has been recorded in this log.'}
        </p>
      </div>
    )
  }

  return (
    <ol className="relative grid gap-4 before:absolute before:inset-y-2 before:left-2 before:w-px before:bg-border">
      {entries.map((entry) => (
        <li key={entry.id} className="relative pl-7">
          <span className="absolute left-0 top-5 size-4 rounded-full border-4 border-card bg-primary" aria-hidden="true" />
          <article className="rounded-xl border border-border bg-card p-4 shadow-xs">
            {editingId === entry.id ? (
              <HealthLogEntryForm
                key={entry.id}
                healthLog={healthLog}
                entry={entry}
                busy={mutationKey === `edit-entry:${entry.id}`}
                onSubmit={(payload) => update(entry.id, payload)}
                onCancel={() => setEditingId(null)}
              />
            ) : (
              <>
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-xs font-medium text-muted-foreground">{formatOccurredAt(entry.occurred_at)}</p>
                    <h3 className="mt-1 text-base font-semibold text-card-foreground">{entry.title ?? healthLog.name}</h3>
                  </div>
                  {entry.intensity !== null && <Badge variant="outline">Intensity {entry.intensity}/10</Badge>}
                </div>

                {entry.notes && <p className="mt-3 whitespace-pre-wrap text-sm leading-6 text-foreground">{entry.notes}</p>}

                {entry.tags.length > 0 && (
                  <div className="mt-3 flex flex-wrap gap-1.5">
                    {entry.tags.map((tag) => <Badge key={tag} variant="secondary">{tag}</Badge>)}
                  </div>
                )}

                {entry.details && Object.keys(entry.details).length > 0 && (
                  <dl className="mt-3 grid gap-x-4 gap-y-2 rounded-lg bg-muted/40 p-3 text-xs sm:grid-cols-2">
                    {Object.entries(entry.details).map(([key, value]) => (
                      <div key={key} className="min-w-0">
                        <dt className="font-medium text-muted-foreground">{key.replaceAll('_', ' ')}</dt>
                        <dd className="mt-0.5 break-words text-foreground">{detailValue(value)}</dd>
                      </div>
                    ))}
                  </dl>
                )}

                {canManage && (
                  <div className="mt-4 flex items-center justify-end gap-1 border-t border-border/60 pt-2">
                    {deletingId === entry.id ? (
                      <>
                        <span className="mr-auto text-xs text-destructive">Delete this entry?</span>
                        <Button
                          type="button"
                          size="sm"
                          variant="destructive"
                          disabled={mutationKey === `delete-entry:${entry.id}`}
                          onClick={() => void remove(entry.id)}
                        >
                          {mutationKey === `delete-entry:${entry.id}` ? 'Deleting…' : 'Delete'}
                        </Button>
                        <Button type="button" size="sm" variant="ghost" onClick={() => setDeletingId(null)}>Cancel</Button>
                      </>
                    ) : (
                      <>
                        <Button type="button" size="icon-sm" variant="ghost" title="Edit health log entry" onClick={() => setEditingId(entry.id)}>
                          <Pencil className="size-4" />
                        </Button>
                        <Button type="button" size="icon-sm" variant="ghost" title="Delete health log entry" className="text-destructive hover:text-destructive" onClick={() => setDeletingId(entry.id)}>
                          <Trash2 className="size-4" />
                        </Button>
                      </>
                    )}
                  </div>
                )}
              </>
            )}
          </article>
        </li>
      ))}
    </ol>
  )
}
