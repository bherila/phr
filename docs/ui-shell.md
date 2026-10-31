# PHR interface shell

How PHR's screens are laid out and navigated: the color tokens, the sidebar, the page and
detail-panel layout, URLs, and the phone layout. Keyboard behavior has its own document:
[keyboard navigation](keyboard-navigation.md).

This replaces the Miller-column shell, where every drill-down opened another column to the
right. That design hid the section list once a few columns were open, gave tables whatever
width their column happened to get, and kept location in two places (the URL path and a
`#/…` hash), which broke browser Back.

Status markers: **(shipped)** is in the code; **(planned)** is designed but not built yet.

## Color

Blue replaces gold as the primary color. Only these tokens in `resources/css/app.css`
changed; neutrals, status colors and type are as before. **(shipped)**

| Token | Light | Dark | Checked against |
|---|---|---|---|
| `--primary` | `215 70% 45%` | `213 80% 68%` | 5.7:1 on card (light), 7.4:1 (dark) |
| `--primary-foreground` | `0 0% 98%` | `0 0% 6%` | 5.4:1 / 7.7:1 on primary |
| `--accent` | `215 70% 95%` | `215 50% 16%` | selected and active fill |
| `--accent-foreground` | `215 65% 32%` | `213 80% 68%` | 7.8:1 / 6.2:1 on accent |
| `--ring` | `215 70% 45%` | `213 80% 68%` | same as primary |

Every text pairing must meet 4.5:1. The old light-mode gold did not (3.7:1 on white), which
is part of why it went.

Status colors carry meaning through a dot next to text in the foreground color, not through
colored text: light-mode `--warning` is only 3.3:1 against white.

## Layout

```
┌──────────────────────── top bar ────────────────────────┐
│ PHR   [Search this patient… ⌘K]   Patients · Manage · Imports · Data Hub · Config · ⓤ │
├──────────┬───────────────────────────────┬──────────────┤
│ sidebar  │ page                          │ detail panel │
│ (patient │ breadcrumb, title, actions,   │ (optional)   │
│  context)│ full-width list or content    │              │
└──────────┴───────────────────────────────┴──────────────┘
```

At most three panes. Nothing stacks beyond them.

- **Top bar.** The PHR wordmark, ⌘K search, the top-level sections with the current one
  marked, and the account menu. There is no link out of PHR.
- **Sidebar.** Shown only in a patient's context. It holds the patient switcher, then:
  - **Overview:** Review (planned), Summary, Health Log
  - **Clinical:** Labs, Vitals, Visits, Medications, Conditions, Procedures, Immunizations, Allergies
  - **Records:** Documents, Imaging
  - **Sharing:** Access

  Each item has an icon, a label, an optional count, and a warning dot when it has items
  waiting for review. The current item uses `--accent` with `--accent-foreground` and
  `aria-current="page"`. Section pages (Imports, Data Hub, Config, Manage Patients) have no
  patient sidebar.
- **Page.** A breadcrumb (`Patient / Group / Section [/ Item]`), the title, the page's actions
  at top right, and the content at full width. Tables get the room they need, and wide tables
  scroll inside their own box rather than squeezing their columns.
- **Detail panel.** Selecting a row opens its detail in a 320–420px panel to the right of the
  list. Selecting another row swaps the panel's contents. Below the `lg` breakpoint the detail
  opens as its own page instead.

### Sidebar sizing

The sidebar's width depends on the context:

| Mode | Width | When |
|---|---|---|
| Expanded | 248px | Default on desktop |
| Rail | 64px, icons only | Automatic on full-width views (document viewer, imaging study, 3D viewer), and when a detail panel is open below about 1440px, so the list keeps at least 560px |
| Hidden | — | Below `md`: a menu button opens the sidebar as a sheet |

A manual toggle (the sidebar button, or `[`) overrides the automatic choice. The choice is
remembered per context type (a normal page vs a full-width view), in `localStorage` behind
`try`/`catch`. Rail items keep an `aria-label` and a tooltip.

### Deeper drill-downs

No view needs more than nav, content and detail.

- **Visit → Visit detail → linked imaging study.** The study replaces the visit in the detail
  panel, with a "← Visit" link back.
- **Document viewer and imaging study.** These open full-width as their own pages under the
  sidebar, which drops to the rail. They are too cramped in a side panel.
  - The viewer has **one toolbar row**, left to right: the title (with type, source, date and
    page count as a small line under it), page controls, zoom controls, a divider, then the
    actions. The actions are Send to AI, an icon-only "Open in new tab" with an accessible name
    and tooltip, and Download as the primary button.
  - Controls appear only where they apply: page arrows for multi-page formats, zoom for PDFs
    and images, neither for HTML or C-CDA.
  - At narrow widths the page and zoom controls wrap under the title.
  - The preview and the side column start at the same height.
- **Cross-section jumps** (Conditions or Immunizations → Documents, Imaging → Imports, Allergy
  detail → Allergies) are ordinary navigation.

## URLs

Every view has a real path, so browser Back and Forward, reloads and shared links work:

- `/phr/patients`, `/phr/imports`, and the other top-level sections
- `/phr/patient/{patientId}/{section}`
- `/phr/patient/{patientId}/{section}/{recordId}` for a record's detail

The URL is the only source of truth for where you are. The patient, the section and the open
record are all read from it, so they can never disagree.

Old Miller URLs (`/phr/patient/{id}#/…`) redirect in the browser to the matching new path,
so existing bookmarks keep working.

## Phone

- The sidebar becomes a menu button that opens a sheet. The sheet holds the patient switcher,
  the sections, and the top-level links.
- Tables become lists of tappable cards. Filter tabs scroll sideways.
- A record opens as its own page with a back link to its section. Approve and Reject sit in a
  full-width bar.
- Every tap target is at least 44px.

## Planned screens

These are designed (see the design canvas) but not built:

- **Patient selection** (planned). A card per patient showing your relationship to them
  ("You", "You manage this record", "Shared with you", the last marked view only), a record
  count, the last update, and how many items wait for review. A "Needs your attention" list
  below collects ready imports, finished AI extractions and failed imports across patients.
- **Review** (planned). One page for every proposed change for the patient, grouped by where it
  came from (an import or an AI extraction), with a switch to group by section instead.
  - Each proposal is labeled **New**, **Updates existing** (with the change spelled out, e.g.
    "Dose 5 mg → 10 mg"), or **Possible duplicate**.
  - Batch approve applies to the checked items. Possible duplicates start unchecked.
  - AI-read items are flagged to be checked against their document.
  - This page needs an endpoint that returns pending items across all sections.
- **Document viewer provenance** (planned). The viewer's side column lists the records that came
  from the document, each with its review status, and the document's provenance: source, who
  added it, original file, and file hash.
