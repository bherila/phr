import { fileAtPath } from './dicomZip'

/** The slice of the File and Directory Entries API a drop hands over. */
interface DroppedEntry {
  isFile: boolean
  isDirectory: boolean
  name: string
  fullPath: string
  file?: (success: (file: File) => void, failure?: (error: unknown) => void) => void
  createReader?: () => { readEntries: (success: (entries: DroppedEntry[]) => void, failure?: (error: unknown) => void) => void }
}

export interface DroppedFiles {
  files: File[]
  /** Whether the drop included a folder, which only a DICOM upload can take. */
  hadDirectory: boolean
}

function entryFile(entry: DroppedEntry): Promise<File> {
  return new Promise((resolve, reject) => {
    if (!entry.file) {
      reject(new Error(`${entry.name} could not be read.`))
      return
    }
    entry.file(resolve, reject)
  })
}

async function directoryEntries(entry: DroppedEntry): Promise<DroppedEntry[]> {
  const reader = entry.createReader?.()
  if (!reader) return []
  const all: DroppedEntry[] = []
  // readEntries answers in batches (Chrome: 100 at a time) and an empty batch at the end.
  for (;;) {
    const batch = await new Promise<DroppedEntry[]>((resolve, reject) => reader.readEntries(resolve, reject))
    if (batch.length === 0) return all
    all.push(...batch)
  }
}

async function collect(entry: DroppedEntry, into: File[]): Promise<void> {
  if (entry.isFile) {
    const file = await entryFile(entry)
    const path = entry.fullPath.replace(/^\/+/, '')
    // A top-level file keeps its own File; one inside a folder gets the folder's path,
    // as the folder picker would give it.
    into.push(path.includes('/') ? fileAtPath(file, path) : file)
    return
  }
  if (entry.isDirectory) {
    for (const child of await directoryEntries(entry)) {
      await collect(child, into)
    }
  }
}

/**
 * Every file a drop carries, walking into dropped folders. Entries must be taken from
 * the DataTransfer before the first await: the browser empties it once the drop
 * handler returns.
 */
export async function droppedFiles(dataTransfer: DataTransfer): Promise<DroppedFiles> {
  const items = Array.from(dataTransfer.items ?? [])
  const entries = items
    .filter((item) => item.kind === 'file')
    .map((item) => (item as DataTransferItem & { webkitGetAsEntry?: () => DroppedEntry | null }).webkitGetAsEntry?.() ?? null)

  if (entries.length === 0 || entries.some((entry) => entry === null)) {
    return { files: Array.from(dataTransfer.files ?? []), hadDirectory: false }
  }

  const files: File[] = []
  for (const entry of entries as DroppedEntry[]) {
    await collect(entry, files)
  }
  return { files, hadDirectory: (entries as DroppedEntry[]).some((entry) => entry.isDirectory) }
}
