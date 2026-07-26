import { type ReactElement, type ReactNode, useEffect } from 'react'

import type { MillerColumnSize, MillerDrillTarget, MillerRegistryEntry } from './millerRegistry'
import { MillerRegistryShell } from './MillerRegistryShell'
import { useMillerRoute, type UseMillerRouteResult } from './useMillerRoute'

/**
 * Per-surface configuration for a registry-driven Miller application. Each surface
 * (PHR, tax preview, …) supplies one of these; the shared `useMillerApp` hook owns
 * all the common wiring (hash routing, registry shell rendering, recent tracking).
 */
export interface MillerAppConfig<State, Id extends string, Meta = unknown> {
  /** The module registry keyed by column id. */
  registry: Record<Id, MillerRegistryEntry<State, Id, Meta>>
  /** Set of valid column ids, used to validate inbound hashes. */
  ids: ReadonlySet<string>
  /** Surface-specific shell state passed to every column component. */
  state: State
  /**
   * The dock/home view shown when no columns are open. May be a node, or a function
   * that receives the route handles (so a home view can drill via `replaceFrom`, etc.).
   */
  homeView: ReactNode | ((route: UseMillerRouteResult<Id>) => ReactNode)
  homeColumnSize?: MillerColumnSize
  /** Handle drills the registry shell cannot place as a column (e.g. modal worksheets). */
  onDrillUnhandled?: (target: MillerDrillTarget<Id>, entry: MillerRegistryEntry<State, Id, Meta> | undefined) => void
  /**
   * Fired whenever the rightmost column id changes. Surfaces use this to record a
   * "recent" entry; the policy (which categories count, etc.) lives in the callback.
   */
  onRightmostChange?: (rightmostId: Id | null) => void
}

export interface UseMillerAppResult<Id extends string> extends UseMillerRouteResult<Id> {
  /** The id of the rightmost open column, or null when only the home view is shown. */
  rightmostId: Id | null
  /** The rendered registry shell. Place this inside the surface's own chrome. */
  shell: ReactElement
}

/**
 * Shared scaffold for registry-driven Miller surfaces. Owns hash routing
 * (`useMillerRoute`) and renders `MillerRegistryShell`, exposing the route handles so
 * the caller can wire its own navbar, command palette, and home view around `shell`.
 */
export function useMillerApp<State, Id extends string, Meta = unknown>(
  config: MillerAppConfig<State, Id, Meta>,
): UseMillerAppResult<Id> {
  const { registry, ids, state, homeView, homeColumnSize, onDrillUnhandled, onRightmostChange } = config

  const routeApi = useMillerRoute<Id>(ids)
  const { route, pushColumn, replaceFrom, truncateTo, navigate } = routeApi

  const rightmostId: Id | null = route.columns.length > 0 ? route.columns[route.columns.length - 1]!.id : null

  useEffect(() => {
    onRightmostChange?.(rightmostId)
  }, [rightmostId, onRightmostChange])

  const resolvedHomeView = typeof homeView === 'function' ? homeView(routeApi) : homeView

  const shell = (
    <MillerRegistryShell<State, Id, Meta>
      registry={registry}
      state={state}
      homeView={resolvedHomeView}
      route={route}
      pushColumn={pushColumn}
      replaceFrom={replaceFrom}
      truncateTo={truncateTo}
      navigate={navigate}
      {...(onDrillUnhandled ? { onDrillUnhandled } : {})}
      {...(homeColumnSize ? { homeColumnSize } : {})}
    />
  )

  return { ...routeApi, rightmostId, shell }
}
