import type { ShortcutBinding } from '@/lib/shortcuts'

export const RECORD_LIST_GROUP = 'Lists and tables'

function listKey(id: string, keys: ShortcutBinding['keys'], description: string): ShortcutBinding {
  return { id, keys, description, group: RECORD_LIST_GROUP }
}

/**
 * The keys a record list handles while it has focus. The list matches presses against these
 * entries, and the shortcuts dialog lists them, so the two cannot drift apart. They have no
 * `run`: the global listener never dispatches them.
 */
export const RECORD_LIST_KEYS = {
  enter: listKey('list-enter', [['Tab']], 'Move into the list: focus lands on the active row'),
  next: listKey('list-next', [['ArrowDown'], ['j']], 'Next row'),
  previous: listKey('list-previous', [['ArrowUp'], ['k']], 'Previous row'),
  first: listKey('list-first', [['Home']], 'First row'),
  last: listKey('list-last', [['End']], 'Last row'),
  open: listKey('list-open', [['Enter']], 'Open the active row: the detail panel on wide screens, its own page on small ones'),
  actions: listKey('list-actions', [['Tab'], ['Shift+Tab']], 'Step through the active row’s buttons, then leave the list'),
  toggle: listKey('list-toggle', [['Space']], 'Check or uncheck the row, where the list has checkboxes'),
  back: listKey('list-back', [['Escape']], 'From a row’s button, go back to its row'),
} as const satisfies Record<string, ShortcutBinding>

/** `/` is global: it focuses the page's list filter from anywhere outside a field. */
export const FOCUS_FILTER_KEYS: ShortcutBinding['keys'] = [['/']]

export const RECORD_LIST_FILTER_ATTRIBUTE = 'data-record-list-filter'

/** Focus the filter or search field of the list on this page. Returns whether there was one. */
export function focusRecordListFilter(root: ParentNode = document): boolean {
  const field = root.querySelector<HTMLElement>(`[${RECORD_LIST_FILTER_ATTRIBUTE}]`)
  if (!field) return false
  field.focus()
  if (field instanceof HTMLInputElement) field.select()
  return true
}
