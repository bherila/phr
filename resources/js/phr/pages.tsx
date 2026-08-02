import { createRoot } from 'react-dom/client'

import type { PhrSection } from '@/lib/phrRouteBuilder'
import { PhrMillerShell } from '@/phr/miller'

const PHR_SECTIONS: readonly PhrSection[] = ['patients', 'manage-patients', 'data-hub', 'imports', 'config']

const SECTION_HASH: Record<PhrSection, string> = {
  patients: '#/patients',
  'manage-patients': '#/patients-manage',
  'data-hub': '#/data-hub',
  imports: '#/imports',
  config: '#/config',
}

function isPhrSection(value: string | undefined): value is PhrSection {
  return PHR_SECTIONS.includes(value as PhrSection)
}

function parsePatientId(value: string | undefined): number | undefined {
  if (!value) {
    return undefined
  }
  const parsed = Number.parseInt(value, 10)
  return Number.isNaN(parsed) ? undefined : parsed
}

function patientIdFromPath(): number | undefined {
  const match = window.location.pathname.match(/^\/phr\/patient\/(\d+)$/)
  return match ? Number.parseInt(match[1]!, 10) : undefined
}

document.addEventListener('DOMContentLoaded', () => {
  const mount = document.getElementById('PhrShell') ?? document.getElementById('PhrNavbar')
  if (!mount) {
    return
  }

  const patientId = parsePatientId(mount.dataset.patientId) ?? patientIdFromPath()
  const activeSection = isPhrSection(mount.dataset.activeSection) ? mount.dataset.activeSection : undefined
  const backUrl = mount.dataset.backUrl

  // Section routes (e.g. /phr/imports) seed the matching column into the hash so the single
  // hash-routed shell boots to the right place. Patient routes leave the hash untouched.
  if (!window.location.hash && activeSection) {
    window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}${SECTION_HASH[activeSection]}`)
  }

  createRoot(mount).render(
    <PhrMillerShell
      {...(patientId !== undefined ? { initialPatientId: patientId } : {})}
      {...(backUrl ? { backUrl } : {})}
    />,
  )
})
