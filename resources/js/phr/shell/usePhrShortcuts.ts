import { type Dispatch, type SetStateAction, useMemo } from 'react'

import { FOCUS_FILTER_KEYS, focusRecordListFilter, RECORD_LIST_GROUP, RECORD_LIST_KEYS } from '@/components/record-list/recordListKeys'
import { type ShortcutBinding, useShortcutRegistry } from '@/lib/shortcuts'

import { pushPhrLocation } from './phrLocation'
import { PHR_PAGES } from './phrPages'
import { detailPresentation, isPhrPageId, patientPageUrl, type PhrView, phrViewUrl } from './phrRoutes'
import type { SidebarMode } from './useSidebarMode'

/** G then a letter. A page id the shell does not know is simply not registered. */
export const GO_TO_KEYS: readonly { key: string, page: string }[] = [
  { key: 'r', page: 'review' },
  { key: 's', page: 'summary' },
  { key: 'h', page: 'health-log' },
  { key: 'l', page: 'labs' },
  { key: 'v', page: 'vitals' },
  { key: 't', page: 'office-visits' },
  { key: 'm', page: 'medications' },
  { key: 'f', page: 'medication-fills' },
  { key: 'c', page: 'conditions' },
  { key: 'p', page: 'procedures' },
  { key: 'i', page: 'immunizations' },
  { key: 'a', page: 'allergies' },
  { key: 'd', page: 'documents' },
  { key: 'x', page: 'imaging' },
]

export const SHORTCUT_GROUPS = {
  anywhere: 'Anywhere',
  goTo: 'Go to (in a patient’s record)',
  lists: RECORD_LIST_GROUP,
} as const

export interface PhrShortcutState {
  view: PhrView
  paletteOpen: boolean
  setPaletteOpen: Dispatch<SetStateAction<boolean>>
  setHelpOpen: Dispatch<SetStateAction<boolean>>
  sidebarMode: SidebarMode
  setSidebarMode: (mode: SidebarMode) => void
}

/** The URL the detail panel's Esc goes to, or null when no panel is open. */
function panelCloseUrl(view: PhrView): string | null {
  if (view.kind !== 'patient' || detailPresentation(view) !== 'panel') return null
  return phrViewUrl({ kind: 'patient', patientId: view.patientId, page: view.page })
}

/** Every keyboard shortcut in the shell, in the order the shortcuts dialog lists them. */
export function phrShortcutBindings(state: PhrShortcutState): ShortcutBinding[] {
  const { view, paletteOpen, setPaletteOpen, setHelpOpen, sidebarMode, setSidebarMode } = state
  const patientId = view.kind === 'patient' ? view.patientId : null
  const closeUrl = panelCloseUrl(view)

  const bindings: ShortcutBinding[] = [
    {
      id: 'palette',
      keys: [['Mod+k']],
      group: SHORTCUT_GROUPS.anywhere,
      description: patientId === null ? 'Jump to a section or a patient' : 'Search this patient',
      // While the palette is open the same keys close it, from its own search field too.
      allowInInputs: paletteOpen,
      allowInDialogs: paletteOpen,
      run: () => setPaletteOpen((open) => !open),
    },
  ]
  if (patientId !== null) {
    bindings.push({
      id: 'sidebar',
      keys: [['[']],
      group: SHORTCUT_GROUPS.anywhere,
      description: 'Collapse or expand the sidebar',
      run: () => setSidebarMode(sidebarMode === 'rail' ? 'expanded' : 'rail'),
    })
  }
  bindings.push({
    id: 'help',
    keys: [['?']],
    group: SHORTCUT_GROUPS.anywhere,
    description: 'Show the keyboard shortcuts',
    run: () => setHelpOpen(true),
  })
  if (patientId !== null) {
    bindings.push({
      id: 'close-detail',
      keys: [['Escape']],
      group: SHORTCUT_GROUPS.anywhere,
      description: 'Close the detail panel and return to its row',
      enabled: closeUrl !== null,
      run: () => { if (closeUrl !== null) pushPhrLocation(closeUrl) },
    })
    for (const { key, page } of GO_TO_KEYS) {
      if (!isPhrPageId(page)) continue
      bindings.push({
        id: `go-${page}`,
        keys: [['g', key]],
        group: SHORTCUT_GROUPS.goTo,
        description: PHR_PAGES[page].label,
        run: () => pushPhrLocation(patientPageUrl(patientId, page)),
      })
    }
  }

  bindings.push(
    RECORD_LIST_KEYS.enter,
    RECORD_LIST_KEYS.next,
    RECORD_LIST_KEYS.previous,
    RECORD_LIST_KEYS.first,
    RECORD_LIST_KEYS.last,
    RECORD_LIST_KEYS.open,
    RECORD_LIST_KEYS.actions,
    RECORD_LIST_KEYS.toggle,
    {
      id: 'list-filter',
      keys: FOCUS_FILTER_KEYS,
      group: SHORTCUT_GROUPS.lists,
      description: 'Focus the list’s filter or search field, if it has one',
      run: () => { focusRecordListFilter() },
    },
    RECORD_LIST_KEYS.back,
  )
  return bindings
}

/**
 * The shell's one registration point: builds the bindings, installs the single listener, and
 * returns the same array for the shortcuts dialog.
 */
export function usePhrShortcuts(state: PhrShortcutState): ShortcutBinding[] {
  const { view, paletteOpen, setPaletteOpen, setHelpOpen, sidebarMode, setSidebarMode } = state
  const bindings = useMemo(
    () => phrShortcutBindings({ view, paletteOpen, setPaletteOpen, setHelpOpen, sidebarMode, setSidebarMode }),
    [view, paletteOpen, setPaletteOpen, setHelpOpen, sidebarMode, setSidebarMode],
  )
  useShortcutRegistry(bindings)
  return bindings
}
