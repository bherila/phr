'use client'

import { ArrowLeft, Command, Database, Search, Settings, UserRound } from 'lucide-react'
import { type MouseEvent, type ReactNode, useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { Button } from '@/components/ui/button'
import {
  Combobox,
  ComboboxContent,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
} from '@/components/ui/combobox'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  NavigationMenu,
  NavigationMenuItem,
  NavigationMenuLink,
  NavigationMenuList,
  navigationMenuTriggerStyle,
} from '@/components/ui/navigation-menu'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { fetchWrapper } from '@/fetchWrapper'
import { currentUser, relyingApplications } from '@/lib/appShell'
import type { PhrSection } from '@/lib/phrRouteBuilder'
import { phrSectionUrl } from '@/lib/phrRouteBuilder'
import { cn } from '@/lib/utils'
import { type PhrPatient, PhrPatientListResponseSchema } from '@/phr/types'

interface PhrNavbarProps {
  patientId?: number
  activeSection?: PhrSection
  children?: ReactNode
  className?: string
  /** Where the navbar's "back" affordance exits PHR to. Defaults to the parent site. */
  backUrl?: string
  onPatientChange?: (patientId: number) => void
  onSectionChange?: (section: PhrSection) => void
  onSearch?: () => void
}

const DEFAULT_BACK_URL = 'https://bherila.net'

