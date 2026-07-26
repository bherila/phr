import type { ReactElement } from 'react'

import {
  type KeyAmount,
  type MillerColumnSpec,
  MillerDockClearButton,
  MillerDockSection,
  type MillerDockTileEntry,
  type MillerDrillTarget,
} from '@/components/ui/miller'

import {
  getPhrModuleMeta,
  PHR_LIST_MODULES,
  type PhrModuleCategory,
  type PhrModuleId,
  phrModuleRegistry,
  type PhrRegistryEntry,
  type PhrShellState,
} from './phrModuleRegistry'
import { usePhrDockPrefs } from './usePhrDockPrefs'

const CATEGORY_ORDER: PhrModuleCategory[] = ['Clinical', 'Documents & Imaging', 'Admin']
const LIST_MODULE_IDS = new Set<PhrModuleId>(PHR_LIST_MODULES.map((module) => module.id))

interface PhrDockHomeViewProps {
  patientId: number | undefined
  replaceFrom: (depth: number, column: MillerColumnSpec<PhrModuleId>) => void
  onDrill?: ((target: MillerDrillTarget<PhrModuleId>) => void) | undefined
}

export function PhrDockHomeView({ patientId, replaceFrom, onDrill }: PhrDockHomeViewProps): ReactElement {
  const state: PhrShellState = { patientId }
  const { recent, pinned, addRecent, togglePin, isPinned, clearRecent } = usePhrDockPrefs(patientId)

  if (patientId === undefined) {
    return (
      <div className="flex h-full items-center justify-center p-8 text-center">
        <div className="space-y-2">
          <p className="font-medium text-foreground">No patient selected</p>
          <p className="text-sm text-muted-foreground">Choose a patient from the selector above to get started.</p>
        </div>
      </div>
    )
  }

  const openModule = (id: PhrModuleId): void => {
    addRecent(id)
    replaceFrom(0, { id })
    onDrill?.({ id })
  }

  const entries = resolveEntries(PHR_LIST_MODULES.map((module) => module.id))
  const pinnedEntries = resolveEntries(pinned)
  const recentEntries = resolveEntries(recent).filter((entry) => !pinned.includes(entry.id))
  const groups = CATEGORY_ORDER.map((category) => ({
    category,
    entries: entries.filter((entry) => getPhrModuleMeta(entry).category === category),
  })).filter((group) => group.entries.length > 0)

  return (
    <div className="mx-auto w-full max-w-5xl space-y-6 p-6">
      <header className="space-y-1 border-b border-primary/20 pb-4">
        <h1 className="text-lg font-semibold text-foreground">Patient {patientId}</h1>
        <p className="text-sm text-muted-foreground">Open a PHR module.</p>
      </header>

      {pinnedEntries.length > 0 && (
        <MillerDockSection
          title="Pinned"
          entries={sortEntries(pinnedEntries, state).map((entry) => toModuleTile(entry, state))}
          onOpen={openModule}
          isPinned={isPinned}
          onTogglePin={togglePin}
          className="border-primary/25 bg-accent/20"
        />
      )}

      {recentEntries.length > 0 && (
        <MillerDockSection
          title="Recent"
          entries={recentEntries.map((entry) => toModuleTile(entry, state))}
          onOpen={openModule}
          isPinned={isPinned}
          onTogglePin={togglePin}
          className="border-info/25 bg-info/5"
          titleClassName="text-info"
          action={<MillerDockClearButton onClear={clearRecent} />}
        />
      )}

      {groups.map((group) => (
        <MillerDockSection
          key={group.category}
          title={group.category}
          entries={sortEntries(group.entries, state).map((entry) => toModuleTile(entry, state))}
          onOpen={openModule}
          isPinned={isPinned}
          onTogglePin={togglePin}
        />
      ))}
    </div>
  )
}

function resolveEntries(ids: PhrModuleId[]): PhrRegistryEntry[] {
  return ids
    .filter((id) => LIST_MODULE_IDS.has(id))
    .map((id) => phrModuleRegistry[id])
    .filter((entry): entry is PhrRegistryEntry => entry !== undefined && entry.presentation === 'column')
}

function moduleHasData(entry: PhrRegistryEntry, state: PhrShellState): boolean {
  const meta = getPhrModuleMeta(entry)

  if (meta.keyAmounts) {
    return meta.keyAmounts(state) !== null
  }

  if (meta.hasData) {
    return meta.hasData(state)
  }

  return true
}

function sortEntries(entries: PhrRegistryEntry[], state: PhrShellState): PhrRegistryEntry[] {
  return [...entries].sort((a, b) => {
    const aActive = moduleHasData(a, state)
    const bActive = moduleHasData(b, state)

    if (aActive === bActive) {
      return 0
    }

    return aActive ? -1 : 1
  })
}

function toModuleTile(entry: PhrRegistryEntry, state: PhrShellState): MillerDockTileEntry<PhrModuleId> {
  const keyAmounts = getPhrModuleMeta(entry).keyAmounts?.(state) ?? null

  return {
    id: entry.id,
    label: entry.label,
    shortLabel: entry.shortLabel,
    amounts: keyAmounts?.map((keyAmount) => ({
      label: keyAmount.label,
      value: formatKeyAmount(keyAmount),
    })) ?? null,
    inactive: !moduleHasData(entry, state),
    pinLabel: entry.label,
  }
}

function formatKeyAmount(keyAmount: KeyAmount): string {
  return new Intl.NumberFormat('en-US', {
    maximumFractionDigits: Number.isInteger(keyAmount.value) ? 0 : 1,
  }).format(keyAmount.value)
}
