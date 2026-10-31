import { useEffect, useState } from 'react'

import { genAiExecutionMode } from '@/lib/appShell'

import { type ExecutionMode, getExecutionMode } from './aiImportsApi'

/**
 * Where this user's AI work goes, read from the server when the page mounts. The
 * shell's value is only the first guess: the mode can change in Settings, or when
 * a saved key moves the default, without a full reload.
 */
export function useExecutionMode(): ExecutionMode {
  const [mode, setMode] = useState<ExecutionMode>(genAiExecutionMode)

  useEffect(() => {
    let cancelled = false
    getExecutionMode().then((current) => {
      if (!cancelled) setMode(current.mode)
    }).catch(() => {
      // Keep the shell's value.
    })

    return () => {
      cancelled = true
    }
  }, [])

  return mode
}
