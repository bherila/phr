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
through colored text: light-mode `--warning` is only 3.3:1 against white. The review status
(pending review, confirmed, rejected) is shown that way everywhere, through the shared
`StatusDot`. **(shipped)** Other status badges (clinical status, criticality) still use tinted
text; they move to the dot as each page is revisited. **(planned)**

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
  actions, and the content. Page content (breadcrumb, header, toolbar, list) stops at 1280px
  (`max-w-[80rem]`), aligned to the start beside the sidebar rather than centered, so wide
  screens do not stretch it. Full-width viewers (document, imaging study, vitals trend, the 3D
  explorer) are exempt. Wide tables scroll inside their own box rather than squeezing their
  columns. **(shipped)**
- **Detail panel.** Selecting a row opens its detail in a panel to the right of the list, from
  the `lg` breakpoint, `clamp(400px, 32vw, 640px)` wide. When it opens, the list gives up width
  first; list and panel together may exceed 1280px, up to the window. Selecting another row
  swaps the panel's contents, and the row whose record is open is highlighted. While the panel
  is open, moving the active row with the keyboard shows that record (debounced, with
  `replaceState`). The panel has a close link, and Esc closes it and puts focus back on the
  row. Below `lg` the detail opens as its own page instead, with the breadcrumb and the close
  link back to the list. **(shipped)**
  - The panel is a size container. Content with a multi-column table adapts to the width it
    gets: the Lab Panel's results stack below 520px (name and value on the first line, range,
    flag and trend on the second) and are a table above it. **(shipped)**
- **Record lists.** Every section's table or list is the shared record table or its list form
  (`resources/js/components/record-list`), with the keyboard model in
  [keyboard navigation](keyboard-navigation.md). **(shipped)** Its column rules:
  - Short columns (value, unit, flag, date, status, review, actions) are `fit` columns: they
    size to their content and stay on one line (`width: 1%; white-space: nowrap`). The name
    column takes the rest.
  - Text is left-aligned. Only numeric values right-align, with tabular figures; a column can
    decide per row, so a lab value of "Not Detected" sits next to its analyte while "112"
    lines up with the other numbers.
  - The review status sits on the row's line as a dot and a label (beside the review actions,
    or after the name), not as a pill under the name.
- **Loading.** Nothing moves when data arrives. **(shipped)**
  - On the first load a record list renders skeleton rows in its real columns and row classes
    (8, or the expected count), with `aria-busy="true"` on the region, one visually hidden live
    message ("Loading conditions"), `aria-hidden` skeleton blocks, and no pulse when
    `prefers-reduced-motion` is set.
  - A refetch after a change or a filter keeps the current rows on screen.
  - Detail panels show a skeleton of their heading, fact lines and result rows; a lazily
    loaded page shows a skeleton with its own title. The top bar, sidebar and breadcrumb render
    from the URL at once.
  - Controls that depend on the person's access (the Add buttons) start from the patient list
    the shell already holds, so they do not appear late and push the list down. A Playwright
    check keeps the Conditions page's layout shift under 0.02 between skeleton and table.

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
items keep an `aria-label` and a tooltip. The `[` key toggles it too, as the same manual
choice; see [keyboard navigation](keyboard-navigation.md). **(shipped)**

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

## Initial data preload

The shell page carries the first responses of the view it opens on, so that view renders without
waiting for its own round trips. **(shipped)**

- **What.** `<script type="application/json" id="phr-preload">` holds a map from each GET URL the
  page sends on first load, written exactly as the browser writes it, to the JSON body that URL
  returns for the signed-in person. On a patient view that is the current page's list request
  (with its default query), the open record's detail, and the Health Log's first log entries.
  Every patient view also gets the sidebar's review counts (`/review/summary`), and Review gets
  its queue. Summary and Imaging also get the patient record they fetch. Patients gets the
  selection cards' overview, Data Hub its inventory, deletions and restores, and Config its
  default AI Providers tab. Nothing is preloaded for
  sections not on screen, nor for the list under a full-width detail (document viewer, imaging
  study, vitals trend). The patient list already ships in `app-initial-data` on every shell page,
  and Imports picks its patient in the browser, so neither is in the map.
