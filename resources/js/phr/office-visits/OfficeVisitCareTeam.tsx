import { Stethoscope } from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import type { PhrOfficeVisitParticipant } from '@/phr/types'

/** HL7 v3 ParticipationType roles (PhrVisitParticipants::ROLES). PART names no role of its own. */
const ROLE_LABELS: Record<string, string> = {
  ATND: 'Attending',
  CON: 'Consultant',
  ADM: 'Admitting',
  DIS: 'Discharging',
  PPRF: 'Primary performer',
  SPRF: 'Secondary performer',
}

/** "Alex Attending, MD": the name, and the credentials unless the name already ends with them. */
export function participantName(member: PhrOfficeVisitParticipant): string {
  const credentials = member.credentials?.trim() ?? ''
  const name = member.name.toLowerCase()
  const suffix = credentials.toLowerCase()
  if (credentials === '' || name.endsWith(` ${suffix}`) || name.endsWith(`,${suffix}`)) {
    return member.name
  }

  return `${member.name}, ${credentials}`
}

/** The source's own words for the role, else the code's. */
export function participantRole(member: PhrOfficeVisitParticipant): string | null {
  const label = member.role_label?.trim() ?? ''

  return label !== '' ? label : (ROLE_LABELS[member.role_code] ?? null)
}

/** A member whose time on the team has ended. Times are local wall clocks ("2026-02-10 07:00:00"). */
export function participantIsInactive(member: PhrOfficeVisitParticipant, now: Date = new Date()): boolean {
  if (!member.period_end) {
    return false
  }
  const ended = new Date(member.period_end.replace(' ', 'T'))

  return !Number.isNaN(ended.getTime()) && ended.getTime() <= now.getTime()
}

interface OfficeVisitCareTeamProps {
  participants: PhrOfficeVisitParticipant[]
}

/** The visit's care team; a visit that records none (most office visits) shows nothing. */
export default function OfficeVisitCareTeam({ participants }: OfficeVisitCareTeamProps) {
  if (participants.length === 0) {
    return null
  }

  return (
    <section className="rounded-lg border border-border bg-card p-4">
      <div>
        <h2 className="flex items-center gap-2 font-semibold text-card-foreground">
          <Stethoscope className="size-4 text-primary" />
          Care team
        </h2>
        <p className="mt-1 text-sm text-muted-foreground">
          The people who took part in this visit, as the source listed them.
        </p>
      </div>
      <ul className="mt-4 divide-y divide-border rounded-md border border-border">
        {participants.map((member) => {
          const role = participantRole(member)
          const inactive = participantIsInactive(member)
          const details = [role, member.specialty].filter((part): part is string => Boolean(part && part.trim()))

          return (
            <li key={member.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
              <div className="min-w-0">
                <p className={inactive ? 'text-sm font-medium text-muted-foreground' : 'text-sm font-medium text-card-foreground'}>
                  {participantName(member)}
                </p>
                {details.length > 0 && <p className="text-xs text-muted-foreground">{details.join(' · ')}</p>}
              </div>
              <div className="flex flex-wrap gap-1">
                {member.is_primary && <Badge variant="secondary">Primary</Badge>}
                {inactive && <Badge variant="outline">Inactive</Badge>}
              </div>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
