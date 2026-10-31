import type React from 'react'

interface PhrRecordNotFoundProps {
  message?: string
}

const DEFAULT_NOT_FOUND_MESSAGE = 'Record not found. It may belong to a different patient.'

export function PhrRecordNotFound({
  message = DEFAULT_NOT_FOUND_MESSAGE,
}: PhrRecordNotFoundProps): React.ReactElement {
  return (
    <div className="flex h-full items-center justify-center p-8 text-center text-sm text-muted-foreground">
      {message}
    </div>
  )
}