- **One serialization path.** `PhrPreloadBuilder` matches each URL against the application's
  routes and runs that route's own controller action, with the page request's user and session,
  and keeps only a 200 JSON response. The action's own authorization (the patient access check,
  the record's patient scope, owner-only exports) decides what is inlined, and tests check every
  entry byte for byte against the API response for an owner, a manager and a viewer. Only routes
  on an explicit allow-list are dispatched, and only when every middleware they declare is one
  the page request already passed (the `web` group and `auth`) or throttling. `PhrPreloadPlan`
  lists the URLs and mirrors `phrRoutes.ts`. If the two drift, a view just loads from the network
  again, and the Playwright spec (`tests/e2e/phr-preload.spec.ts`) fails.
- **Single use.** At boot the client reads the element once into memory and removes it.
  `fetchWrapper.get` and `getRaw` (and so `fetchPhrDetail`) look a URL up by same-origin path plus
  sorted query string. A hit is removed and answered as a 200 JSON `Response`, which callers
  cannot tell from a fetched one; a miss goes to the network. Every unused entry is dropped on an
  in-app navigation, Back or Forward, any `fetchWrapper` write, `reviewStateChanged`, a restore
  from the back/forward cache (`pageshow` with `persisted`), or 30 seconds after boot. A refetch, or a later visit,
  therefore always reaches the network.
- **Security.**
  - The map is encoded with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`, so no
    value can close the element (`</script>`) or open a comment (`<!--`).
  - Shell responses are `Cache-Control: private, no-store, max-age=0` with `Pragma: no-cache`.
  - Someone without access to the patient gets the same 404 page as before, with no preload.
  - An entry that would take the map past 256 KB is left out and loads from the network.

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
- **AI proposals are approved one at a time, after they are read.** Accepting an AI
  proposal imports every value it holds (a document's proposal can carry many nested
  records), so the rule is that none is approved before all of its values have been shown.
  An AI row has no checkbox and no Approve of its own; **Review** expands it to the same
  rendering the Imports page's AI review uses (every field and every nested record), and its
  Approve and Reject live there. "Approve N selected" never includes an AI proposal; a group
  of AI proposals has no such button. Reject all may still include them, since rejecting adds
  nothing. AI-read groups carry "AI-read: check against the document. Open each one and
  approve it on its own."

- **Rows.** Each group is the shared record table (`resources/js/phr/review/ReviewPage.tsx`):
  a checkbox, what is proposed, its section, the change with a dot, and an action. **Open**
  goes to the record; **Compare** goes to the EHI export's own review, which shows the
  record already in PHR; **Review** expands an AI proposal (above), with a link to its
  document. The list keyboard model applies: Space checks a row (never an AI proposal, which
  has no checkbox), and Enter does what the row's action does.
- **Change kinds.**
  - **New**: not in the record yet. AI proposals read as New: accepting adds them.
  - **Updates existing**: an EHI proposal shows the changed fields ("Lot number L1 → L2").
    A clinical record whose source changed it after it was added (which reopened its review)
    has no field-level difference to show, because PHR does not keep its earlier values.
  - **Possible duplicate**: only from the EHI export's own duplicate detection; nothing
    else guesses. These start unchecked, since approving one adds a separate record.
  - **Already in PHR** and **Evidence only**: EHI proposals that add the export as evidence
    of an existing record, or keep a row with the export without adding anything.
- **Batches.** Batches are for records and parsed imports, not AI proposals. A batch asks
  for confirmation with its count, then decides each item in turn. A refusal (a record changed since the page loaded, a proposal already decided) does
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