export default function PhrNavbar({
  patientId,
  activeSection,
  children,
  className,
  backUrl = DEFAULT_BACK_URL,
  onPatientChange,
  onSectionChange,
  onSearch,
}: PhrNavbarProps) {
  const [patients, setPatients] = useState<PhrPatient[]>([])
  const [searchValue, setSearchValue] = useState('')
  const [isComboboxOpen, setIsComboboxOpen] = useState(false)

  // Read once per mount: both come from the server-rendered initial data, which does not
  // change while the page is open.
  const signedInUser = useMemo(() => currentUser(), [])
  const applications = useMemo(() => relyingApplications(), [])

  // Signing out must be a POST so it cannot be triggered by a link someone else planted.
  // The form is rendered outside the menu because the menu unmounts its own content on
  // select, which would tear the form out of the document before it could submit.
  const logoutFormRef = useRef<HTMLFormElement>(null)
  const csrfToken = typeof document === 'undefined'
    ? ''
    : (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '')

  const fetchPatients = useCallback(async () => {
    try {
      const rawResponse: unknown = await fetchWrapper.get('/api/phr/patients')
      const response = PhrPatientListResponseSchema.parse(rawResponse)
      setPatients(response.patients)
    } catch (error) {
      console.error('Failed to fetch PHR patients:', error)
    }
  }, [])

  useEffect(() => {
    void fetchPatients()
  }, [fetchPatients])

  const currentPatient = useMemo<PhrPatient | null>(
    () => patients.find((patient) => patient.id === patientId) ?? null,
    [patientId, patients],
  )

  const filteredPatients = useMemo<PhrPatient[]>(() => {
    if (!searchValue) {
      return patients
    }

    const query = searchValue.toLowerCase()
    return patients.filter((patient) => (patient.display_name ?? '').toLowerCase().includes(query))
  }, [patients, searchValue])

  const canManageAnyPatient = useMemo<boolean>(
    () => patients.some((patient) => patient.can_manage),
    [patients],
  )

  function handlePatientSelect(patient: PhrPatient): void {
    setSearchValue('')
    setIsComboboxOpen(false)
    onPatientChange?.(patient.id)
  }

  function handleSectionClick(section: PhrSection, event: MouseEvent<HTMLAnchorElement>): void {
    if (!onSectionChange) {
      return
    }

    event.preventDefault()
    onSectionChange(section)
  }

  return (
    <div className={cn('min-h-0', className)}>
      <div className="w-full border-b border-border/40 bg-background">
        <div className="flex h-12 items-center gap-2 px-4">
          <Tooltip>
            <TooltipTrigger asChild>
              <Button variant="secondary" size="icon" className="h-7 w-7 shrink-0" asChild>
                <a href={backUrl} aria-label="Back to BWH">
                  <ArrowLeft className="h-4 w-4" />
                </a>
              </Button>
            </TooltipTrigger>
            <TooltipContent side="bottom">Back to BWH</TooltipContent>
          </Tooltip>

          <span
            className="select-none text-xs font-bold uppercase tracking-widest text-foreground"
            aria-label="PHR section"
          >
            PHR
          </span>

          {patientId !== undefined && (
            <>
              <Combobox
                onValueChange={(value) => {
                  if (typeof value === 'number') {
                    const selectedPatient = patients.find((patient) => patient.id === value)
                    if (selectedPatient) {
                      handlePatientSelect(selectedPatient)
                    }
                  }
                }}
                open={isComboboxOpen}
                onOpenChange={setIsComboboxOpen}
              >
                <ComboboxInput
                  placeholder="Search patients…"
                  aria-label={`Selected patient: ${currentPatient?.display_name ?? patientId}`}
                  className="h-8 min-w-[200px]"
                  value={isComboboxOpen ? searchValue : (currentPatient?.display_name ?? String(patientId))}
                  onChange={(event) => setSearchValue(event.target.value)}
                  onFocus={() => {
                    setIsComboboxOpen(true)
                    setSearchValue('')
                  }}
                />
                <ComboboxContent align="start" className="w-72">
                  <ComboboxList>
                    {filteredPatients.map((patient) => (
                      <ComboboxItem
                        key={patient.id}
                        value={patient.id}
                        className={cn(patient.id === patientId && 'bg-accent font-medium')}
                      >
                        {patient.display_name || `Patient ${patient.id}`}
                      </ComboboxItem>
                    ))}
                    {filteredPatients.length === 0 && searchValue && (
                      <div className="py-2 text-center text-sm text-muted-foreground">No patients found</div>
                    )}
                  </ComboboxList>
                </ComboboxContent>
              </Combobox>
              {onSearch && (
                <Button
                  type="button"
                  variant="outline"
                  className="ml-1 h-8 min-w-52 justify-start gap-2 text-muted-foreground"
                  onClick={onSearch}
                >
                  <Search className="size-4" />
                  <span className="flex-1 text-left">Search this patient…</span>
                  <span className="flex items-center gap-0.5 text-xs"><Command className="size-3" />K</span>
                </Button>
              )}
            </>
          )}

          <NavigationMenu viewport={false} className="ml-auto">
            <NavigationMenuList>
              <NavigationMenuItem>
                <NavigationMenuLink
                  href={phrSectionUrl('patients')}
                  onClick={(event) => handleSectionClick('patients', event)}
                  aria-current={activeSection === 'patients' ? 'page' : undefined}
                  className={cn(
                    navigationMenuTriggerStyle(),
                    'h-8 px-3 text-sm',
                    activeSection === 'patients' ? 'bg-accent font-medium text-accent-foreground' : 'text-muted-foreground',
                  )}
                >
                  Patients
                </NavigationMenuLink>
              </NavigationMenuItem>
              {canManageAnyPatient && (
                <NavigationMenuItem>
                  <NavigationMenuLink
                    href={phrSectionUrl('manage-patients')}
                    onClick={(event) => handleSectionClick('manage-patients', event)}
                    aria-current={activeSection === 'manage-patients' ? 'page' : undefined}
                    className={cn(
                      navigationMenuTriggerStyle(),
                      'h-8 px-3 text-sm',
                      activeSection === 'manage-patients'
                        ? 'bg-accent font-medium text-accent-foreground'
                        : 'text-muted-foreground',
                    )}
                  >
                    Manage Patients
                  </NavigationMenuLink>
                </NavigationMenuItem>
              )}
              <NavigationMenuItem>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <NavigationMenuLink
                      href={phrSectionUrl('data-hub')}
                      onClick={(event) => handleSectionClick('data-hub', event)}
                      aria-current={activeSection === 'data-hub' ? 'page' : undefined}
                      aria-label="Data Hub"
                      className={cn(
                        navigationMenuTriggerStyle(),
                        'h-8 w-8 p-0',
                        activeSection === 'data-hub' ? 'bg-accent text-accent-foreground' : 'text-muted-foreground',
                      )}
                    >
                      <Database className="h-4 w-4" />
                    </NavigationMenuLink>
                  </TooltipTrigger>
                  <TooltipContent side="bottom">Data Hub</TooltipContent>
                </Tooltip>
              </NavigationMenuItem>
              <NavigationMenuItem>
                <NavigationMenuLink
                  href={phrSectionUrl('imports')}
                  onClick={(event) => handleSectionClick('imports', event)}
                  aria-current={activeSection === 'imports' ? 'page' : undefined}
                  className={cn(
                    navigationMenuTriggerStyle(),
                    'h-8 px-3 text-sm',
                    activeSection === 'imports' ? 'bg-accent font-medium text-accent-foreground' : 'text-muted-foreground',
                  )}
                >
                  Imports
                </NavigationMenuLink>
              </NavigationMenuItem>
              <NavigationMenuItem>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <NavigationMenuLink
                      href={phrSectionUrl('config')}
                      onClick={(event) => handleSectionClick('config', event)}
                      aria-current={activeSection === 'config' ? 'page' : undefined}
                      aria-label="Config"
                      className={cn(
                        navigationMenuTriggerStyle(),
                        'h-8 w-8 p-0',
                        activeSection === 'config' ? 'bg-accent text-accent-foreground' : 'text-muted-foreground',
                      )}
                    >
                      <Settings className="h-4 w-4" />
                    </NavigationMenuLink>
                  </TooltipTrigger>
                  <TooltipContent side="bottom">Config</TooltipContent>
                </Tooltip>
              </NavigationMenuItem>
            </NavigationMenuList>
          </NavigationMenu>

          {signedInUser !== null && (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8 text-muted-foreground"
                  aria-label="Account"
                >
                  <UserRound className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuGroup>
                  <DropdownMenuLabel className="font-normal">
                    <span className="block truncate text-sm font-medium">{signedInUser.name}</span>
                    <span className="block truncate text-xs text-muted-foreground">{signedInUser.email}</span>
                  </DropdownMenuLabel>
                </DropdownMenuGroup>

                {/* The sibling applications, as the identity provider reported them at sign-in.
                    The provider — not this bundle — decides what is listed, so an application
                    this person cannot reach never appears. */}
                {applications.length > 0 && (
                  <>
                    <DropdownMenuSeparator />
                    <DropdownMenuGroup>
                      <DropdownMenuLabel>Other apps</DropdownMenuLabel>
                    </DropdownMenuGroup>
                    {applications.map((app) => (
                      <DropdownMenuItem key={app.key} asChild>
                        <a href={app.url}>{app.name}</a>
                      </DropdownMenuItem>
                    ))}
                  </>
                )}

                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={() => logoutFormRef.current?.submit()}>
                  Sign out
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          )}
        </div>
      </div>

      {signedInUser !== null && (
        <form ref={logoutFormRef} action="/logout" method="POST" className="hidden">
          <input type="hidden" name="_token" value={csrfToken} />
        </form>
      )}

      {children}
    </div>
  )
}
