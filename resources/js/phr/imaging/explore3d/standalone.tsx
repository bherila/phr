import { createRoot } from 'react-dom/client'

import { Explore3DStandalone } from './Explore3DStandalone'

const root = document.getElementById('explore3d-root')
if (root) {
  const patientId = Number(root.dataset.patientId)
  const seriesId = Number(root.dataset.seriesId)
  if (Number.isFinite(patientId) && Number.isFinite(seriesId)) {
    createRoot(root).render(<Explore3DStandalone patientId={patientId} seriesId={seriesId} />)
  }
}
