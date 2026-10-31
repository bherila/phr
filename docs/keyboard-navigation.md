# Keyboard navigation

Everything in PHR can be done from the keyboard. This document is the contract: the keys,
what they do, and the rules every list and shortcut follows. Layout and URLs are in
[the interface shell](ui-shell.md).

Status markers: **(shipped)** is in the code; **(planned)** is designed but not built yet.
The list behavior, ⌘K / Ctrl+K and Esc on the detail panel are shipped. The other global
shortcuts (G, `[`, `?`, `/`) are planned as one follow-up piece of work, because they share
the central registration point and the shortcuts dialog must list exactly the keys that exist.

## Lists and tables

**(shipped)** Every list of records behaves the same way: the tables on Labs, Vitals,
Medications, Conditions, Immunizations and Allergies, and the lists on Visits, Procedures,
Documents (list view), Imaging and Access. The behavior lives in the shared record table and
list (`resources/js/components/record-list`), not in each page.

| Key | Action |
|---|---|
| Tab | Move into the list: focus lands on the active row |
| ↑ ↓, J K | Move the active row |
| Home, End | First or last row |
| Enter | Open the active row: the detail panel on wide screens, its own page below `lg` |
| Tab (on the active row) | Step through that row's action buttons, then leave the list |
| Shift+Tab | The same, in reverse |
| Space | Check or uncheck the row, where the table has checkboxes |
| `/` | Focus the list's filter or search field, if it has one **(planned)** |
| Esc | From a row's button, go back to its row; on the row, close the detail panel and put focus back on the row that opened it |

### The rules behind it

- **The list is one Tab stop.** Rows use a roving `tabindex`: the active row has `0`, every
  other row `-1`. In a table the active row also has `aria-selected="true"`, and native table
  semantics stay. The list form (timelines, card stacks) marks its active item with
  `aria-current="true"` instead, because `aria-selected` is not allowed on a list item.
- **Only the active row's buttons are in the Tab order.** Its buttons (Resolve, Edit,
  Compare and so on) get `tabindex="0"`; every other row's buttons get `-1`, updated whenever
  the active row changes (on the DOM, so shared cell components follow the rule too). So Tab
  goes: active row → its buttons in visual order → the next region of the page. Inline edit
  and delete rows under a table row are not part of the row and stay in the Tab order.
- **Arrow keys always move rows.** Pressing ↑ or ↓ while one of the row's buttons has focus
  moves the active row and puts focus on the new row, not on the same button in the next row.
  Esc from a button goes back to its row; a second Esc closes the panel. Keys typed into a
  field inside a row are left alone.
- **Mouse and keyboard share one selection.** Clicking a row, or a button on a row, makes it
  the active row.
- **Buttons are named for their row**, e.g. "Resolve Seasonal allergic rhinitis", "Edit
  Metformin", "End now: Metformin", so a screen reader says what will change.
- **The detail panel follows the active row.** While the panel is open, moving the active row
  shows that record in the panel. Fetches are debounced (150ms), and the URL is updated with
  `replaceState`, so browser Back doesn't replay every arrow press. Enter and clicks push.
- **The active row has a visible focus ring:** a 2px outline in `--ring` while it has keyboard
  focus. The row whose record is open in the panel is highlighted with `--accent`.
- **Scrolling a row into view respects `prefers-reduced-motion`.**

## Anywhere

| Key | Action |
|---|---|
| ⌘K / Ctrl+K | Search this patient; outside a patient's record, jump to a section or a patient **(shipped)** |
| G, then a letter | Go to a section (below) **(planned)** |
| `[` | Collapse or expand the sidebar (counts as the manual sizing choice) **(planned)** |
| `?` | Show the keyboard shortcuts dialog **(planned)** |

### Go to (after G) (planned)

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
  contenteditable element, or while a dialog is open (except Esc). **(shipped** for ⌘K and Esc**)**
- **No single key changes the record.** Approving, rejecting, resolving, editing and deleting
  always take a deliberate action: a focused button and Enter or Space, never a bare letter.
- **One registration point.** Shortcuts register through one central hook, never a separate
  global `keydown` listener per component. **(planned)** Today ⌘K and the panel's Esc each have
  their own listener; the planned hook replaces both. The list's own keys are defined once as
  data (`recordListKeys.ts`) and handled by the list while it has focus. **(shipped)**
- **The shortcuts dialog is a real dialog** with a focus trap. It lists exactly the keys in
  this document. When a shortcut changes, change this document and the dialog together.

## Tests

**(shipped)** The list behavior is covered by tests that drive the real keys
(`RecordTable.test.tsx`, `ConditionsPage.keyboard.test.tsx`, and the Conditions journey in
`tests/e2e/phr.spec.ts`):

- arrow, J/K, Home and End movement
- Enter opens the record; Esc returns focus to its row, from the row or from inside the panel
- Tab from the active row reaches its first button, then its second, then leaves the table;
  Shift+Tab walks back; buttons on inactive rows are never reached
- arrows from a button move to the next row; Space toggles a checkbox
- with the panel open, arrows update the panel, debounced, and use `replaceState`

**(planned)** With the global shortcuts: shortcuts are ignored inside inputs and dialogs, the
`G` sequence, `[`, `/`, and the `?` dialog listing exactly the registered keys.
