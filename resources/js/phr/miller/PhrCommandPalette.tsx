import { type Dispatch, type SetStateAction, useMemo } from 'react'

import { MillerCommandPalette, type MillerCommandPaletteRow, type MillerDrillTarget, useMillerCommandPaletteShortcut } from '@/components/ui/miller'

import {
  PHR_LIST_MODULES,
  type PhrModuleCategory,
  type PhrModuleId,
  type phrModuleRegistry,
  type PhrRegistryEntry,
} from './phrModuleRegistry'

const GROUP_ORDER: PhrModuleCategory[] = ['Clinical', 'Documents & Imaging', 'Admin']
const GROUP_HEADINGS: Record<PhrModuleCategory, string> = {
  Clinical: 'Clinical',
  'Documents & Imaging': 'Documents & Imaging',
  Admin: 'Admin',
}

const DIRECT_JUMP_MODULE_IDS: ReadonlySet<PhrModuleId> = new Set(PHR_LIST_MODULES.map((module) => module.id))

interface PaletteRow extends MillerCommandPaletteRow<PhrModuleCategory> {
  rowKey: string
  moduleId: PhrModuleId
  label: string
  keywords: string[]
  category: PhrModuleCategory
}

interface PhrCommandPaletteProps {
  open: boolean
  onClose: () => void
  onDrill: (target: MillerDrillTarget<PhrModuleId>) => void
  registry: typeof phrModuleRegistry
}

/**
 * Cmd/Ctrl-K command palette for jumping to root PHR modules.
 */
export function PhrCommandPalette({ open, onClose, onDrill, registry }: PhrCommandPaletteProps): React.ReactElement {
  const rows = useMemo(() => buildRows(registry), [registry])

  const handleSelect = (row: PaletteRow): void => {
    onDrill({ id: row.moduleId })
  }

  return (
    <MillerCommandPalette
      open={open}
      onOpenChange={(nextOpen) => {
        if (!nextOpen) {
          onClose()
        }
      }}
      title="Jump to PHR module"
      description="Search clinical, document, and access modules"
      placeholder="Jump to a PHR module…"
      emptyMessage="No matching modules."
      groupOrder={GROUP_ORDER}
      groupHeadings={GROUP_HEADINGS}
      rows={rows}
      onSelect={handleSelect}
    />
  )
}

export function usePhrCommandPaletteShortcut(
  open: boolean,
  setOpen: Dispatch<SetStateAction<boolean>>,
): void {
  useMillerCommandPaletteShortcut(open, setOpen)
}

function buildRows(registry: typeof phrModuleRegistry): PaletteRow[] {
  const rows: PaletteRow[] = []

  for (const entry of Object.values(registry)) {
    if (!isDirectJumpEntry(entry)) {
      continue
    }

    const category = entry.meta?.category ?? entry.category
    const keywords = entry.meta?.keywords ?? entry.keywords

    rows.push({
      rowKey: entry.id,
      moduleId: entry.id,
      label: entry.label,
      keywords: uniqueKeywords([...keywords, entry.label, entry.shortLabel, entry.id]),
      category,
    })
  }

  return rows
}

function isDirectJumpEntry(entry: PhrRegistryEntry): boolean {
  return DIRECT_JUMP_MODULE_IDS.has(entry.id) && entry.presentation === 'column' && entry.instances === undefined
}

function uniqueKeywords(keywords: string[]): string[] {
  return Array.from(new Set(keywords.filter((keyword) => keyword.trim() !== '')))
}
