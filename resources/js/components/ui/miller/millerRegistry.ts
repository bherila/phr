import type { ComponentType } from 'react'

export type MillerPresentation = 'column' | 'modal' | 'app'
export type MillerColumnSize = 'narrow' | 'default' | 'wide' | 'full' | 'viewport'

export interface KeyAmount {
  label: string
  value: number
}

export interface MillerInstanceRef {
  key: string
  label: string
}

export interface MillerDrillTarget<Id extends string> {
  id: Id
  instance?: string
  placement?: 'right' | 'left-of-current'
}

export interface MillerRenderProps<State, Id extends string> {
  state: State
  instance?: MillerInstanceRef
  onDrill: (target: MillerDrillTarget<Id>) => void
}

export interface MillerRegistryEntry<State, Id extends string, Meta = unknown> {
  id: Id
  label: string
  shortLabel: string
  presentation: MillerPresentation
  component: ComponentType<MillerRenderProps<State, Id>>
  instances?: {
    list: (state: State) => MillerInstanceRef[]
    create: (state: State) => MillerInstanceRef
    allowCreate: boolean
  }
  size?: MillerColumnSize | undefined
  /** @deprecated Use size instead. */
  wide?: boolean | undefined
  meta?: Meta
}
