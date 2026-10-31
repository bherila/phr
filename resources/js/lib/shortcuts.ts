/**
 * One key press, as a binding names it: a lower-case character (`g`, `?`, `[`), a named key
 * (`ArrowDown`, `Escape`, `Home`, `Enter`, `Space`), or `Mod+k` for ⌘K on a Mac and Ctrl+K
 * elsewhere.
 */
export type KeyToken = string

/**
 * A key binding as data: the keys, and what they do in words. The record list matches presses
 * against these entries, so its keys are defined once.
 */
export interface ShortcutBinding {
  id: string
  /** Each entry is one way to trigger the binding: a single key, or a sequence such as G then S. */
  keys: readonly (readonly KeyToken[])[]
  description: string
  /** The heading the shortcuts dialog lists it under. */
  group: string
}

export function isEditableTarget(target: EventTarget | null): boolean {
  const element = target instanceof HTMLElement ? target : (typeof document === 'undefined' ? null : document.activeElement)
  if (!(element instanceof HTMLElement)) return false
  if (element.isContentEditable || element.closest('[contenteditable="true"], [contenteditable=""]')) return true
  return ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName)
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
