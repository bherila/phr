'use client'

import { Command, Database, Search, Settings, UserRound } from 'lucide-react'
import { type MouseEvent, type ReactNode, useMemo, useRef } from 'react'

import { Button } from '@/components/ui/button'
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
import { currentUser, relyingApplications } from '@/lib/appShell'
import type { PhrSection } from '@/lib/phrRouteBuilder'
import { phrSectionUrl } from '@/lib/phrRouteBuilder'
import { cn } from '@/lib/utils'
import { usePhrPatients } from '@/phr/patients/usePhrPatients'

interface PhrNavbarProps {
  activeSection?: PhrSection
  /**
   * A patient's record is open. The Patients link is then marked as the current location
   * (`aria-current="true"`) rather than the current page, which is the sidebar's.
   */
  inPatient?: boolean
  /** Rendered before the wordmark, e.g. the button that opens the sidebar on small screens. */
  leading?: ReactNode
  className?: string
  searchLabel?: string
  onSectionChange?: (section: PhrSection) => void
  onSearch?: () => void
}

interface SectionLink {
  section: PhrSection
  label: string
  /** Icon-only links carry the label as their accessible name and a tooltip. */
  icon?: typeof Database
}

const SECTION_LINKS: SectionLink[] = [
  { section: 'patients', label: 'Patients' },
  { section: 'manage-patients', label: 'Manage Patients' },
  { section: 'imports', label: 'Imports' },
  { section: 'data-hub', label: 'Data Hub', icon: Database },
  { section: 'config', label: 'Config', icon: Settings },
]

export default function PhrNavbar({
  activeSection,
  inPatient = false,
  leading,
  className,
  searchLabel = 'Search…',
  onSectionChange,
  onSearch,
}: PhrNavbarProps) {
  const { patients } = usePhrPatients()

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

  const canManageAnyPatient = useMemo<boolean>(
    () => patients.some((patient) => patient.can_manage),
    [patients],
  )

  const current = inPatient ? 'patients' : activeSection

  function currentAttribute(section: PhrSection): 'page' | 'true' | undefined {
    if (current !== section) return undefined
    return inPatient ? 'true' : 'page'
  }

  function linkClass(section: PhrSection, iconOnly = false): string {
    return cn(
      navigationMenuTriggerStyle(),
      iconOnly ? 'h-8 w-8 p-0' : 'h-8 px-3 text-sm',
      'focus-visible:ring-2 focus-visible:ring-ring',
      current === section ? 'bg-accent font-medium text-accent-foreground' : 'text-muted-foreground',
    )
  }

  function handleSectionClick(section: PhrSection, event: MouseEvent<HTMLAnchorElement>): void {
    if (!onSectionChange) {
      return
    }

    event.preventDefault()
    onSectionChange(section)
  }

  return (
    <header className={cn('w-full shrink-0 border-b border-border bg-background', className)}>
      <div className="flex min-h-12 flex-wrap items-center gap-2 px-3 py-1.5 md:px-4">
          {leading}
          <span
            className="select-none text-xs font-bold uppercase tracking-widest text-foreground"
            aria-label="PHR section"
          >
            PHR
          </span>

          {onSearch && (
            <Button
              type="button"
              variant="outline"
              className="ml-1 h-8 min-w-0 flex-[0_1_320px] justify-start gap-2 text-muted-foreground"
              onClick={onSearch}
            >
              <Search className="size-4" aria-hidden="true" />
              <span className="flex-1 truncate text-left">{searchLabel}</span>
              <span className="hidden items-center gap-0.5 text-xs sm:flex" aria-hidden="true"><Command className="size-3" />K</span>
            </Button>
          )}

          <NavigationMenu viewport={false} aria-label="Sections" className="ml-auto flex-none">
            <NavigationMenuList className="flex-wrap justify-end">
              {SECTION_LINKS.filter((link) => link.section !== 'manage-patients' || canManageAnyPatient).map((link) => {
                const anchor = (
                  <NavigationMenuLink
                    href={phrSectionUrl(link.section)}
                    onClick={(event) => handleSectionClick(link.section, event)}
                    aria-current={currentAttribute(link.section)}
                    {...(link.icon ? { 'aria-label': link.label } : {})}
                    className={linkClass(link.section, link.icon !== undefined)}
                  >
                    {link.icon ? <link.icon className="h-4 w-4" aria-hidden="true" /> : link.label}
                  </NavigationMenuLink>
                )
                return (
                  <NavigationMenuItem key={link.section}>
                    {link.icon ? (
                      <Tooltip>
                        <TooltipTrigger asChild>{anchor}</TooltipTrigger>
                        <TooltipContent side="bottom">{link.label}</TooltipContent>
                      </Tooltip>
                    ) : anchor}
                  </NavigationMenuItem>
                )
              })}
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

      {signedInUser !== null && (
        <form ref={logoutFormRef} action="/logout" method="POST" className="hidden">
          <input type="hidden" name="_token" value={csrfToken} />
        </form>
      )}
    </header>
  )
}
