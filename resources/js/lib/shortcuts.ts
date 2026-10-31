import { useEffect, useRef } from 'react'

/**
 * One key press, as a binding names it: a lower-case character (`g`, `?`, `[`), a named key
 * (`ArrowDown`, `Escape`, `Home`, `Enter`, `Space`), or `Mod+k` for ⌘K on a Mac and Ctrl+K
 * elsewhere.
 */
export type KeyToken = string

export interface ShortcutBinding {
  id: string
  /** Each entry is one way to trigger the binding: a single key, or a sequence such as G then S. */
  keys: readonly (readonly KeyToken[])[]
  description: string
  /** The heading the shortcuts dialog lists it under. */
  group: string
  /**
   * What the binding does. A binding without one is listed in the dialog but dispatched by the
   * component that owns it (the record list handles its own keys while it has focus).
   */
  run?: (event: KeyboardEvent) => void
  /** Dispatch only while true; the binding is still listed. Defaults to true. */
  enabled?: boolean
  /** Fire while focus is in a field. Off by default: shortcuts pause while you type. */
  allowInInputs?: boolean
  /** Fire while a dialog is open. Off by default. */
  allowInDialogs?: boolean
}

/** How long the second key of a sequence (G then S) may take. */
export const SEQUENCE_TIMEOUT_MS = 1200

export function isEditableTarget(target: EventTarget | null): boolean {
  const element = target instanceof HTMLElement ? target : (typeof document === 'undefined' ? null : document.activeElement)
  if (!(element instanceof HTMLElement)) return false
  if (element.isContentEditable || element.closest('[contenteditable="true"], [contenteditable=""]')) return true
  return ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName)
}

export function isDialogOpen(): boolean {
  return document.querySelector('[role="dialog"][data-open], [role="alertdialog"][data-open]') !== null
}

/** The token a key press stands for, or null for presses no binding can use (Alt chords). */
export function eventKeyToken(event: Pick<KeyboardEvent, 'key' | 'metaKey' | 'ctrlKey' | 'altKey'>): KeyToken | null {
  if (event.altKey) return null
  if (event.metaKey || event.ctrlKey) {
    return event.key.length === 1 ? `Mod+${event.key.toLowerCase()}` : null
  }
  if (event.key === ' ') return 'Space'
  return event.key.length === 1 ? event.key.toLowerCase() : event.key
}

export function bindingMatches(binding: Pick<ShortcutBinding, 'keys'>, event: Pick<KeyboardEvent, 'key' | 'metaKey' | 'ctrlKey' | 'altKey'>): boolean {
  const token = eventKeyToken(event)
  return token !== null && binding.keys.some((sequence) => sequence.length === 1 && sequence[0] === token)
}

const KEY_LABELS: Record<string, string> = {
  ArrowUp: '↑',
  ArrowDown: '↓',
  ArrowLeft: '←',
  ArrowRight: '→',
  Escape: 'Esc',
  Space: 'Space',
  Enter: 'Enter',
  Home: 'Home',
  End: 'End',
  Tab: 'Tab',
  'Shift+Tab': 'Shift+Tab',
}

function isApplePlatform(): boolean {
  return typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)
}

/** How a token is printed in the shortcuts dialog. */
export function keyLabel(token: KeyToken): string {
  if (token.startsWith('Mod+')) {
    const key = token.slice(4).toUpperCase()
    return isApplePlatform() ? `⌘${key}` : `Ctrl+${key}`
  }
  return KEY_LABELS[token] ?? token.toUpperCase()
}

interface PendingSequence {
  tokens: KeyToken[]
  timer: ReturnType<typeof setTimeout>
}

function startsWith(sequence: readonly KeyToken[], prefix: readonly KeyToken[]): boolean {
  return prefix.every((token, index) => sequence[index] === token)
}

/**
 * The one global keyboard listener. Every shortcut in the app is a binding passed here, and the
 * shortcuts dialog renders the same array, so the dialog cannot list a key that does not exist.
 *
 * Shortcuts pause while focus is in an input, textarea, select or contenteditable element and
 * while a dialog is open, unless a binding opts in. A press another handler already consumed
 * (`defaultPrevented`) is ignored. A key that starts a sequence waits {@link SEQUENCE_TIMEOUT_MS}
 * for the next one; any other key ends the wait and is handled on its own.
 */
export function useShortcutRegistry(bindings: readonly ShortcutBinding[]): void {
  const latestRef = useRef(bindings)
  useEffect(() => {
    latestRef.current = bindings
  })

  useEffect(() => {
    let pending: PendingSequence | null = null
    const clearPending = (): void => {
      if (pending) clearTimeout(pending.timer)
      pending = null
    }

    const handler = (event: KeyboardEvent): void => {
      if (event.defaultPrevented || event.isComposing) return
      const token = eventKeyToken(event)
      if (token === null) return
      const editable = isEditableTarget(event.target)
      const dialogOpen = isDialogOpen()
      const live = latestRef.current.filter((binding) => (
        binding.run !== undefined
        && binding.enabled !== false
        && (!editable || binding.allowInInputs === true)
        && (!dialogOpen || binding.allowInDialogs === true)
      ))

      const run = (binding: ShortcutBinding | undefined): boolean => {
        if (!binding?.run) return false
        event.preventDefault()
        binding.run(event)
        return true
      }

      if (pending) {
        const sequence = [...pending.tokens, token]
        clearPending()
        const complete = live.find((binding) => binding.keys.some((keys) => keys.length === sequence.length && startsWith(keys, sequence)))
        if (run(complete)) return
      }

      if (run(live.find((binding) => binding.keys.some((keys) => keys.length === 1 && keys[0] === token)))) return

      if (live.some((binding) => binding.keys.some((keys) => keys.length > 1 && keys[0] === token))) {
        event.preventDefault()
        // Cleared by clearPending, which the effect's cleanup also calls.
        // eslint-disable-next-line @eslint-react/web-api-no-leaked-timeout
        pending = { tokens: [token], timer: setTimeout(clearPending, SEQUENCE_TIMEOUT_MS) }
      }
    }

    window.addEventListener('keydown', handler)
    return () => {
      clearPending()
      window.removeEventListener('keydown', handler)
    }
  }, [])
}
