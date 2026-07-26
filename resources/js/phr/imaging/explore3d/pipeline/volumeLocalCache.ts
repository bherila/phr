import { PIPELINE_VERSION } from './protocol'

/**
 * Browser-local cache of the gzipped derived-volume artifact, so a repeat open
 * on the same machine skips both the slice download and the R2 round-trip.
 *
 * The entries hold decoded scan data (PHI), so they are deliberately
 * short-lived: expired past TTL_MS, capped to MAX_ENTRIES (LRU by createdAt),
 * and stored in best-effort storage (no navigator.storage.persist() request),
 * letting the browser evict them under pressure.
 */
const DB_NAME = 'phr-explore3d'
const STORE = 'volumes'
const TTL_MS = 7 * 24 * 60 * 60 * 1000
const MAX_ENTRIES = 6

interface VolumeCacheRecord {
  key: string
  createdAt: number
  artifact: ArrayBuffer
}

function cacheKey(seriesUid: string): string {
  return `${seriesUid}:v${PIPELINE_VERSION}`
}

function openDb(): Promise<IDBDatabase | null> {
  return new Promise((resolve) => {
    if (typeof indexedDB === 'undefined') {
      resolve(null)
      return
    }
    let request: IDBOpenDBRequest
    try {
      request = indexedDB.open(DB_NAME, 1)
    } catch {
      resolve(null)
      return
    }
    request.onupgradeneeded = () => {
      const db = request.result
      if (!db.objectStoreNames.contains(STORE)) {
        db.createObjectStore(STORE, { keyPath: 'key' })
      }
    }
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => resolve(null)
  })
}

function promisifyTx<T>(request: IDBRequest<T>): Promise<T> {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
}

/** Returns the cached gzipped artifact bytes for a series, or null on miss/expiry. */
export async function readLocalVolume(seriesUid: string): Promise<ArrayBuffer | null> {
  const db = await openDb()
  if (!db) return null
  try {
    const tx = db.transaction(STORE, 'readonly')
    const record = await promisifyTx<VolumeCacheRecord | undefined>(tx.objectStore(STORE).get(cacheKey(seriesUid)))
    if (!record) return null
    if (Date.now() - record.createdAt > TTL_MS) {
      await deleteLocalVolume(seriesUid)
      return null
    }
    return record.artifact
  } catch {
    return null
  } finally {
    db.close()
  }
}

export async function writeLocalVolume(seriesUid: string, artifact: ArrayBuffer): Promise<void> {
  const db = await openDb()
  if (!db) return
  try {
    const tx = db.transaction(STORE, 'readwrite')
    const store = tx.objectStore(STORE)
    const record: VolumeCacheRecord = { key: cacheKey(seriesUid), createdAt: Date.now(), artifact }
    await promisifyTx(store.put(record))
    await evictStale(store)
  } catch {
    /* Cache writes are best-effort. */
  } finally {
    db.close()
  }
}

async function deleteLocalVolume(seriesUid: string): Promise<void> {
  const db = await openDb()
  if (!db) return
  try {
    const tx = db.transaction(STORE, 'readwrite')
    await promisifyTx(tx.objectStore(STORE).delete(cacheKey(seriesUid)))
  } catch {
    /* ignore */
  } finally {
    db.close()
  }
}

/** Drops expired entries and enforces the LRU cap. Runs within an open write tx. */
async function evictStale(store: IDBObjectStore): Promise<void> {
  const records = await promisifyTx<VolumeCacheRecord[]>(store.getAll() as IDBRequest<VolumeCacheRecord[]>)
  const now = Date.now()
  const live = records.filter((record) => now - record.createdAt <= TTL_MS)
  const expired = records.filter((record) => now - record.createdAt > TTL_MS)
  for (const record of expired) {
    store.delete(record.key)
  }
  if (live.length > MAX_ENTRIES) {
    live
      .sort((a, b) => a.createdAt - b.createdAt)
      .slice(0, live.length - MAX_ENTRIES)
      .forEach((record) => store.delete(record.key))
  }
}
