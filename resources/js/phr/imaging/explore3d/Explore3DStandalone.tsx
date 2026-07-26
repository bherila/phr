import { Loader2 } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'

import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { type PhrDicomVolumeManifestResponse, PhrDicomVolumeManifestResponseSchema } from '@/phr/types'

import { Explore3DViewer } from './Explore3DViewer'

const INELIGIBILITY_COPY: Record<string, string> = {
  unsupported_modality: 'Only CT and MRI scans contain the 3D slice data this viewer needs.',
  missing_geometry: 'The images in this series don’t include the position information needed to rebuild a 3D volume.',
  unsupported_transfer_syntax: 'The images use a compression format this viewer can’t decode yet.',
  not_monochrome: 'This viewer only supports grayscale scan images.',
  too_few_slices: 'This series doesn’t have enough slices to rebuild a meaningful 3D volume.',
  inconsistent_dimensions: 'The slices in this series have mismatched sizes, so they can’t be stacked into one volume.',
  inconsistent_pixel_spacing: 'The slices in this series have mismatched resolutions, so they can’t be stacked into one volume.',
  inconsistent_bits_allocated: 'The slices in this series store pixels in mismatched formats.',
  duplicate_positions: 'Multiple slices share the same position, so a clean 3D stack can’t be built.',
}

function ineligibilityMessage(reason: string): string {
  return INELIGIBILITY_COPY[reason] ?? `This series can’t be shown in 3D (${reason.replaceAll('_', ' ')}).`
}

interface Explore3DStandaloneProps {
  patientId: number
  seriesId: number
}

function FullScreenCard({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex h-dvh w-full items-center justify-center bg-black p-6 text-white">
      <div className="w-full max-w-md space-y-3 rounded-lg bg-white/5 p-6">{children}</div>
    </div>
  )
}

export function Explore3DStandalone({ patientId, seriesId }: Explore3DStandaloneProps) {
  const [manifest, setManifest] = useState<PhrDicomVolumeManifestResponse | null>(null)
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const close = useCallback(() => {
    window.close()
    /* window.close() is a no-op for tabs the browser won't let a script close;
     * fall back to the patient record so the user is never stranded. */
    window.setTimeout(() => {
      window.location.href = `/phr/patient/${patientId}`
    }, 150)
  }, [patientId])

  useEffect(() => {
    let cancelled = false
    void (async () => {
      try {
        const result = await fetchPhrDetail(
          `/api/phr/patients/${patientId}/dicom/series/${seriesId}/volume-manifest`,
          PhrDicomVolumeManifestResponseSchema,
        )
        if (cancelled) return
        if (result.notFound || !result.data) {
          setError('This scan series could not be found, or you don’t have access to it.')
          return
        }
        setManifest(result.data)
      } catch (caught: unknown) {
        if (!cancelled) setError(errorMessage(caught))
      } finally {
        if (!cancelled) setBusy(false)
      }
    })()
    return () => {
      cancelled = true
    }
  }, [patientId, seriesId])

  if (busy) {
    return (
      <FullScreenCard>
        <div className="flex items-center gap-2 text-sm">
          <Loader2 className="size-4 animate-spin" />
          Loading scan…
        </div>
      </FullScreenCard>
    )
  }

  if (error) {
    return (
      <FullScreenCard>
        <p className="text-sm font-medium">Couldn’t open the 3D view</p>
        <p className="text-xs text-white/70">{error}</p>
        <a href={`/phr/patient/${patientId}`} className="inline-block text-xs text-sky-400 underline">
          Back to record
        </a>
      </FullScreenCard>
    )
  }

  if (!manifest) {
    return null
  }

  if (!manifest.eligible || !manifest.volume) {
    return (
      <FullScreenCard>
        <p className="text-sm font-medium">This series can’t be explored in 3D</p>
        {(manifest.reasons.length > 0 ? manifest.reasons : ['missing_geometry']).map((reason) => (
          <p key={reason} className="text-xs text-white/70">
            {ineligibilityMessage(reason)}
          </p>
        ))}
        <a href={`/phr/patient/${patientId}`} className="inline-block text-xs text-sky-400 underline">
          Back to record
        </a>
      </FullScreenCard>
    )
  }

  return <Explore3DViewer patientId={patientId} manifest={manifest} onClose={close} />
}
