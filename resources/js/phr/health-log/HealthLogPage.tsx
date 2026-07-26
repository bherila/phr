import { NotebookPen } from 'lucide-react'

import type { PhrListPageProps } from '@/phr/miller'

import { HealthLogEntryForm } from './HealthLogEntryForm'
import { HealthLogSidebar } from './HealthLogSidebar'
import { HealthLogTimeline } from './HealthLogTimeline'
import { useHealthLogs } from './useHealthLogs'

export default function HealthLogPage({ patientId }: PhrListPageProps) {
  const state = useHealthLogs(patientId)

  return (
    <div className="grid gap-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
            <NotebookPen className="size-6 text-primary" />
            Health Log
          </h1>
          <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
            Keep meals, snacks, symptoms, and personal observations together in timestamped journals.
          </p>
        </div>
      </header>

      {state.error && (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
          {state.error}
        </div>
      )}

      <div className="grid items-start gap-5 lg:grid-cols-[minmax(16rem,20rem)_minmax(0,1fr)]">
        <HealthLogSidebar
          healthLogs={state.healthLogs}
          selectedHealthLogId={state.selectedHealthLog?.id ?? null}
          canManage={state.canManage}
          busy={state.logsBusy}
          mutationKey={state.mutationKey}
          onSelect={state.selectHealthLog}
          onCreate={state.addHealthLog}
          onUpdate={state.editHealthLog}
          onDelete={state.removeHealthLog}
        />

        <main className="min-w-0">
          {state.selectedHealthLog ? (
            <div className="grid gap-5">
              <section className="rounded-xl border border-border bg-card p-4 sm:p-5">
                <div className="mb-4">
                  <h2 className="text-lg font-semibold text-card-foreground">{state.selectedHealthLog.name}</h2>
                  {state.selectedHealthLog.description && (
                    <p className="mt-1 text-sm text-muted-foreground">{state.selectedHealthLog.description}</p>
                  )}
                </div>
                {state.canManage ? (
                  <HealthLogEntryForm
                    key={state.selectedHealthLog.id}
                    healthLog={state.selectedHealthLog}
                    busy={state.mutationKey === 'add-entry'}
                    onSubmit={state.addEntry}
                  />
                ) : (
                  <p className="rounded-lg bg-muted/40 p-3 text-sm text-muted-foreground">You have read-only access to this health log.</p>
                )}
              </section>

              <section aria-label={`${state.selectedHealthLog.name} entries`}>
                <div className="mb-3 flex items-center justify-between gap-3">
                  <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Timeline</h2>
                  <span className="text-xs text-muted-foreground">
                    {state.entries.length} {state.entries.length === 1 ? 'entry' : 'entries'}
                  </span>
                </div>
                <HealthLogTimeline
                  healthLog={state.selectedHealthLog}
                  entries={state.entries}
                  canManage={state.canManage}
                  busy={state.entriesBusy}
                  mutationKey={state.mutationKey}
                  onUpdate={state.editEntry}
                  onDelete={state.removeEntry}
                />
              </section>
            </div>
          ) : !state.logsBusy ? (
            <div className="flex min-h-64 items-center justify-center rounded-xl border border-dashed border-border p-8 text-center">
              <div>
                <NotebookPen className="mx-auto size-8 text-muted-foreground/60" />
                <p className="mt-3 font-medium text-foreground">Choose or create a health log</p>
                <p className="mt-1 text-sm text-muted-foreground">Each log keeps one kind of observation organized over time.</p>
              </div>
            </div>
          ) : null}
        </main>
      </div>
    </div>
  )
}
