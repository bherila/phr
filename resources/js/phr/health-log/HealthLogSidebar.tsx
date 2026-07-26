import { Activity, Cookie, NotebookPen, Pencil, Plus, Trash2, Utensils } from 'lucide-react'
import type { ComponentType } from 'react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

import { HealthLogForm } from './HealthLogForm'
import type { HealthLog, HealthLogKind } from './healthLogSchemas'

const KIND_ICONS: Record<HealthLogKind, ComponentType<{ className?: string }>> = {
  meal: Utensils,
  snack: Cookie,
  symptom: Activity,
  custom: NotebookPen,
}

interface HealthLogSidebarProps {
  healthLogs: HealthLog[]
  selectedHealthLogId: number | null
  canManage: boolean
  busy: boolean
  mutationKey: string | null
  onSelect: (healthLogId: number) => void
  onCreate: (payload: Record<string, unknown>) => Promise<boolean>
  onUpdate: (healthLogId: number, payload: Record<string, unknown>) => Promise<boolean>
  onDelete: (healthLogId: number) => Promise<boolean>
}

export function HealthLogSidebar({
  healthLogs,
  selectedHealthLogId,
  canManage,
  busy,
  mutationKey,
  onSelect,
  onCreate,
  onUpdate,
  onDelete,
}: HealthLogSidebarProps) {
  const [creating, setCreating] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [deletingId, setDeletingId] = useState<number | null>(null)

  async function create(payload: Record<string, unknown>): Promise<boolean> {
    const created = await onCreate(payload)
    if (created) {
      setCreating(false)
    }
    return created
  }

  async function update(healthLogId: number, payload: Record<string, unknown>): Promise<boolean> {
    const updated = await onUpdate(healthLogId, payload)
    if (updated) {
      setEditingId(null)
    }
    return updated
  }

  async function remove(healthLogId: number): Promise<void> {
    const deleted = await onDelete(healthLogId)
    if (deleted) {
      setDeletingId(null)
    }
  }

  return (
    <aside className="rounded-xl border border-border bg-card p-3">
      <div className="flex items-center justify-between gap-3 px-1 pb-3">
        <div>
          <h2 className="text-sm font-semibold text-card-foreground">Your logs</h2>
          <p className="text-xs text-muted-foreground">Choose a journal to view.</p>
        </div>
        {canManage && !creating && (
          <Button type="button" size="icon-sm" variant="outline" title="Create health log" onClick={() => setCreating(true)}>
            <Plus className="size-4" />
          </Button>
        )}
      </div>

      {creating && (
        <div className="mb-3">
          <HealthLogForm busy={mutationKey === 'add-log'} onSubmit={create} onCancel={() => setCreating(false)} />
        </div>
      )}

      {busy && healthLogs.length === 0 && <p className="px-1 py-6 text-sm text-muted-foreground">Loading logs…</p>}
      {!busy && healthLogs.length === 0 && !creating && (
        <div className="rounded-lg border border-dashed border-border px-3 py-8 text-center">
          <NotebookPen className="mx-auto size-7 text-muted-foreground/60" />
          <p className="mt-2 text-sm font-medium text-foreground">No health logs yet</p>
          <p className="mt-1 text-xs text-muted-foreground">Create one for meals, snacks, symptoms, or anything else.</p>
          {canManage && <Button type="button" size="sm" className="mt-3" onClick={() => setCreating(true)}>Create a log</Button>}
        </div>
      )}

      <div className="grid gap-2">
        {healthLogs.map((healthLog) => {
          const KindIcon = KIND_ICONS[healthLog.kind]
          const selected = selectedHealthLogId === healthLog.id

          if (editingId === healthLog.id) {
            return (
              <HealthLogForm
                key={healthLog.id}
                healthLog={healthLog}
                busy={mutationKey === `edit-log:${healthLog.id}`}
                onSubmit={(payload) => update(healthLog.id, payload)}
                onCancel={() => setEditingId(null)}
              />
            )
          }

          return (
            <div
              key={healthLog.id}
              className={cn(
                'rounded-lg border transition-colors',
                selected ? 'border-primary/60 bg-primary/5' : 'border-border bg-background hover:border-primary/30',
              )}
            >
              <button type="button" className="flex w-full items-start gap-3 p-3 text-left" onClick={() => onSelect(healthLog.id)}>
                <span className={cn('rounded-md p-2', selected ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground')}>
                  <KindIcon className="size-4" />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium text-foreground">{healthLog.name}</span>
                  <span className="mt-1 flex flex-wrap items-center gap-1.5">
                    <Badge variant="secondary" className="capitalize">{healthLog.kind}</Badge>
                    <span className="text-xs text-muted-foreground">
                      {healthLog.entries_count} {healthLog.entries_count === 1 ? 'entry' : 'entries'}
                    </span>
                  </span>
                </span>
              </button>
              {canManage && selected && (
                <div className="flex items-center justify-end gap-1 border-t border-border/60 px-2 py-1.5">
                  {deletingId === healthLog.id ? (
                    <>
                      <span className="mr-auto pl-1 text-xs text-destructive">Delete this log and its entries?</span>
                      <Button
                        type="button"
                        size="sm"
                        variant="destructive"
                        disabled={mutationKey === `delete-log:${healthLog.id}`}
                        onClick={() => void remove(healthLog.id)}
                      >
                        {mutationKey === `delete-log:${healthLog.id}` ? 'Deleting…' : 'Delete'}
                      </Button>
                      <Button type="button" size="sm" variant="ghost" onClick={() => setDeletingId(null)}>Cancel</Button>
                    </>
                  ) : (
                    <>
                      <Button type="button" size="icon-sm" variant="ghost" title="Edit health log" onClick={() => setEditingId(healthLog.id)}>
                        <Pencil className="size-4" />
                      </Button>
                      <Button type="button" size="icon-sm" variant="ghost" title="Delete health log" className="text-destructive hover:text-destructive" onClick={() => setDeletingId(healthLog.id)}>
                        <Trash2 className="size-4" />
                      </Button>
                    </>
                  )}
                </div>
              )}
            </div>
          )
        })}
      </div>
    </aside>
  )
}
