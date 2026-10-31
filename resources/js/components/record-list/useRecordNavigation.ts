import { type FocusEvent, type KeyboardEvent, type MouseEvent, useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'

import { bindingMatches, isEditableTarget } from '@/lib/shortcuts'

import { RECORD_LIST_KEYS } from './recordListKeys'

export interface RecordOpenOptions {
  /**
   * True when the open follows the active row while the detail panel is already showing a record.
   * The caller should replace the current history entry rather than push one, so Back does not
   * step through every row the arrow keys passed.
   */
  replace: boolean
}

export interface RecordSelection {
  isSelected: (id: string) => boolean
  onToggle: (id: string) => void
}

export interface RecordNavigationOptions<Row> {
  rows: readonly Row[]
  rowId: (row: Row) => string
  /** Open a row: Enter, or a click outside its buttons and links. */
  onOpen?: (row: Row, options: RecordOpenOptions) => void
  /**
   * The id of the record the detail panel shows, or null when it is closed. While it is open,
   * moving the active row opens that row too (debounced, with `replace`). When it closes, focus
   * returns to the row that was open.
   */
  openRowId?: string | null
  /** How long the active row must settle before the open panel follows it. */
  followDelayMs?: number
  selection?: RecordSelection
}

export interface RecordRowProps {
  ref: (element: HTMLElement | null) => void
  tabIndex: number
  'data-record-row': string
  'data-active'?: ''
  'data-open'?: ''
  onFocus: (event: FocusEvent<HTMLElement>) => void
  onClick: (event: MouseEvent<HTMLElement>) => void
}

export interface RecordNavigation<Row> {
  activeId: string | null
  containerProps: {
    ref: (element: HTMLElement | null) => void
    onKeyDown: (event: KeyboardEvent<HTMLElement>) => void
    'data-record-list': ''
  }
  rowProps: (row: Row) => RecordRowProps
}

export const DEFAULT_FOLLOW_DELAY_MS = 150

const FOCUSABLE = 'a[href], button, input, select, textarea, summary, iframe, [tabindex], [contenteditable="true"]'
const INTERACTIVE = 'a[href], button, input, select, textarea, label, summary, [role="button"], [contenteditable="true"]'
/** Marks an element whose tabindex the list changed; the value is its own tabindex, if any. */
const MANAGED = 'data-record-list-tabindex'

/**
 * Keep only the active row's buttons and links in the Tab order. Done on the DOM, not through
 * props, so any cell content (shared review buttons, links, inline forms) follows the rule
 * without knowing about the list.
 */
function syncRowTabIndexes(container: HTMLElement, activeId: string | null): void {
  for (const row of container.querySelectorAll<HTMLElement>('[data-record-row]')) {
    const active = row.dataset.recordRow === activeId
    for (const element of row.querySelectorAll<HTMLElement>(FOCUSABLE)) {
      if (element.hasAttribute('data-record-row')) continue
      if (active) {
        if (!element.hasAttribute(MANAGED)) continue
        const original = element.getAttribute(MANAGED)
        element.removeAttribute(MANAGED)
        if (original) element.setAttribute('tabindex', original)
        else element.removeAttribute('tabindex')
      } else if (!element.hasAttribute(MANAGED) && element.getAttribute('tabindex') !== '-1') {
        element.setAttribute(MANAGED, element.getAttribute('tabindex') ?? '')
        element.setAttribute('tabindex', '-1')
      }
    }
  }
}

function prefersReducedMotion(): boolean {
  return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

function focusRowElement(element: HTMLElement): void {
  element.focus({ preventScroll: true })
  element.scrollIntoView?.({ block: 'nearest', inline: 'nearest', behavior: prefersReducedMotion() ? 'auto' : 'smooth' })
}

/**
 * The keyboard model every record list shares: one Tab stop with a roving tabindex, ↑/↓ and
 * J/K, Home/End, Enter to open, Space to check, Esc from a row's button back to the row, and
 * only the active row's buttons in the Tab order. Mouse and keyboard share one active row.
 */
export function useRecordNavigation<Row>({
  rows,
  rowId,
  onOpen,
  openRowId = null,
  followDelayMs = DEFAULT_FOLLOW_DELAY_MS,
  selection,
}: RecordNavigationOptions<Row>): RecordNavigation<Row> {
  const ids = rows.map(rowId)
  const [chosenId, setChosenId] = useState<string | null>(openRowId)
  const [seenOpenRowId, setSeenOpenRowId] = useState(openRowId)
  if (seenOpenRowId !== openRowId) {
    // A record opened from elsewhere (a link, Back, the palette) becomes the active row.
    setSeenOpenRowId(openRowId)
    if (openRowId !== null) setChosenId(openRowId)
  }
  const activeId = chosenId !== null && ids.includes(chosenId)
    ? chosenId
    : openRowId !== null && ids.includes(openRowId) ? openRowId : ids[0] ?? null

  const [container, setContainer] = useState<HTMLElement | null>(null)
  const rowElementsRef = useRef(new Map<string, HTMLElement>())
  const followTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const latestRef = useRef({ rows, rowId, onOpen, openRowId, followDelayMs, selection, activeId })
  useEffect(() => {
    latestRef.current = { rows, rowId, onOpen, openRowId, followDelayMs, selection, activeId }
  })

  const cancelFollow = useCallback((): void => {
    if (followTimerRef.current !== null) clearTimeout(followTimerRef.current)
    followTimerRef.current = null
  }, [])
  useEffect(() => cancelFollow, [cancelFollow])

  useLayoutEffect(() => {
    if (container) syncRowTabIndexes(container, activeId)
  })

  useEffect(() => {
    if (!container || typeof MutationObserver === 'undefined') return
    // Cells that re-render on their own (a busy button appearing) still get the rule.
    const observer = new MutationObserver(() => syncRowTabIndexes(container, latestRef.current.activeId))
    observer.observe(container, { childList: true, subtree: true })
    return () => observer.disconnect()
  }, [container])

  const previousOpenRowIdRef = useRef(openRowId)
  useEffect(() => {
    const previous = previousOpenRowIdRef.current
    previousOpenRowIdRef.current = openRowId
    if (previous === null || openRowId !== null) return
    const element = rowElementsRef.current.get(previous)
    if (!element) return
    // The panel closed. Its content is gone, so focus would fall to the page: put it back on
    // the row that was open, unless the person has already moved somewhere else.
    const focused = document.activeElement
    if (focused === null || focused === document.body || container?.contains(focused)) {
      focusRowElement(element)
    }
  }, [openRowId, container])

  const rowById = (id: string): Row | undefined => latestRef.current.rows.find((row) => latestRef.current.rowId(row) === id)

  const moveTo = (id: string): void => {
    const element = rowElementsRef.current.get(id)
    if (!element) return
    setChosenId(id)
    focusRowElement(element)
    const { onOpen: open, openRowId: openId, followDelayMs: delay } = latestRef.current
    if (!open || openId === null || id === openId) {
      cancelFollow()
      return
    }
    cancelFollow()
    followTimerRef.current = setTimeout(() => {
      followTimerRef.current = null
      const row = rowById(id)
      if (row !== undefined) open(row, { replace: true })
    }, delay)
  }

  const onKeyDown = (event: KeyboardEvent<HTMLElement>): void => {
    if (event.defaultPrevented) return
    const target = event.target as HTMLElement
    const rowElement = target.closest<HTMLElement>('[data-record-row]')
    if (!rowElement || !event.currentTarget.contains(rowElement)) return
    const onRow = target === rowElement
    if (!onRow && isEditableTarget(target)) return
    const id = rowElement.dataset.recordRow ?? ''
    const index = latestRef.current.rows.findIndex((row) => latestRef.current.rowId(row) === id)
    if (index < 0) return
    const native = event.nativeEvent
    const step = (to: number): void => {
      event.preventDefault()
      const next = latestRef.current.rows[Math.max(0, Math.min(latestRef.current.rows.length - 1, to))]
      if (next !== undefined) moveTo(latestRef.current.rowId(next))
    }

    if (bindingMatches(RECORD_LIST_KEYS.next, native)) step(index + 1)
    else if (bindingMatches(RECORD_LIST_KEYS.previous, native)) step(index - 1)
    else if (bindingMatches(RECORD_LIST_KEYS.first, native)) step(0)
    else if (bindingMatches(RECORD_LIST_KEYS.last, native)) step(latestRef.current.rows.length - 1)
    else if (bindingMatches(RECORD_LIST_KEYS.back, native)) {
      if (onRow) return
      event.preventDefault()
      focusRowElement(rowElement)
    } else if (onRow && bindingMatches(RECORD_LIST_KEYS.open, native)) {
      const row = latestRef.current.rows[index]
      if (!latestRef.current.onOpen || row === undefined) return
      event.preventDefault()
      cancelFollow()
      latestRef.current.onOpen(row, { replace: false })
    } else if (onRow && bindingMatches(RECORD_LIST_KEYS.toggle, native)) {
      if (!latestRef.current.selection) return
      event.preventDefault()
      latestRef.current.selection.onToggle(id)
    }
  }

  const rowProps = (row: Row): RecordRowProps => {
    const id = rowId(row)
    return {
      ref: (element) => {
        if (element) rowElementsRef.current.set(id, element)
        else rowElementsRef.current.delete(id)
      },
      tabIndex: id === activeId ? 0 : -1,
      'data-record-row': id,
      ...(id === activeId ? { 'data-active': '' as const } : {}),
      ...(id === openRowId ? { 'data-open': '' as const } : {}),
      onFocus: () => setChosenId(id),
      onClick: (event) => {
        const open = latestRef.current.onOpen
        if (!open) return
        const interactive = (event.target as Element).closest(INTERACTIVE)
        if (interactive && interactive !== event.currentTarget && event.currentTarget.contains(interactive)) return
        cancelFollow()
        open(row, { replace: false })
      },
    }
  }

  return {
    activeId,
    containerProps: { ref: setContainer, onKeyDown, 'data-record-list': '' },
    rowProps,
  }
}
