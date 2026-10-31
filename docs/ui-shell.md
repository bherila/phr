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

Status colors should carry meaning through a dot next to text in the foreground color, not
through colored text: light-mode `--warning` is only 3.3:1 against white. **(planned)** Existing
status badges still use tinted text; they move to the dot as each page is revisited.

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

At most three panes. Nothing stacks beyond them. **(shipped)**

- **Top bar.** The PHR wordmark, ⌘K search, the top-level sections with the current one
  marked, and the account menu. There is no link out of PHR. Inside a patient's record the
  Patients link is marked as the current location (`aria-current="true"`), because the
  sidebar item carries `aria-current="page"`. **(shipped)**
- **Sidebar.** Shown only in a patient's context. It holds the patient switcher, which keeps
  the page you are on when you switch, then: **(shipped)**
  - **Overview:** Review, Summary, Health Log
  - **Clinical:** Labs, Vitals, Visits, Medications, Conditions, Procedures, Immunizations, Allergies
  - **Records:** Documents, Imaging
  - **Sharing:** Access

  Each item is a real link with an icon and a label. The current item uses `--accent` with
  `--accent-foreground` and `aria-current="page"`. **(shipped)**

  Counts and dots come from one request per patient, `GET /api/phr/patients/{id}/review/summary`,
  shared by the sidebar and its small-screen sheet. **(shipped)**
  - Review shows how many items wait in all: pending records, AI import proposals and
    reviewable EHI export proposals.
  - A section shows how many records it lists (standing, not rejected), and a `--warning`
    dot when some wait for review: its own records in `pending_review`, plus EHI proposals
    whose records land there (Immunizations, Documents). AI proposals are not records of any
    section yet, so they count only toward Review.
  - The count and the dot are hidden from assistive technology and said in the item's name
    instead, after its visible label ("Medications, 12 records, 2 waiting for review").
  - Every decision path (a section's review buttons, the Review page, the Imports page's AI
    and EHI reviews) fetches the summary again, so the counts follow without a reload. The
    Review page's own listing carries the summary, so opening it costs no second request.

  Section pages (Imports, Data Hub, Config, Manage Patients, Patients) have no patient
  sidebar. **(shipped)**
- **Page.** A breadcrumb (`Patient / Group / Section [/ Item]`), then the page's own title and
  actions, and the content at full width. Tables get the room they need, and wide tables
  scroll inside their own box rather than squeezing their columns. **(shipped)**
- **Detail panel.** Selecting a row opens its detail in a panel to the right of the list:
  380px from the `lg` breakpoint, 420px from `xl`. Selecting another row swaps the panel's
  contents. The panel has a close link (and Esc closes it). Below `lg` the detail opens as its
  own page instead, with the breadcrumb and the close link back to the list. **(shipped)**

### Sidebar sizing

The sidebar's width depends on the context: **(shipped)**

| Mode | Width | When |
|---|---|---|
| Expanded | 248px | Default on desktop |
| Rail | 64px, icons only | Automatic on full-width views (document viewer, imaging study, vitals trend), and when a detail panel is open on a viewport narrower than 1440px, so the list keeps at least 560px |
| Hidden | — | Below `md`: a menu button opens the sidebar as a sheet |

A manual toggle (the button at the top of the sidebar) overrides the automatic choice. The
choice is remembered per signed-in person and per context type (a normal page vs a full-width
view), in `localStorage` behind `try`/`catch`; without storage it lasts for the visit. Rail
items keep an `aria-label` and a tooltip. **(shipped)** The `[` key as a second way to toggle
is part of [keyboard navigation](keyboard-navigation.md). **(planned)**

The 3D imaging explorer is a separate page outside the shell, so it has no sidebar.

### Deeper drill-downs

No view needs more than nav, content and detail. **(shipped)**

- **Visit → Visit detail → linked imaging study.** The study replaces the visit in the detail
  panel, with a "← Visit" link back.
- **Visits timeline entries that are not visits** (allergy-shot procedures, immunizations) open
  in the Visits panel too, so the list you were reading stays in place.
- **Document viewer, imaging study and vitals trend.** These open full-width as their own pages
  under the sidebar, which drops to the rail. They are too cramped in a side panel.
  - The document viewer has **one toolbar row** (`role="toolbar"`), left to right: the title,
    truncated, with type, source, added date, and format and page count as a small line under
    it; page controls; zoom controls; a divider; then the actions. The actions are Send to AI
    (for people who can manage the record), an icon-only "Open in new tab" with an accessible
    name and tooltip, and Download as the primary button.
  - Controls appear only where they apply: page arrows for PDFs with more than one page, zoom
    for PDFs and images, neither for HTML or text.
  - At narrow widths the row wraps, putting the page and zoom controls under the title.
  - The preview and the side column ("Records from this document", links to the records the
    document produced) start at the same height.
- **Cross-section jumps** (Conditions or Immunizations → Documents, Imaging → Imports, Allergy
  detail → Allergies) are ordinary navigation, each with its own history entry.

## URLs

Every view has a real path, so browser Back and Forward, reloads and shared links work:
**(shipped)**

| Path | View |
|---|---|
| `/phr/patients`, `/phr/patients/manage`, `/phr/imports`, `/phr/data-hub`, `/phr/config` | Top-level sections. Their query string is part of the view, e.g. `/phr/imports?patient_id={id}` preselects a patient |
| `/phr/patient/{patientId}` | The patient's Summary |
| `/phr/patient/{patientId}/{section}` | A record section. The segment is the section's id: `review`, `summary`, `health-log`, `labs`, `vitals`, `office-visits` (Visits), `medications`, `conditions`, `procedures`, `immunizations`, `allergies`, `documents`, `imaging`, `access` |
| `/phr/patient/{patientId}/{section}/{recordId}` | That section with one of its own records open |
| `/phr/patient/{patientId}/{section}/{kind}/{recordId}` | A record the section shows although it is another type: `office-visits/procedure/{id}`, `office-visits/immunization/{id}`, `vitals/trend/{metric}` |
| `/phr/patient/{patientId}/office-visits/{visitId}/imaging/{studyId}` | A visit's linked imaging study, in place of the visit |

