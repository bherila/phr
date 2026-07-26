import dicomParser from 'dicom-parser'

import type { SliceGeometry } from './volumeAssembly'

const JPEG2000_TRANSFER_SYNTAXES = new Set(['1.2.840.10008.1.2.4.90', '1.2.840.10008.1.2.4.91'])
const NATIVE_TRANSFER_SYNTAXES = new Set(['1.2.840.10008.1.2', '1.2.840.10008.1.2.1'])

export interface DecodedSlicePixels {
  pixels: Int16Array
  rows: number
  columns: number
  geom: SliceGeometry
}

interface J2kFrameInfo {
  width: number
  height: number
  bitsPerSample: number
  componentCount: number
  isSigned: boolean
}

interface J2kDecoder {
  getEncodedBuffer(length: number): Uint8Array
  decode(): void
  getFrameInfo(): J2kFrameInfo
  getDecodedBuffer(): Uint8Array
  delete(): void
}

interface OpenJpegModule {
  J2KDecoder: new () => J2kDecoder
}

let openJpegModulePromise: Promise<OpenJpegModule> | null = null

/**
 * Lazily initialise the OpenJPEG WASM decoder once per worker. The wasm asset
 * is resolved through Vite so it is bundled/fingerprinted with the worker.
 */
function loadOpenJpeg(): Promise<OpenJpegModule> {
  openJpegModulePromise ??= (async () => {
    const [{ default: factory }, { default: wasmUrl }] = await Promise.all([
      import('@cornerstonejs/codec-openjpeg/decodewasmjs'),
      import('@cornerstonejs/codec-openjpeg/decodewasm?url'),
    ])
    return (await factory({ locateFile: () => wasmUrl })) as OpenJpegModule
  })()
  return openJpegModulePromise
}

function tagFloats(dataSet: dicomParser.DataSet, tag: string, count: number): number[] | null {
  const values: number[] = []
  for (let index = 0; index < count; index++) {
    const value = dataSet.floatString(tag, index)
    if (value === undefined || Number.isNaN(value)) {
      return null
    }
    values.push(value)
  }
  return values
}

function readGeometry(dataSet: dicomParser.DataSet): SliceGeometry {
  const position = tagFloats(dataSet, 'x00200032', 3)
  const orientation = tagFloats(dataSet, 'x00200037', 6)
  const pixelSpacing = tagFloats(dataSet, 'x00280030', 2)
  return {
    position: (position ?? [0, 0, 0]) as [number, number, number],
    orientation: orientation as SliceGeometry['orientation'],
    pixelSpacing: pixelSpacing as SliceGeometry['pixelSpacing'],
  }
}

async function decodeJpeg2000Frame(
  frame: Uint8Array,
): Promise<{ stored: Int16Array | Uint16Array; width: number; height: number }> {
  const openJpeg = await loadOpenJpeg()
  const decoder = new openJpeg.J2KDecoder()
  try {
    const encodedBuffer = decoder.getEncodedBuffer(frame.length)
    encodedBuffer.set(frame)
    decoder.decode()
    const frameInfo = decoder.getFrameInfo()
    if (frameInfo.componentCount !== 1 || frameInfo.bitsPerSample > 16) {
      throw new Error(
        `unsupported JPEG 2000 frame (components=${frameInfo.componentCount}, bits=${frameInfo.bitsPerSample})`,
      )
    }
    const decoded = decoder.getDecodedBuffer()
    /*
     * The codestream's own signedness wins over the DICOM PixelRepresentation
     * tag: real GE sinus CTs declare PixelRepresentation=1 yet encode an
     * unsigned 0..4095 codestream (verified against a live slice in the
     * pipeline spike).
     */
    const stored = frameInfo.isSigned
      ? new Int16Array(decoded.buffer.slice(decoded.byteOffset, decoded.byteOffset + decoded.byteLength))
      : new Uint16Array(decoded.buffer.slice(decoded.byteOffset, decoded.byteOffset + decoded.byteLength))
    return { stored, width: frameInfo.width, height: frameInfo.height }
  } finally {
    decoder.delete()
  }
}

function readNativePixels(
  dataSet: dicomParser.DataSet,
  bytes: Uint8Array,
  pixelRepresentation: number,
  expectedLength: number,
): Int16Array | Uint16Array {
  const pixelElement = dataSet.elements.x7fe00010
  if (!pixelElement) {
    throw new Error('missing pixel data element')
  }
  const byteLength = expectedLength * 2
  if (pixelElement.length < byteLength) {
    throw new Error(`pixel data too short (${pixelElement.length} < ${byteLength})`)
  }
  const view = bytes.buffer.slice(
    bytes.byteOffset + pixelElement.dataOffset,
    bytes.byteOffset + pixelElement.dataOffset + byteLength,
  )
  return pixelRepresentation === 1 ? new Int16Array(view) : new Uint16Array(view)
}

/**
 * Parses one DICOM file and returns its pixels rescaled to Int16 HU/density
 * (`HU = slope * stored + intercept`, read from the file itself — the DB
 * metadata predates the Rescale tags being parsed server-side).
 */
export async function decodeDicomSlice(buffer: ArrayBuffer, transferSyntaxUid: string): Promise<DecodedSlicePixels> {
  const bytes = new Uint8Array(buffer)
  const dataSet = dicomParser.parseDicom(bytes)

  const rows = dataSet.uint16('x00280010')
  const columns = dataSet.uint16('x00280011')
  const bitsAllocated = dataSet.uint16('x00280100')
  if (rows === undefined || columns === undefined) {
    throw new Error('missing rows/columns')
  }
  if (bitsAllocated !== undefined && bitsAllocated !== 16) {
    throw new Error(`unsupported bits allocated: ${bitsAllocated}`)
  }
  const pixelRepresentation = dataSet.uint16('x00280103') ?? 0
  const rescaleSlope = dataSet.floatString('x00281053') ?? 1
  const rescaleIntercept = dataSet.floatString('x00281052') ?? 0

  let stored: Int16Array | Uint16Array
  if (JPEG2000_TRANSFER_SYNTAXES.has(transferSyntaxUid)) {
    const pixelElement = dataSet.elements.x7fe00010
    if (!pixelElement?.encapsulatedPixelData || !pixelElement.fragments?.length) {
      throw new Error('expected encapsulated pixel data')
    }
    const frame = dicomParser.readEncapsulatedPixelDataFromFragments(
      dataSet,
      pixelElement,
      0,
      pixelElement.fragments.length,
    )
    const decoded = await decodeJpeg2000Frame(frame)
    if (decoded.width !== columns || decoded.height !== rows) {
      throw new Error(`decoded size ${decoded.width}x${decoded.height} != ${columns}x${rows}`)
    }
    stored = decoded.stored
  } else if (NATIVE_TRANSFER_SYNTAXES.has(transferSyntaxUid)) {
    stored = readNativePixels(dataSet, bytes, pixelRepresentation, rows * columns)
  } else {
    throw new Error(`unsupported transfer syntax: ${transferSyntaxUid}`)
  }

  if (stored.length < rows * columns) {
    throw new Error(`decoded pixel count ${stored.length} < ${rows * columns}`)
  }

  const pixels = new Int16Array(rows * columns)
  for (let index = 0; index < pixels.length; index++) {
    const value = (stored[index] as number) * rescaleSlope + rescaleIntercept
    pixels[index] = Math.max(-32768, Math.min(32767, Math.round(value)))
  }

  return { pixels, rows, columns, geom: readGeometry(dataSet) }
}
