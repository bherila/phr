import type React from 'react'

interface PhrNotFoundColumnProps {
  message?: string
}

const DEFAULT_NOT_FOUND_MESSAGE = 'Record not found. It may belong to a different patient.'

export function PhrNotFoundColumn({
  message = DEFAULT_NOT_FOUND_MESSAGE,
}: PhrNotFoundColumnProps): React.ReactElement {
  return (
    <div className="flex h-full items-center justify-center p-8 text-center text-sm text-muted-foreground">
      {message}
    </div>
  )
}
