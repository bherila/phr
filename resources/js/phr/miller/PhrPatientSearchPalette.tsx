import { FileText, Loader2, Search } from 'lucide-react'
import type { ReactElement } from 'react'
import { useEffect, useMemo, useState } from 'react'

import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import type { MillerDrillTarget } from '@/components/ui/miller'
import { fetchWrapper } from '@/fetchWrapper'
import type { PhrModuleId } from '@/phr/miller/phrModuleRegistry'
import { errorMessage } from '@/phr/shared'
import { PhrPatientSearchResponseSchema, type PhrPatientSearchResult } from '@/phr/types'

interface PhrPatientSearchPaletteProps {
  open: boolean
  patientId: number
  onClose: () => void
  onDrill: (target: MillerDrillTarget<PhrModuleId>) => void
}

export function PhrPatientSearchPalette({ open, patientId, onClose, onDrill }: PhrPatientSearchPaletteProps): ReactElement {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<PhrPatientSearchResult[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const trimmed = query.trim()
    if (trimmed.length < 2) {
      return
    }

    let cancelled = false
    const timeout = window.setTimeout(async () => {
      setBusy(true)
      setError(null)
      try {
        const raw: unknown = await fetchWrapper.get(`/api/phr/patients/${patientId}/search?q=${encodeURIComponent(trimmed)}`)
        if (!cancelled) setResults(PhrPatientSearchResponseSchema.parse(raw).results)
      } catch (caught) {
        if (!cancelled) {
          setResults([])
          setError(errorMessage(caught))
        }
      } finally {
        if (!cancelled) setBusy(false)
      }
    }, 150)

    return () => {
      cancelled = true
      window.clearTimeout(timeout)
    }
  }, [patientId, query])

  const groups = useMemo(() => {
    const visibleResults = query.trim().length >= 2 ? results : []

    return visibleResults.reduce<Record<string, PhrPatientSearchResult[]>>((all, result) => {
      const group = all[result.category] ??= []
      group.push(result)
      return all
    }, {})
  }, [query, results])

  function select(result: PhrPatientSearchResult): void {
    onClose()
    onDrill({ id: result.module_id as PhrModuleId, instance: String(result.id) })
  }

  const emptyMessage = error ?? (query.trim().length < 2 ? 'Type at least two characters to search this patient.' : busy ? 'Searching…' : 'No matching records.')

  return (
    <CommandDialog
      open={open}
      onOpenChange={(nextOpen) => { if (!nextOpen) onClose() }}
      title="Search this patient"
      description="Search structured clinical records and extracted document text for the selected patient."
      commandProps={{ shouldFilter: false }}
    >
      <CommandInput value={query} onValueChange={setQuery} placeholder="Search visits, clinicians, notes, studies, and documents…" />
      <CommandList>
        <CommandEmpty>{emptyMessage}</CommandEmpty>
        {busy && results.length > 0 && <div className="px-3 py-2 text-xs text-muted-foreground"><Loader2 className="mr-1 inline size-3 animate-spin" />Updating results…</div>}
        {Object.entries(groups).map(([category, group]) => (
          <CommandGroup key={category} heading={category}>
            {group.map((result) => (
              <CommandItem key={`${result.module_id}-${result.id}`} value={`${result.module_id}-${result.id}`} onSelect={() => select(result)}>
                {result.category === 'Document' ? <FileText className="size-4" /> : <Search className="size-4" />}
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium">{result.label}</span>
                  {(result.description || result.date) && <span className="block truncate text-xs text-muted-foreground">{[result.description, result.date].filter(Boolean).join(' · ')}</span>}
                </span>
              </CommandItem>
            ))}
          </CommandGroup>
        ))}
      </CommandList>
    </CommandDialog>
  )
}
