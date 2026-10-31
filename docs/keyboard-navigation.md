# Keyboard navigation

Everything in PHR can be done from the keyboard. This document is the contract: the keys,
what they do, and the rules every list and shortcut follows. Layout and URLs are in
[the interface shell](ui-shell.md).

## Lists and tables

Every list of records (the Conditions table, the Labs list, the Review table) behaves the
same way. The behavior lives in the shared list and detail-panel components, not in each page.

| Key | Action |
|---|---|
| Tab | Move into the list: focus lands on the active row |
| ↑ ↓, J K | Move the active row |
| Home, End | First or last row |
| Enter | Open the active row: the detail panel on wide screens, its own page below `lg` |
| Tab (on the active row) | Step through that row's action buttons, then leave the list |
| Shift+Tab | The same, in reverse |
| Space | Check or uncheck the row, where the table has checkboxes |
| `/` | Focus the list's filter or search field, if it has one |
| Esc | Close the detail panel and put focus back on the row that opened it |

### The rules behind it

- **The list is one Tab stop.** Rows use a roving `tabindex`: the active row has `0`, every
  other row `-1`. The active row also has `aria-selected="true"`. Native table semantics stay.
- **Only the active row's buttons are in the Tab order.** Its buttons (Resolve, Edit,
  Compare and so on) get `tabindex="0"`; every other row's buttons get `-1`, updated whenever
  the active row changes. So Tab goes: active row → its buttons in visual order → the next
  region of the page.
- **Arrow keys always move rows.** Pressing ↑ or ↓ while one of the row's buttons has focus
  moves the active row and puts focus on the new row, not on the same button in the next row.
  Esc from a button goes back to its row.
- **Mouse and keyboard share one selection.** Clicking a row, or a button on a row, makes it
  the active row.
- **Buttons are named for their row**, e.g. "Resolve Seasonal allergic rhinitis", so a screen
  reader says what will change.
- **The detail panel follows the active row.** While the panel is open, moving the active row
  shows that record in the panel. Fetches are debounced (about 150ms), and the URL is updated
  with `replaceState`, so browser Back doesn't replay every arrow press.
- **The active row has a visible focus ring:** 2px in `--ring`.
- **Scrolling a row into view respects `prefers-reduced-motion`.**

## Anywhere

| Key | Action |
|---|---|
| ⌘K / Ctrl+K | Search this patient |
| G, then a letter | Go to a section (below) |
| `[` | Collapse or expand the sidebar (counts as the manual sizing choice) |
| `?` | Show the keyboard shortcuts dialog |

### Go to (after G)

| Letter | Section |
|---|---|
| R | Review (once it exists) |
| S | Summary |
| H | Health Log |
| L | Labs |
| V | Vitals |
| T | Visits |
| M | Medications |
| C | Conditions |
| P | Procedures |
| I | Immunizations |
| A | Allergies |
| D | Documents |
| X | Imaging |

## Rules for every shortcut

- **Shortcuts pause while you type.** None fire while focus is in an input, textarea, select or
  contenteditable element, or while a dialog is open (except Esc).
- **No single key changes the record.** Approving, rejecting, resolving, editing and deleting
  always take a deliberate action: a focused button and Enter or Space, never a bare letter.
- **One registration point.** Shortcuts register through one central hook, never a separate
  global `keydown` listener per component.
- **The shortcuts dialog is a real dialog** with a focus trap. It lists exactly the keys in
  this document. When a shortcut changes, change this document and the dialog together.

## Tests

The list behavior is covered by tests that drive the real keys:

- arrow movement, Home and End
- Enter opens the record; Esc returns focus to its row
- Tab from the active row reaches its first button, then its second, then leaves the table;
  Shift+Tab walks back; buttons on inactive rows are never reached
- with the panel open, arrows update the panel and use `replaceState`
- shortcuts are ignored inside inputs
- the `G` sequence
