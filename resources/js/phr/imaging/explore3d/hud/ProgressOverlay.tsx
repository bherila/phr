import { Loader2 } from 'lucide-react'

import type { PipelinePhase } from '../pipeline/useVolumePipeline'

interface ProgressOverlayProps {
  phase: PipelinePhase
  progress: number
}

const PHASE_COPY: Record<Exclude<PipelinePhase, 'ready' | 'error' | 'idle'>, string> = {
  downloading: 'Downloading scan slices…',
  assembling: 'Stacking slices into a 3D volume…',
  meshing: 'Building the 3D surface…',
}

export function ProgressOverlay({ phase, progress }: ProgressOverlayProps) {
  if (phase === 'ready' || phase === 'error' || phase === 'idle') {
    return null
  }

  return (
    <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
      <div className="flex w-72 flex-col items-center gap-3 rounded-lg bg-black/80 p-6 text-white backdrop-blur">
        <Loader2 className="size-6 animate-spin" />
        <p className="text-sm">{PHASE_COPY[phase]}</p>
        {phase === 'downloading' && (
          <div className="h-1.5 w-full overflow-hidden rounded-full bg-white/20">
            <div className="h-full bg-sky-400 transition-[width]" style={{ width: `${Math.round(progress * 100)}%` }} />
          </div>
        )}
        <p className="text-center text-xs text-white/60">
          Your scan is processed entirely in your browser — it never leaves your account.
        </p>
      </div>
    </div>
  )
}
