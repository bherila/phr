import type { ReactElement } from 'react'

export function labelize(value: string): string {
  return value.replaceAll('_', ' ')
}

export function codeChip(label: string, value: string | null): ReactElement | null {
  if (!value) {
    return null
  }

  return (
    <span
      title={`${label}: ${value}`}
      className="inline-flex rounded-full border border-border bg-background px-2 py-0.5 text-xs font-medium text-foreground"
    >
      {label} {value}
    </span>
  )
}

export function classBadge(value: string | null, classes: Record<string, string>): ReactElement | null {
  if (!value) {
    return null
  }

  return (
    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${classes[value] ?? 'bg-muted text-muted-foreground'}`}>
      {labelize(value)}
    </span>
  )
}
