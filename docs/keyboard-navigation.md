# Keyboard navigation

Everything in PHR can be done from the keyboard. This document is the contract: the keys,
what they do, and the rules every list and shortcut follows. Layout and URLs are in
[the interface shell](ui-shell.md).

Status markers: **(shipped)** is in the code; **(planned)** is designed but not built yet.
Everything below is shipped except where marked. The `?` dialog lists the same keys as this
document, grouped the same way, because it is built from the bindings the app dispatches.
When a shortcut changes, change this document and the bindings together.

## Lists and tables

**(shipped)** Every list of records behaves the same way: the tables on Labs, Vitals,
Medications, Conditions, Immunizations, Allergies and Review, and the lists on Visits,
Procedures, Documents (list view), Imaging and Access. The behavior lives in the shared record table and
list (`resources/js/components/record-list`), not in each page.

| Key | Action |
|---|---|
| Tab | Move into the list: focus lands on the active row |
| ↓ or J | Next row |
| ↑ or K | Previous row |
| Home | First row |
| End | Last row |
| Enter | Open the active row: the detail panel on wide screens, its own page below `lg` |
| Tab or Shift+Tab | Step through the active row's buttons, then leave the list |
| Space | Check or uncheck the row, where the list has checkboxes |
| `/` | Focus the list's filter or search field, if it has one (Labs, Vitals) |
| Esc | From a row's button, go back to its row |

### The rules behind it

- **The list is one Tab stop.** Rows use a roving `tabindex`: the active row has `0`, every
  other row `-1`. In a table the active row also has `aria-selected="true"`, and native table
  semantics stay. The list form (timelines, card stacks) marks its active item with
  `aria-current="true"` instead, because `aria-selected` is not allowed on a list item.
- **Only the active row's buttons are in the Tab order.** Every button, link or field inside
  any other row gets `tabindex="-1"`, updated whenever the active row changes or a row's
  content changes. So Tab goes: active row → its buttons in visual order → the next region of
  the page. Inline edit and delete rows under a table row are not part of the row and stay in
  the Tab order while they are open.
- **Arrow keys always move rows.** Pressing ↑ or ↓ (or J, K, Home, End) while one of the row's
  buttons has focus moves the active row and puts focus on the new row, not on the same button
  in the next row. Esc from a button goes back to its row. Keys typed into a field inside a row
  are left alone.
- **Mouse and keyboard share one selection.** Clicking a row, or focusing or clicking a button
  on a row, makes it the active row. A click on a row opens it unless it lands on a button,
  link or field in the row.
- **Buttons are named for their row**, e.g. "Resolve Seasonal allergic rhinitis", "Edit
  Metformin", "End now: Metformin", "Remove" with the person's name, so a screen reader says
  what will change.
- **The detail panel follows the active row.** While the panel is open, moving the active row
  opens that record in the panel after the keys have settled for 150ms, and the URL is updated
  with `replaceState`, so browser Back doesn't replay every arrow press. Enter and clicks push
  a history entry as before.
- **Closing the panel puts focus back on its row.** Esc on the row or anywhere in the panel
  (outside a field) closes it, and focus returns to the row that was open. Esc on one of the
  row's buttons only goes back to the row; a second Esc closes the panel.
- **The active row has a visible focus ring:** a 2px outline in `--ring` while it has keyboard
  focus. The row whose record is open in the panel is highlighted with `--accent`.
- **Scrolling a row into view respects `prefers-reduced-motion`:** smooth by default, instant
  when reduced motion is requested.

## Anywhere

| Key | Action |
|---|---|
| ⌘K / Ctrl+K | Search this patient; outside a patient's record, jump to a section or a patient. Pressed again, closes the palette |
| `[` | Collapse or expand the sidebar (counts as the manual sizing choice). In a patient's record |
| `?` | Show the keyboard shortcuts dialog |
| Esc | Close the detail panel and return to its row. In a patient's record, while a panel is open |

### Go to (after G), in a patient's record

Press G, then the letter within 1.2 seconds. Any other second key cancels the sequence and is
handled on its own.

| Letter | Section |
|---|---|
| R | Review **(planned)**: listed and active once the Review page exists |
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
  contenteditable element, or while a dialog is open. The dialog's own Esc still closes it, and
  ⌘K still closes the search palette from its search field.
- **No single key changes the record.** Approving, rejecting, resolving, editing and deleting
  always take a deliberate action: a focused button and Enter or Space, never a bare letter.
- **One registration point.** The shell builds every binding in `usePhrShortcuts`, and
  `useShortcutRegistry` (`resources/js/lib/shortcuts.ts`) installs the only global `keydown`
  listener. The list keys are handled by the list itself while it has focus, but they are
  defined once (`recordListKeys.ts`), matched from that definition, and listed through the
  registry.
- **The shortcuts dialog is a real dialog** (Base UI, with a focus trap). It renders the
  registry's bindings, so it lists exactly the keys that are bound in the current context: the
  G letters and `[` appear only in a patient's record.

## Tests

**(shipped)** The behavior is covered by tests that drive the real keys:

- `RecordTable.test.tsx`: arrow, J/K, Home and End movement; Enter opens; Tab from the active
  row reaches its first button, then its second, then leaves the table, and Shift+Tab walks
  back, with buttons on inactive rows never reached; arrows from a button move to the next row;
  Esc from a button; Space toggles a checkbox; the panel follows the active row, debounced, as
  a replace; focus returns to the row when the panel closes
- `ConditionsPage.keyboard.test.tsx`: in the real shell, the panel follows the active row through
  `replaceState`, and Esc returns focus to the row, from the row or from inside the panel
- `shortcuts.test.tsx` and `usePhrShortcuts.test.tsx`: shortcuts are ignored inside fields and
  while a dialog is open; the G sequence, including a timeout and an invalid second key; `[`
  toggles the sidebar mode; `/` focuses the filter; the `?` dialog lists exactly the registry's
  bindings
- `tests/e2e/phr.spec.ts`: arrows through the Conditions table in a browser, opens a condition
  with Enter, closes it with Esc and checks that focus is back on the row
