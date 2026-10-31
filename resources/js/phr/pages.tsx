import { createRoot } from 'react-dom/client'

import { legacyHashUrl } from '@/phr/shell/phrRoutes'
import { PhrShell } from '@/phr/shell/PhrShell'

document.addEventListener('DOMContentLoaded', () => {
  const mount = document.getElementById('PhrShell')
  if (!mount) {
    return
  }

  // Bookmarks from the old column shell (/phr/patient/12#/labs/lab-panel-detail:5) move to the
  // path they now live at before the first render, without adding a history entry.
  const target = legacyHashUrl(window.location.pathname, window.location.search, window.location.hash)
  if (target !== null) {
    window.history.replaceState(null, '', target)
  }

  createRoot(mount).render(<PhrShell />)
})
