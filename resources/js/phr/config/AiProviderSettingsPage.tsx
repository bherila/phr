import { Check, Pencil, Plus, RefreshCw, ShieldCheck, Trash2 } from 'lucide-react'
import type { FormEvent, ReactElement } from 'react'
import { useCallback, useEffect, useId, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { fetchWrapper } from '@/fetchWrapper'
import { errorMessage } from '@/phr/shared'

import {
  AI_PROVIDER_LABELS,
  type AiConfiguration,
  AiConfigurationListSchema,
  AiConfigurationSchema,
  AiDeleteResponseSchema,
  AiModelsResponseSchema,
  type AiProvider,
} from './aiPrefs'

interface FormState {
  name: string
  provider: AiProvider
  apiKey: string
  sessionToken: string
  clearSessionToken: boolean
  region: string
  model: string
  expiresAt: string
}

const EMPTY_FORM: FormState = {
  name: '',
  provider: 'gemini',
  apiKey: '',
  sessionToken: '',
  clearSessionToken: false,
  region: '',
  model: '',
  expiresAt: '',
}

const SELECT_CLASS = 'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50'

function toDateInput(value: string | null): string {
  return value?.slice(0, 10) ?? ''
}

function formForConfiguration(configuration: AiConfiguration): FormState {
  return {
    name: configuration.name,
    provider: configuration.provider,
    apiKey: '',
    sessionToken: '',
    clearSessionToken: false,
    region: configuration.region ?? '',
    model: configuration.model,
    expiresAt: toDateInput(configuration.expires_at),
  }
}

function tomorrow(): string {
  const date = new Date()
  date.setDate(date.getDate() + 1)
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function tokenCount(value: number): string {
  return new Intl.NumberFormat().format(value)
}

export default function AiProviderSettingsPage(): ReactElement {
  const [configurations, setConfigurations] = useState<AiConfiguration[]>([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState<FormState>(EMPTY_FORM)
  const [models, setModels] = useState<string[]>([])
  const [modelsBusy, setModelsBusy] = useState(false)
  const [formBusy, setFormBusy] = useState(false)
  const [actionBusyId, setActionBusyId] = useState<number | null>(null)
  const [confirmDeleteId, setConfirmDeleteId] = useState<number | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const formHeadingId = useId()
  const sessionTokenId = useId()

  const loadConfigurations = useCallback(async (): Promise<void> => {
    setLoading(true)
    setLoadError(null)
    try {
      const raw: unknown = await fetchWrapper.get('/api/user/ai-prefs')
      setConfigurations(AiConfigurationListSchema.parse(raw))
    } catch (caught) {
      setLoadError(errorMessage(caught))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void loadConfigurations()
  }, [loadConfigurations])

  function startCreate(): void {
    setEditingId(null)
    setForm(EMPTY_FORM)
    setModels([])
    setFormError(null)
    setNotice(null)
    setFormOpen(true)
  }

  function startEdit(configuration: AiConfiguration): void {
    setEditingId(configuration.id)
    setForm(formForConfiguration(configuration))
    setModels([configuration.model])
    setFormError(null)
    setNotice(null)
    setFormOpen(true)
  }

  function closeForm(): void {
    setFormOpen(false)
    setEditingId(null)
    setForm(EMPTY_FORM)
    setModels([])
    setFormError(null)
  }

  function changeProvider(provider: AiProvider): void {
    setForm((current) => ({
      ...current,
      provider,
      apiKey: '',
      sessionToken: '',
      clearSessionToken: false,
      region: provider === 'bedrock' ? 'us-east-1' : '',
      model: '',
    }))
    setModels([])
    setFormError(null)
  }

  async function loadModels(): Promise<void> {
    setFormError(null)
    const apiKey = form.apiKey.trim()
    if (editingId === null && apiKey === '') {
      setFormError('Enter an API key before loading models.')
      return
    }
    if (form.provider === 'bedrock' && form.region.trim() === '') {
      setFormError('Enter an AWS region before loading models.')
      return
    }

    const payload: Record<string, unknown> = { provider: form.provider }
    if (editingId !== null) payload.config_id = editingId
    if (apiKey !== '') payload.api_key = apiKey
    if (form.provider === 'bedrock') {
      payload.region = form.region.trim()
      if (form.clearSessionToken) {
        payload.clear_session_token = true
      } else if (form.sessionToken.trim() !== '') {
        payload.session_token = form.sessionToken.trim()
      }
    }

    setModelsBusy(true)
    try {
      const raw: unknown = await fetchWrapper.post('/api/user/ai-prefs/models', payload)
      const response = AiModelsResponseSchema.parse(raw)
      if (response.models.length === 0) {
        setModels([])
        setForm((current) => ({ ...current, model: '' }))
        setFormError('No usable models were found for these credentials.')
        return
      }
      setModels(response.models)
      setForm((current) => ({
        ...current,
        model: response.models.includes(current.model) ? current.model : response.models[0]!,
      }))
    } catch (caught) {
      setFormError(errorMessage(caught))
    } finally {
      setModelsBusy(false)
    }
  }

  async function submit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    setFormError(null)
    setNotice(null)

    if (form.name.trim() === '') {
      setFormError('Enter a name for this configuration.')
      return
    }
    if (editingId === null && form.apiKey.trim() === '') {
      setFormError('Enter an API key.')
      return
    }
    if (form.provider === 'bedrock' && form.region.trim() === '') {
      setFormError('Enter an AWS region.')
      return
    }
    if (form.model === '') {
      setFormError('Load and select an available model.')
      return
    }

    const payload: Record<string, unknown> = {
      name: form.name.trim(),
      provider: form.provider,
      model: form.model,
      region: form.provider === 'bedrock' ? form.region.trim() : null,
      expires_at: form.expiresAt || null,
    }
    if (form.apiKey.trim() !== '') payload.api_key = form.apiKey.trim()
    if (form.provider === 'bedrock') {
      if (form.clearSessionToken) {
        payload.clear_session_token = true
      } else if (form.sessionToken.trim() !== '') {
        payload.session_token = form.sessionToken.trim()
      }
    }

    setFormBusy(true)
    try {
      const raw: unknown = editingId === null
        ? await fetchWrapper.post('/api/user/ai-prefs', payload)
        : await fetchWrapper.put(`/api/user/ai-prefs/${editingId}`, payload)
      const saved = AiConfigurationSchema.parse(raw)
      await loadConfigurations()
      closeForm()
      setNotice(editingId === null
        ? `${saved.name} was added.`
        : `${saved.name} was updated.`)
    } catch (caught) {
      setFormError(errorMessage(caught))
    } finally {
      setFormBusy(false)
    }
  }

  async function activate(configuration: AiConfiguration): Promise<void> {
    setNotice(null)
    setLoadError(null)
    setActionBusyId(configuration.id)
    try {
      const raw: unknown = await fetchWrapper.post(`/api/user/ai-prefs/${configuration.id}/activate`, {})
      const activated = AiConfigurationSchema.parse(raw)
      await loadConfigurations()
      setNotice(`${activated.name} is now active.`)
    } catch (caught) {
      setLoadError(errorMessage(caught))
    } finally {
      setActionBusyId(null)
    }
  }

  async function remove(configuration: AiConfiguration): Promise<void> {
    setNotice(null)
    setLoadError(null)
    setActionBusyId(configuration.id)
    try {
      const raw: unknown = await fetchWrapper.delete(`/api/user/ai-prefs/${configuration.id}`, {})
      AiDeleteResponseSchema.parse(raw)
      await loadConfigurations()
      setConfirmDeleteId(null)
      setNotice(`${configuration.name} was removed.`)
    } catch (caught) {
      setLoadError(errorMessage(caught))
    } finally {
      setActionBusyId(null)
    }
  }

  const editingConfiguration = editingId === null
    ? null
    : configurations.find((configuration) => configuration.id === editingId) ?? null

  return (
    <div className="h-full overflow-y-auto p-6">
      <div className="mx-auto max-w-5xl">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold text-foreground">AI Provider Settings</h1>
            <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
              Manage the provider credentials and model used for your document imports. Credentials are encrypted at rest and are never displayed again.
            </p>
          </div>
          <Button type="button" size="sm" onClick={startCreate} disabled={formBusy || modelsBusy}>
            <Plus className="size-4" />
            Add configuration
          </Button>
        </div>

        {notice ? (
          <p role="status" aria-live="polite" className="mt-4 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
            {notice}
          </p>
        ) : null}
        {loadError ? (
          <div role="alert" className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            <span>{loadError}</span>
            <Button type="button" variant="outline" size="sm" onClick={() => void loadConfigurations()}>Retry</Button>
          </div>
        ) : null}

        {formOpen ? (
          <section aria-labelledby={formHeadingId} className="mt-6 rounded-lg border border-primary/30 bg-card p-4 shadow-sm">
            <h2 id={formHeadingId} className="text-lg font-semibold text-card-foreground">
              {editingId === null ? 'Add AI configuration' : 'Edit AI configuration'}
            </h2>
            <p className="mt-1 text-sm text-muted-foreground">
              {editingId === null
                ? 'Load models with the credentials before saving.'
                : 'Stored secrets stay unchanged when their replacement fields are blank.'}
            </p>
            <form className="mt-4 grid gap-4" onSubmit={(event) => void submit(event)}>
              <div className="grid gap-4 md:grid-cols-2">
                <label className="grid gap-1 text-sm font-medium text-foreground">
                  Configuration name
                  <Input
                    value={form.name}
                    maxLength={255}
                    required
                    onChange={(event) => setForm({ ...form, name: event.target.value })}
                  />
                </label>
                <label className="grid gap-1 text-sm font-medium text-foreground">
                  Provider
                  <select
                    className={SELECT_CLASS}
                    value={form.provider}
                    disabled={editingId !== null}
                    onChange={(event) => changeProvider(event.target.value as AiProvider)}
                  >
                    {Object.entries(AI_PROVIDER_LABELS).map(([value, label]) => (
                      <option key={value} value={value}>{label}</option>
                    ))}
                  </select>
                  {editingId !== null ? <span className="text-xs font-normal text-muted-foreground">Create a new configuration to use a different provider.</span> : null}
                </label>
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <label className="grid gap-1 text-sm font-medium text-foreground">
                  {form.provider === 'bedrock' ? 'Bedrock bearer token' : 'API key'}
                  <Input
                    type="password"
                    autoComplete="new-password"
                    value={form.apiKey}
                    required={editingId === null}
                    placeholder={editingId === null ? 'Enter credential' : 'Leave blank to keep stored key'}
                    onChange={(event) => setForm({ ...form, apiKey: event.target.value })}
                  />
                  {editingConfiguration?.has_api_key ? (
                    <span className="text-xs font-normal text-muted-foreground">Configured as {editingConfiguration.masked_key}; enter a value only to replace it.</span>
                  ) : null}
                </label>

                {form.provider === 'bedrock' ? (
                  <label className="grid gap-1 text-sm font-medium text-foreground">
                    AWS region
                    <Input
                      value={form.region}
                      maxLength={64}
                      required
                      placeholder="us-east-1"
                      onChange={(event) => setForm({ ...form, region: event.target.value })}
                    />
                  </label>
                ) : null}
              </div>

              {form.provider === 'bedrock' ? (
                <div className="grid gap-1 text-sm font-medium text-foreground">
                  <label htmlFor={sessionTokenId}>STS session token <span className="font-normal text-muted-foreground">(optional)</span></label>
                  <Input
                    id={sessionTokenId}
                    type="password"
                    autoComplete="new-password"
                    value={form.sessionToken}
                    disabled={form.clearSessionToken}
                    placeholder={editingConfiguration?.has_session_token ? 'Leave blank to keep stored token' : 'Optional session token'}
                    onChange={(event) => setForm({ ...form, sessionToken: event.target.value })}
                  />
                  {editingConfiguration?.has_session_token ? (
                    <>
                      <span className="text-xs font-normal text-muted-foreground">A session token is configured; enter a value only to replace it.</span>
                      <label className="mt-1 flex items-center gap-2 font-normal text-foreground">
                        <input
                          type="checkbox"
                          checked={form.clearSessionToken}
                          onChange={(event) => setForm({ ...form, clearSessionToken: event.target.checked, sessionToken: '' })}
                        />
                        Remove stored session token
                      </label>
                    </>
                  ) : null}
                </div>
              ) : null}

              <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                <label className="grid gap-1 text-sm font-medium text-foreground">
                  Model
                  <select
                    className={SELECT_CLASS}
                    value={form.model}
                    required
                    disabled={models.length === 0 || modelsBusy}
                    onChange={(event) => setForm({ ...form, model: event.target.value })}
                  >
                    {models.length === 0 ? <option value="">Load available models first</option> : null}
                    {models.map((model) => <option key={model} value={model}>{model}</option>)}
                  </select>
                </label>
                <Button type="button" variant="outline" onClick={() => void loadModels()} disabled={modelsBusy || formBusy}>
                  <RefreshCw className={`size-4 ${modelsBusy ? 'animate-spin' : ''}`} />
                  {modelsBusy ? 'Loading models…' : 'Load models'}
                </Button>
              </div>

              <label className="grid max-w-sm gap-1 text-sm font-medium text-foreground">
                Credential expiry <span className="font-normal text-muted-foreground">(optional)</span>
                <Input
                  type="date"
                  min={tomorrow()}
                  value={form.expiresAt}
                  onChange={(event) => setForm({ ...form, expiresAt: event.target.value })}
                />
              </label>

              {formError ? <p role="alert" className="text-sm text-destructive">{formError}</p> : null}
              <div className="flex flex-wrap gap-2">
                <Button type="submit" size="sm" disabled={formBusy || modelsBusy}>
                  <Check className="size-4" />
                  {formBusy ? 'Saving…' : 'Save configuration'}
                </Button>
                <Button type="button" variant="outline" size="sm" onClick={closeForm} disabled={formBusy}>Cancel</Button>
              </div>
            </form>
          </section>
        ) : null}

        {loading ? <p role="status" aria-live="polite" className="mt-6 text-sm text-muted-foreground">Loading AI configurations…</p> : null}

        {!loading && configurations.length === 0 ? (
          <div className="mt-6 rounded-lg border border-dashed border-border p-10 text-center">
            <ShieldCheck className="mx-auto size-8 text-muted-foreground/60" />
            <h2 className="mt-3 text-base font-semibold text-foreground">No AI provider configured</h2>
            <p className="mt-1 text-sm text-muted-foreground">Add credentials to enable AI-assisted document imports.</p>
          </div>
        ) : null}

        <div className="mt-6 grid gap-4 lg:grid-cols-2">
          {configurations.map((configuration) => {
            const cannotActivate = configuration.is_active || configuration.is_expired || configuration.has_invalid_api_key
            return (
              <article key={configuration.id} className="rounded-lg border border-border bg-card p-4 shadow-sm">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <h2 className="font-semibold text-card-foreground">{configuration.name}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">{AI_PROVIDER_LABELS[configuration.provider]} · {configuration.model}</p>
                  </div>
                  <div className="flex flex-wrap justify-end gap-1 text-xs">
                    {configuration.is_active ? <span className="rounded-full bg-primary/10 px-2 py-1 font-medium text-primary">Active</span> : null}
                    {configuration.is_expired ? <span className="rounded-full bg-destructive/10 px-2 py-1 font-medium text-destructive">Expired</span> : null}
                    {configuration.has_invalid_api_key ? <span className="rounded-full bg-destructive/10 px-2 py-1 font-medium text-destructive">Invalid credential</span> : null}
                  </div>
                </div>
                <dl className="mt-4 grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm">
                  <dt className="text-muted-foreground">Credential</dt>
                  <dd className="font-mono text-foreground">{configuration.masked_key}</dd>
                  {configuration.region ? <><dt className="text-muted-foreground">Region</dt><dd>{configuration.region}</dd></> : null}
                  <dt className="text-muted-foreground">Expires</dt>
                  <dd>{configuration.expires_at ? new Date(configuration.expires_at).toLocaleDateString() : 'No expiry'}</dd>
                  <dt className="text-muted-foreground">This month</dt>
                  <dd>{tokenCount(configuration.usage.this_month.input_tokens + configuration.usage.this_month.output_tokens)} tokens</dd>
                  <dt className="text-muted-foreground">All time</dt>
                  <dd>{tokenCount(configuration.usage.total.input_tokens + configuration.usage.total.output_tokens)} tokens</dd>
                </dl>

                {confirmDeleteId === configuration.id ? (
                  <div role="alert" className="mt-4 rounded-md border border-destructive/40 bg-destructive/10 p-3">
                    <p className="text-sm text-foreground">Remove {configuration.name}? This cannot be undone.</p>
                    <div className="mt-3 flex gap-2">
                      <Button type="button" variant="destructive" size="sm" disabled={actionBusyId === configuration.id} onClick={() => void remove(configuration)}>
                        {actionBusyId === configuration.id ? 'Removing…' : 'Remove'}
                      </Button>
                      <Button type="button" variant="outline" size="sm" disabled={actionBusyId === configuration.id} onClick={() => setConfirmDeleteId(null)}>Cancel</Button>
                    </div>
                  </div>
                ) : (
                  <div className="mt-4 flex flex-wrap gap-2">
                    <Button type="button" variant="outline" size="sm" onClick={() => startEdit(configuration)}>
                      <Pencil className="size-4" /> Edit
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={cannotActivate || actionBusyId === configuration.id}
                      title={configuration.is_expired ? 'Update the expiry before activating.' : configuration.has_invalid_api_key ? 'Replace the invalid credential before activating.' : undefined}
                      onClick={() => void activate(configuration)}
                    >
                      <Check className="size-4" /> {configuration.is_active ? 'Active' : 'Make active'}
                    </Button>
                    <Button type="button" variant="ghost" size="sm" onClick={() => setConfirmDeleteId(configuration.id)}>
                      <Trash2 className="size-4" /> Remove
                    </Button>
                  </div>
                )}
              </article>
            )
          })}
        </div>
      </div>
    </div>
  )
}