The URL is the only source of truth for where you are. The patient, the section and the open
record are all read from it on every change, so they can never disagree, and Back and Forward
restore all three together. Every in-app link is a real `href`: opening it in a new tab works,
and a plain click navigates in place without reloading the page (so uploads in progress keep
running). Pages that are separate documents (EOBs, the 3D explorer, downloads) load normally.

The server answers each of these paths with the shell, with the same patient access check as
the patient's record, and only for known section ids.

Old Miller URLs (`/phr/patient/{id}#/…`) are rewritten in the browser to the matching new path
with `replaceState`, so existing bookmarks keep working and Back does not revisit the old form.
Each old column is replayed as the navigation it stood for, so
`#/office-visits/office-visit-detail:4/imaging-study-detail:3` lands on
`/phr/patient/{id}/office-visits/4/imaging/3`.

## Phone

- The sidebar becomes a menu button in the top bar that opens a sheet with the patient
  switcher and the sections. The sheet closes once you go somewhere. The top-level links stay
  in the top bar, which wraps. **(shipped)**
- A record opens as its own page, with the breadcrumb and a close link back to its section.
  **(shipped)**
- Tables become lists of tappable cards. Filter tabs scroll sideways. **(planned)**
- Approve and Reject sit in a full-width bar on a record's page. **(planned)**
- Every tap target is at least 44px. **(planned)**

## Review

`/phr/patient/{id}/review` lists everything waiting for the patient's review on one page.
**(shipped)** It reads `GET /api/phr/patients/{id}/review` and decides nothing itself: each
approve or reject goes to the route that already owns that kind of proposal, with that
route's own authorization and conflict checks.

| Origin | Approve / reject | Conflict check |
|---|---|---|
| Clinical records in `pending_review` (C-CDA, FHIR and MyChart imports; connected apps) | The section's `PATCH …/{record}/review` with `confirmed` / `rejected` | The record's opaque version as listed; a changed record is a 409 |
| AI import proposals | The Imports page's `…/ai-imports/{import}/results/{result}/accept` and `/reject` (`PhrImportReviewService`, as the agent API's `imports.review`) | A proposal already decided is a 409 |
| EHI export proposals | `…/ehi/proposals/{proposal}/review` (`EhiProposalService`) | The proposal's preview digest, the source revisions and the target record |

- **Groups.** By source: records by import source and source document, one group per AI
  import, one per EHI export. A switch groups the same rows by section instead.
- **Group header.** The origin and its name (file, document or source label), the date,
  how it was read ("parsed by PHR, no AI", or where the AI ran), a chip per section with its
  count, **Reject all** and **Approve N selected**.
- **AI marker.** AI-read groups carry "AI-read: check against the document".
- **Rows.** A plain table, one component (`resources/js/phr/review/ReviewPage.tsx`): a
  checkbox, what is proposed, its section, the change with a dot, and an action. **Open**
  goes to the record (or, for an AI proposal, its document); **Compare** goes to the EHI
  export's own review, which shows the record already in PHR.
- **Change kinds.**
  - **New**: not in the record yet. AI proposals read as New: accepting adds them.
  - **Updates existing**: an EHI proposal shows the changed fields ("Lot number L1 → L2").
    A clinical record whose source changed it after it was added (which reopened its review)
    has no field-level difference to show, because PHR does not keep its earlier values.
  - **Possible duplicate**: only from the EHI export's own duplicate detection; nothing
    else guesses. These start unchecked, since approving one adds a separate record.
  - **Already in PHR** and **Evidence only**: EHI proposals that add the export as evidence
    of an existing record, or keep a row with the export without adding anything.
- **Batches.** A batch asks for confirmation with its count, then decides each item in
  turn. A refusal (a record changed since the page loaded, a proposal already decided) does
  not stop the rest; it is shown on its row, and the list reloads.
- **Limits.** Up to 200 rows per section, AI import or EHI export; a group with more says
  so and links to the import's own review.
- **View only.** A reader without write access sees the page with every checkbox and
  button disabled.
- **Empty.** "Nothing waiting for review".
- No single-key approve shortcut exists, by design.

## Patient selection

`/phr/patients` shows a card per patient the signed-in person can open. **(shipped)**

- **Card.** Initials, name, your relationship ("You" for your own profile, "You manage this
  record" for one you own for someone else, "Shared with you" for one shared with you, which
  is also marked "View only" when you cannot edit it), its record count, its last update,
  and "N waiting for review".
- **Needs your attention.** Below the cards, across patients: records ready to review, EHI
  exports and finished AI extractions with proposals to review, and AI extractions and EHI
  uploads that failed in the last 30 days. Ready items come first and link to the patient's
  Review page; failures link to the Imports page.
- **Data.** Names and access come from the shared patient list. The counts are one request,
  `GET /api/phr/patients/overview`, built from existing import and AI-job state with a fixed
  number of queries however many patients there are (up to 100).

## Planned screens

These are designed (see the design canvas) but not built:

- **Document viewer provenance** (planned). Each record in the viewer's side column shows its
  review status, and a provenance section lists the document's source, who added it, the
  original file, and the file hash.
