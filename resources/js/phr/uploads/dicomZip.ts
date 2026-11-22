import { Inflate, strFromU8 } from 'fflate'

import { isAuxiliaryUploadPath } from './dicomUpload'

/**
 * Reads a ZIP of DICOM files in the browser so it can go through the per-file DICOM
 * upload like a chosen folder. Hospital portals hand imaging out as a ZIP of `0.dcm`,
 * `1.dcm`…, which the Imports page would otherwise send to the EHI/MyChart intake.
 *
 * Only the central directory is read up front, and each member is read by slicing the
 * ZIP, so a large archive is never held in memory whole.
 */

export interface ZipEntry {
  /** The member's path inside the archive, `/`-separated. */
  path: string
  /** 0 stored, 8 deflate; anything else is refused on extraction. */
  method: number
  compressedSize: number
  size: number
  crc32: number
  localHeaderOffset: number
  encrypted: boolean
}

export class ZipReadError extends Error {}

const EOCD_SIGNATURE = 0x06054b50
const ZIP64_EOCD_LOCATOR_SIGNATURE = 0x07064b50
const ZIP64_EOCD_SIGNATURE = 0x06064b50
const CENTRAL_SIGNATURE = 0x02014b50
const LOCAL_SIGNATURE = 0x04034b50
const EOCD_MIN = 22
/** The EOCD record plus the longest comment a ZIP can carry. */
const EOCD_SEARCH = EOCD_MIN + 0xffff
const CHUNK_BYTES = 4 * 1024 * 1024

async function readBytes(blob: Blob): Promise<Uint8Array> {
  if (typeof blob.arrayBuffer === 'function') {
    return new Uint8Array(await blob.arrayBuffer())
  }

  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(new Uint8Array(reader.result as ArrayBuffer))
    reader.onerror = () => reject(reader.error ?? new ZipReadError('The ZIP could not be read.'))
    reader.readAsArrayBuffer(blob)
  })
}

function u16(bytes: Uint8Array, offset: number): number {
  return (bytes[offset] ?? 0) | ((bytes[offset + 1] ?? 0) << 8)
}

function u32(bytes: Uint8Array, offset: number): number {
  return (u16(bytes, offset) | (u16(bytes, offset + 2) << 16)) >>> 0
}

function u64(bytes: Uint8Array, offset: number): number {
  const value = u32(bytes, offset) + u32(bytes, offset + 4) * 0x1_0000_0000
  if (!Number.isSafeInteger(value)) {
    throw new ZipReadError('The ZIP is larger than this browser can address.')
  }
  return value
}

