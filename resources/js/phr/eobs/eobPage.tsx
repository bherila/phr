import { createRoot } from 'react-dom/client'

import { EobDeleteAction, eobDeleteMountProps } from './EobDeleteAction'

// The EOB page is rendered on the server; only its delete action is interactive.
document.addEventListener('DOMContentLoaded', () => {
  const mount = document.getElementById('PhrEobActions')
  const props = mount ? eobDeleteMountProps(mount) : null
  if (!mount || props === null) {
    return
  }

  createRoot(mount).render(<EobDeleteAction {...props} />)
})
