import { CT_PRESETS } from './thresholdPresets'

interface ThresholdControlsProps {
  modality: string | null
  value: number
  min: number
  max: number
  disabled: boolean
  onChange: (threshold: number) => void
}

/**
 * Presentational density control. Debouncing and clamping live in the parent
 * so the slider, preset buttons, and keyboard shortcuts all share one path.
 */
export function ThresholdControls({ modality, value, min, max, disabled, onChange }: ThresholdControlsProps) {
  const isCt = modality === 'CT'

  return (
    <div className="pointer-events-auto flex flex-col gap-2 rounded-lg bg-black/70 p-3 text-white backdrop-blur">
      <div className="flex items-center justify-between gap-4 text-xs">
        <span className="font-medium">{isCt ? 'Density threshold (HU)' : 'Density threshold'}</span>
        <span className="tabular-nums">{value}</span>
      </div>
      <input
        type="range"
        min={min}
        max={max}
        step={10}
        value={value}
        disabled={disabled}
        onChange={(event) => onChange(Number(event.target.value))}
        className="w-56 accent-sky-400"
        aria-label="Density threshold"
      />
      {isCt && (
        <div className="flex gap-1.5">
          {CT_PRESETS.map((preset) => (
            <button
              key={preset.label}
              type="button"
              disabled={disabled}
              onClick={() => onChange(preset.value)}
              className={`rounded-md px-2 py-1 text-xs transition-colors ${
                value === preset.value ? 'bg-sky-500 text-white' : 'bg-white/15 text-white hover:bg-white/25'
              }`}
            >
              <span className="mr-1 text-white/50">{preset.hotkey}</span>
              {preset.label}
            </button>
          ))}
        </div>
      )}
      <p className="text-[10px] leading-tight text-white/50">
        {isCt ? '[ ] adjust · Shift = coarse · 1–3 presets' : '[ ] adjust · Shift = coarse'}
      </p>
    </div>
  )
}
