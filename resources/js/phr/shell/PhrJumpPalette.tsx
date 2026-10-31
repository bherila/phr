import { UserRound } from 'lucide-react'
import type { ReactElement } from 'react'

import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { usePhrPatients } from '@/phr/patients/usePhrPatients'

import { pushPhrLocation } from './phrLocation'
import { patientPath, type PhrSection, sectionPath } from './phrRoutes'
import { patientDisplayName } from './PhrSidebar'

const SECTIONS: { section: PhrSection, label: string, keywords: string[] }[] = [
  { section: 'patients', label: 'Patients', keywords: ['patients', 'profiles', 'people'] },
  { section: 'manage-patients', label: 'Manage Patients', keywords: ['manage', 'add', 'edit', 'profiles'] },
  { section: 'imports', label: 'Imports', keywords: ['imports', 'upload', 'ccda', 'fhir', 'ehi'] },
  { section: 'data-hub', label: 'Data Hub', keywords: ['data', 'inventory', 'export', 'backup', 'restore', 'delete'] },
  { section: 'config', label: 'Config', keywords: ['config', 'settings', 'preferences', 'ai', 'devices'] },
]

interface PhrJumpPaletteProps {
  open: boolean
  onClose: () => void
}

/** ⌘K outside a patient record: jump to a section or open a patient. */
export function PhrJumpPalette({ open, onClose }: PhrJumpPaletteProps): ReactElement {
  const { patients } = usePhrPatients()

  function go(url: string): void {
    onClose()
    pushPhrLocation(url)
  }

  return (
    <CommandDialog
      open={open}
      onOpenChange={(nextOpen) => { if (!nextOpen) onClose() }}
      title="Jump to"
      description="Open a PHR section or a patient’s record"
    >
      <CommandInput placeholder="Jump to a section or patient…" />
      <CommandList>
        <CommandEmpty>No matches.</CommandEmpty>
        <CommandGroup heading="Sections">
          {SECTIONS.map((row) => (
            <CommandItem key={row.section} value={`section-${row.section}`} keywords={[row.label, ...row.keywords]} onSelect={() => go(sectionPath(row.section))}>
              {row.label}
            </CommandItem>
          ))}
        </CommandGroup>
        {patients.length > 0 && (
          <CommandGroup heading="Patients">
            {patients.map((patient) => {
              const name = patientDisplayName(patient.display_name, patient.id)
              return (
                <CommandItem key={patient.id} value={`patient-${patient.id}`} keywords={[name]} onSelect={() => go(patientPath(patient.id))}>
                  <UserRound className="size-4" aria-hidden="true" />
                  {name}
                </CommandItem>
              )
            })}
          </CommandGroup>
        )}
      </CommandList>
    </CommandDialog>
  )
}
