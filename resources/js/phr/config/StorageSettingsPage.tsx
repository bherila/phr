import { type FormEvent, type ReactElement, useEffect, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { fetchWrapper } from '@/fetchWrapper'

interface Limits { global_limit_bytes: number | null, default_user_limit_bytes: number | null, used_bytes: number }
interface Account { id: number, name: string, email: string }
interface AccountLimit { limit_bytes: number | null, unlimited: boolean, effective_limit_bytes: number | null, used_bytes: number }
const BASE = '/api/admin/storage'
const MIB = 1024 * 1024
function inputValue(bytes: number | null): string { return bytes === null ? '' : String(bytes / MIB) }
function bytes(value: string): number | null {
  if (value.trim() === '') return null
  const result = Number(value) * MIB
  if (!Number.isSafeInteger(result) || result < 0 || result > 1_000_000_000_000_000) throw new Error('Enter a non-negative storage limit in MiB.')
  return result
}
function display(value: number | null): string { return value === null ? 'Unlimited' : `${(value / MIB).toLocaleString(undefined, { maximumFractionDigits: 1 })} MiB` }

export default function StorageSettingsPage(): ReactElement {
  const [limits, setLimits] = useState<Limits | null>(null)
  const [global, setGlobal] = useState('')
  const [defaultLimit, setDefaultLimit] = useState('')
  const [search, setSearch] = useState('')
  const [accounts, setAccounts] = useState<Account[]>([])
  const [account, setAccount] = useState<Account | null>(null)
  const [accountLimit, setAccountLimit] = useState<AccountLimit | null>(null)
  const [override, setOverride] = useState('')
  const [unlimited, setUnlimited] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  useEffect(() => {
    let live = true
    void (fetchWrapper.get(BASE) as Promise<Limits>).then((data) => {
      if (live) { setLimits(data); setGlobal(inputValue(data.global_limit_bytes)); setDefaultLimit(inputValue(data.default_user_limit_bytes)) }
    }).catch(() => { if (live) setError('Storage settings could not be loaded.') })
    return () => { live = false }
  }, [])

  async function save(event: FormEvent): Promise<void> {
    event.preventDefault(); setBusy(true); setError(''); setNotice('')
    try {
      const data = await fetchWrapper.put(BASE, { global_limit_bytes: bytes(global), default_user_limit_bytes: bytes(defaultLimit) }) as Limits
      setLimits(data); setNotice('Storage limits saved.')
      // A changed default invalidates the effective limit displayed for a selected account.
      setAccount(null); setAccountLimit(null)
    } catch (failure) { setError(failure instanceof Error ? failure.message : 'Storage limits could not be saved.') }
    finally { setBusy(false) }
  }

  async function find(event: FormEvent): Promise<void> {
    event.preventDefault(); setBusy(true); setError('')
    try { setAccounts((await (fetchWrapper.get(`${BASE}/users?search=${encodeURIComponent(search)}`) as Promise<{ users: Account[] }>)).users) }
    catch { setError('Accounts could not be loaded.') }
    finally { setBusy(false) }
  }

  async function select(candidate: Account): Promise<void> {
    setBusy(true); setError(''); setAccount(null); setAccountLimit(null); setNotice('')
    try {
      const data = await (fetchWrapper.get(`${BASE}/users/${candidate.id}`) as Promise<AccountLimit>)
      setAccount(candidate); setAccountLimit(data); setOverride(inputValue(data.limit_bytes)); setUnlimited(data.unlimited)
    } catch { setError('Account storage could not be loaded.') }
    finally { setBusy(false) }
  }

  async function saveAccount(event: FormEvent): Promise<void> {
    event.preventDefault()
    if (!account) return
    setBusy(true); setError(''); setNotice('')
    try {
      const data = await fetchWrapper.put(`${BASE}/users/${account.id}`, { limit_bytes: unlimited ? null : bytes(override), unlimited }) as AccountLimit
      setAccountLimit(data); setNotice('Account storage limit saved.')
    } catch (failure) { setError(failure instanceof Error ? failure.message : 'Account limit could not be saved.') }
    finally { setBusy(false) }
  }

  return <section className="max-w-3xl space-y-6">
    <div><h1 className="text-xl font-semibold">Storage limits</h1>
      <p className="mt-2 text-sm text-muted-foreground">Limits apply when uploads are authorized. Usage is approximate and may lag by a minute; simultaneous uploads and generated files can exceed a limit. Existing records remain available.</p>
      <p className="mt-2 text-sm text-muted-foreground">Storage is charged to the record owner, including uploads made by someone with shared access. Leave a limit blank for unlimited storage; zero stops new uploads.</p>
    </div>
    {error && <p role="alert" className="text-destructive">{error}</p>}
    {notice && <p role="status">{notice}</p>}
    {!limits ? <p>Loading storage settings…</p> : <form onSubmit={(event) => void save(event)} className="grid gap-4 rounded-lg border border-border p-4">
      <p>Estimated total usage: <strong>{display(limits.used_bytes)}</strong></p>
      <label className="grid gap-1 text-sm">Global limit (MiB)<Input type="number" min="0" step="any" value={global} onChange={(event) => setGlobal(event.target.value)} /></label>
      <label className="grid gap-1 text-sm">Default per-user limit (MiB)<Input type="number" min="0" step="any" value={defaultLimit} onChange={(event) => setDefaultLimit(event.target.value)} /></label>
      <Button type="submit" disabled={busy}>Save storage limits</Button>
    </form>}
    <div className="space-y-4"><h2 className="text-lg font-semibold">Per-user overrides</h2>
      <form onSubmit={(event) => void find(event)} className="flex items-end gap-2">
        <label className="grid flex-1 gap-1 text-sm">Find account by name or email<Input value={search} maxLength={100} onChange={(event) => setSearch(event.target.value)} /></label>
        <Button type="submit" variant="outline" disabled={busy}>Find accounts</Button>
      </form>
      <ul className="space-y-1">{accounts.map((candidate) => <li key={candidate.id}><Button type="button" variant="ghost" disabled={busy} onClick={() => void select(candidate)}>{candidate.name} ({candidate.email})</Button></li>)}</ul>
      {account && accountLimit && <form onSubmit={(event) => void saveAccount(event)} className="grid gap-4 rounded-lg border border-border p-4">
        <h3 className="font-medium">{account.name}</h3>
        <p className="text-sm">Estimated usage: {display(accountLimit.used_bytes)} · Effective limit: {display(accountLimit.effective_limit_bytes)}</p>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={unlimited} onChange={(event) => setUnlimited(event.target.checked)} />Unlimited for this user (global limit still applies)</label>
        <label className="grid gap-1 text-sm">User limit (MiB)<Input type="number" min="0" step="any" disabled={unlimited} value={override} onChange={(event) => setOverride(event.target.value)} /></label>
        <p className="text-sm text-muted-foreground">Leave blank to use the default per-user limit.</p>
        <Button type="submit" disabled={busy}>Save user limit</Button>
      </form>}
    </div>
  </section>
}
