import { type ReactElement, type ReactNode } from 'react'

import { MillerColumnShell, type MillerColumnShellColumn } from './MillerColumnShell'
import { MillerInstanceTabs } from './MillerInstanceTabs'
import type { MillerColumnSize, MillerDrillTarget, MillerRegistryEntry } from './millerRegistry'
import type { MillerColumnSpec, MillerRoute } from './millerRoute'

interface MillerRegistryShellProps<State, Id extends string, Meta = unknown> {
  registry: Record<Id, MillerRegistryEntry<State, Id, Meta>>
  state: State
  homeView: ReactNode
  route: MillerRoute<Id>
  pushColumn: (column: MillerColumnSpec<Id>) => void
  replaceFrom: (depth: number, column: MillerColumnSpec<Id>) => void
  truncateTo: (depth: number) => void
  navigate: (route: MillerRoute<Id>) => void
  onDrillUnhandled?: (target: MillerDrillTarget<Id>, entry: MillerRegistryEntry<State, Id, Meta> | undefined) => void
  homeColumnSize?: MillerColumnSize
}

export function MillerRegistryShell<State, Id extends string, Meta = unknown>({
  registry,
  state,
  homeView,
  route,
  pushColumn,
  replaceFrom,
  truncateTo,
  navigate,
  onDrillUnhandled,
  homeColumnSize,
}: MillerRegistryShellProps<State, Id, Meta>): ReactElement {
  const dispatchDrill =
    (depth: number) =>
    (target: MillerDrillTarget<Id>): void => {
      const targetEntry = registry[target.id]
      if (targetEntry?.presentation !== 'column') {
        onDrillUnhandled?.(target, targetEntry)
        return
      }
      if (target.placement === 'left-of-current') {
        const targetColumn = target.instance ? { id: target.id, instance: target.instance } : { id: target.id }
        const currentColumn = route.columns[depth]
        const precedingColumns = route.columns
          .slice(0, depth)
          .filter((column) => column.id !== target.id || column.instance !== target.instance)
        navigate({
          columns: [
            ...precedingColumns,
            targetColumn,
            ...(currentColumn ? [currentColumn] : []),
            ...route.columns.slice(depth + 1),
          ],
        })
        return
      }
      if (depth + 1 < route.columns.length) {
        replaceFrom(depth + 1, target)
      } else {
        pushColumn(target)
      }
    }

  const columns: MillerColumnShellColumn[] = route.columns.map((col, depth) => {
    const entry = registry[col.id]
    if (!entry) {
      throw new Error(`Registry has no entry for id: ${col.id}`)
    }

    const Component = entry.component
    const instances = entry.instances ? entry.instances.list(state) : []
    const resolvedInstanceKey = col.instance ?? (instances.length > 0 ? instances[0]!.key : undefined)
    const activeInstance = resolvedInstanceKey ? instances.find((instance) => instance.key === resolvedInstanceKey) : undefined
    // For entries without entry.instances (simple detail pages), synthesise a
    // MillerInstanceRef from col.instance so that detail components receive
    // instance.key and can look up the correct record.
    const syntheticInstance = !entry.instances && resolvedInstanceKey
      ? { key: resolvedInstanceKey, label: resolvedInstanceKey }
      : undefined
    const passedInstance = activeInstance ?? syntheticInstance

    return {
      key: `${depth}-${col.id}-${col.instance ?? ''}`,
      id: col.id,
      label: entry.label,
      shortLabel: entry.shortLabel,
      size: entry.size ?? (entry.wide ? 'wide' : undefined),
      dataAttributes: { 'data-miller-id': col.id },
      topAccessory: entry.instances ? (
        <MillerInstanceTabs
          instances={instances}
          activeKey={resolvedInstanceKey}
          onSelect={(key: string) => replaceFrom(depth, { id: col.id, instance: key })}
          {...(entry.instances.allowCreate
            ? {
                onCreate: () => {
                  const created = entry.instances!.create(state)
                  replaceFrom(depth, { id: col.id, instance: created.key })
                },
              }
            : {})}
        />
      ) : undefined,
      children: (
        <>
          {entry.instances && !activeInstance ? (
            <div className="flex h-full flex-col items-center justify-center gap-3 text-center">
              <p className="text-sm text-muted-foreground">No {entry.shortLabel} instance selected.</p>
              {entry.instances.allowCreate && (
                <button
                  type="button"
                  onClick={() => {
                    const created = entry.instances!.create(state)
                    replaceFrom(depth, { id: col.id, instance: created.key })
                  }}
                  className="rounded-md border border-border bg-card px-3 py-1.5 text-xs font-medium text-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  Create your first {entry.shortLabel}
                </button>
              )}
            </div>
          ) : (
            <Component
              state={state}
              {...(passedInstance ? { instance: passedInstance } : {})}
              onDrill={dispatchDrill(depth)}
            />
          )}
        </>
      ),
    }
  })

  return (
    <MillerColumnShell
      homeView={homeView}
      columns={columns}
      onTruncate={truncateTo}
      {...(homeColumnSize ? { homeColumnSize } : {})}
    />
  )
}