/** Reads the archive's central directory; throws ZipReadError when the file is not a ZIP. */
export async function readZipEntries(zip: Blob): Promise<ZipEntry[]> {
  if (zip.size < EOCD_MIN) {
    throw new ZipReadError('The file is too short to be a ZIP.')
  }

  const tailStart = Math.max(0, zip.size - EOCD_SEARCH)
  const tail = await readBytes(zip.slice(tailStart))
  let eocd = -1
  for (let offset = tail.length - EOCD_MIN; offset >= 0; offset--) {
    if (u32(tail, offset) === EOCD_SIGNATURE) {
      eocd = offset
      break
    }
  }
  if (eocd < 0) {
    throw new ZipReadError('The file has no ZIP directory.')
  }

  let entryCount = u16(tail, eocd + 10)
  let directorySize = u32(tail, eocd + 12)
  let directoryOffset = u32(tail, eocd + 16)

  const locator = eocd - 20
  if (locator >= 0 && u32(tail, locator) === ZIP64_EOCD_LOCATOR_SIGNATURE) {
    const zip64Offset = u64(tail, locator + 8)
    const record = await readBytes(zip.slice(zip64Offset, zip64Offset + 56))
    if (u32(record, 0) !== ZIP64_EOCD_SIGNATURE) {
      throw new ZipReadError('The ZIP64 directory is damaged.')
    }
    entryCount = u64(record, 32)
    directorySize = u64(record, 40)
    directoryOffset = u64(record, 48)
  }

  if (directoryOffset + directorySize > zip.size) {
    throw new ZipReadError('The ZIP directory points past the end of the file.')
  }

  const directory = await readBytes(zip.slice(directoryOffset, directoryOffset + directorySize))
  const entries: ZipEntry[] = []
  let cursor = 0
  for (let index = 0; index < entryCount; index++) {
    if (cursor + 46 > directory.length || u32(directory, cursor) !== CENTRAL_SIGNATURE) {
      throw new ZipReadError('The ZIP directory is damaged.')
    }
    const flags = u16(directory, cursor + 8)
    const method = u16(directory, cursor + 10)
    const crc32 = u32(directory, cursor + 16)
    let compressedSize = u32(directory, cursor + 20)
    let size = u32(directory, cursor + 24)
    const nameLength = u16(directory, cursor + 28)
    const extraLength = u16(directory, cursor + 30)
    const commentLength = u16(directory, cursor + 32)
    let localHeaderOffset = u32(directory, cursor + 42)
    const nameBytes = directory.subarray(cursor + 46, cursor + 46 + nameLength)
    // Bit 11 marks a UTF-8 name; without it the name is CP437, read here as Latin-1,
    // which agrees on the ASCII that DICOM file names use.
    const path = strFromU8(nameBytes, (flags & 0x800) === 0).replace(/\\/g, '/')

    // Zip64 puts the real values, in this order, for exactly the fields saturated above.
    let extra = cursor + 46 + nameLength
    const extraEnd = extra + extraLength
    while (extra + 4 <= extraEnd) {
      const id = u16(directory, extra)
      const length = u16(directory, extra + 2)
      if (id === 0x0001) {
        let field = extra + 4
        if (size === 0xffffffff) {
          size = u64(directory, field)
          field += 8
        }
        if (compressedSize === 0xffffffff) {
          compressedSize = u64(directory, field)
          field += 8
        }
        if (localHeaderOffset === 0xffffffff) {
          localHeaderOffset = u64(directory, field)
        }
      }
      extra += 4 + length
    }

    entries.push({ path, method, compressedSize, size, crc32, localHeaderOffset, encrypted: (flags & 0x1) !== 0 })
    cursor = extraEnd + commentLength
  }

  return entries
}

const CRC_TABLE = (() => {
  const table = new Uint32Array(256)
  for (let n = 0; n < 256; n++) {
    let c = n
    for (let k = 0; k < 8; k++) {
      c = (c & 1) !== 0 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    }
    table[n] = c >>> 0
  }
  return table
})()

function updateCrc(crc: number, bytes: Uint8Array): number {
  let c = crc
  for (let i = 0; i < bytes.length; i++) {
    c = (CRC_TABLE[(c ^ (bytes[i] ?? 0)) & 0xff] ?? 0) ^ (c >>> 8)
  }
  return c
}

function isDirectoryEntry(entry: ZipEntry): boolean {
  return entry.path.endsWith('/')
}

/** Extracts one member, checking its size and CRC so a damaged ZIP is never uploaded as DICOM. */
export async function extractZipEntry(zip: Blob, entry: ZipEntry): Promise<Blob> {
  if (entry.encrypted) {
    throw new ZipReadError(`${entry.path} is encrypted.`)
  }
  if (entry.method !== 0 && entry.method !== 8) {
    throw new ZipReadError(`${entry.path} uses a compression method PHR can't read.`)
  }

  const header = await readBytes(zip.slice(entry.localHeaderOffset, entry.localHeaderOffset + 30))
  if (u32(header, 0) !== LOCAL_SIGNATURE) {
    throw new ZipReadError(`${entry.path} is damaged.`)
  }
  const dataStart = entry.localHeaderOffset + 30 + u16(header, 26) + u16(header, 28)
  const dataEnd = dataStart + entry.compressedSize
  if (dataEnd > zip.size) {
    throw new ZipReadError(`${entry.path} runs past the end of the ZIP.`)
  }

  const parts: Uint8Array[] = []
  let crc = 0xffffffff
  let produced = 0
  const accept = (bytes: Uint8Array): void => {
    if (bytes.length === 0) return
    crc = updateCrc(crc, bytes)
    produced += bytes.length
    parts.push(bytes)
  }

  let inflateError: unknown = null
  const inflate = entry.method === 8
    ? new Inflate((chunk) => accept(chunk))
    : null

  for (let offset = dataStart; offset < dataEnd; offset += CHUNK_BYTES) {
    const end = Math.min(dataEnd, offset + CHUNK_BYTES)
    const chunk = await readBytes(zip.slice(offset, end))
    if (inflate) {
      try {
        inflate.push(chunk, end === dataEnd)
      } catch (caught) {
        inflateError = caught
        break
      }
    } else {
      accept(chunk)
    }
  }
  if (inflate && dataStart === dataEnd) {
    try {
      inflate.push(new Uint8Array(0), true)
    } catch (caught) {
      inflateError = caught
    }
  }

  if (inflateError !== null || produced !== entry.size || ((crc ^ 0xffffffff) >>> 0) !== entry.crc32) {
    throw new ZipReadError(`${entry.path} is damaged.`)
  }

  return new Blob(parts as BlobPart[], { type: 'application/dicom' })
}

function basename(path: string): string {
  return path.split('/').pop() ?? path
}

function extension(path: string): string {
  const name = basename(path).toLowerCase()
  return name.includes('.') ? name.split('.').pop() ?? '' : ''
}

/** A name only DICOM uses: `*.dcm`, `*.dicom`, or a `DICOMDIR` index. */
export function isDicomFileName(path: string): boolean {
  const ext = extension(path)
  return ext === 'dcm' || ext === 'dicom' || basename(path).toUpperCase() === 'DICOMDIR'
}

/**
 * Whether a ZIP is imaging rather than a records export: it names DICOM members, and
 * everything else in it is either a name DICOM media uses or burned-CD debris the
 * uploader drops anyway. An EHI export carries `.tsv` tables (and may carry `.dcm`
 * media among them) and a MyChart export carries an IHE XDM package, so either has a
 * member that fails this test and stays with the EHI/MyChart intake.
 */
export function isDicomZip(entries: ZipEntry[]): boolean {
  const files = entries.filter((entry) => !isDirectoryEntry(entry))
  if (!files.some((entry) => isDicomFileName(entry.path))) {
    return false
  }

  return files.every((entry) => {
    const segments = entry.path.toLowerCase().split('/')
    if (segments.includes('ihe_xdm') || segments.at(-1) === 'metadata.xml') {
      return false
    }
    if (isDicomFileName(entry.path) || isAuxiliaryUploadPath(entry.path)) {
      return true
    }
    // Burned CDs name instances without an extension (`IM0001`, `00000001`).
    return extension(entry.path) === ''
  })
}

/** The name a ZIP's members are filed under: the archive's own name without `.zip`. */
export function zipRootName(zip: File): string {
  const stem = zip.name.replace(/\.zip$/i, '').trim()
  return stem === '' ? 'DICOM' : stem
}

/** A File whose relative path is set, as a folder picker would set it. */
export function fileAtPath(contents: Blob, relativePath: string): File {
  const file = new File([contents], basename(relativePath), { type: contents.type || 'application/dicom' })
  Object.defineProperty(file, 'webkitRelativePath', { value: relativePath })
  return file
}

/**
 * Extracts the uploadable members of a DICOM ZIP as Files under `<zip name>/…`, so the
 * upload session is named after the archive and each file keeps its path within it.
 * Debris the uploader would drop is never extracted.
 */
export async function extractDicomZip(zip: File, entries: ZipEntry[]): Promise<File[]> {
  const root = zipRootName(zip)
  const files: File[] = []
  for (const entry of entries) {
    if (isDirectoryEntry(entry) || isAuxiliaryUploadPath(entry.path)) {
      continue
    }
    const path = `${root}/${entry.path.replace(/^\/+/, '')}`
    files.push(fileAtPath(await extractZipEntry(zip, entry), path))
  }
  return files
}
