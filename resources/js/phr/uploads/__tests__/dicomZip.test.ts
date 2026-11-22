import { strFromU8, strToU8, zipSync } from 'fflate'

import { extractDicomZip, extractZipEntry, isDicomZip, readZipEntries, type ZipEntry,ZipReadError } from '@/phr/uploads/dicomZip'

/** A Part 10 header: 128-byte preamble, then `DICM`. */
function dicomBytes(marker: string): Uint8Array {
  const body = strToU8(`DICM${marker}`.padEnd(400, 'x'))
  const bytes = new Uint8Array(128 + body.length)
  bytes.set(body, 128)
  return bytes
}

function zipFile(name: string, members: Record<string, Uint8Array>, level: 0 | 6 = 6): File {
  return new File([zipSync(members, { level }) as BlobPart], name, { type: 'application/zip' })
}

function entry(path: string): ZipEntry {
  return { path, method: 8, compressedSize: 1, size: 1, crc32: 0, localHeaderOffset: 0, encrypted: false }
}

async function text(blob: Blob): Promise<string> {
  return strFromU8(new Uint8Array(await blob.arrayBuffer()), true)
}

describe('readZipEntries and extractDicomZip', () => {
  it.each([[0], [6]] as const)('unpacks a portal ZIP of numbered .dcm files (level %i) under the ZIP’s name', async (level) => {
    const zip = zipFile('Doe_Jane_00000000(2).zip', { '0.dcm': dicomBytes('zero'), '1.dcm': dicomBytes('one') }, level)

    const entries = await readZipEntries(zip)
    expect(isDicomZip(entries)).toBe(true)
    const files = await extractDicomZip(zip, entries)

    expect(files.map((file) => file.webkitRelativePath)).toEqual(['Doe_Jane_00000000(2)/0.dcm', 'Doe_Jane_00000000(2)/1.dcm'])
    expect(files.map((file) => file.name)).toEqual(['0.dcm', '1.dcm'])
    expect(files[1]?.size).toBe(dicomBytes('one').length)
    expect((await text(files[1] as File)).slice(128, 135)).toBe('DICMone')
  })

  it('keeps a CD’s folder structure and leaves its viewer behind', async () => {
    const zip = zipFile('cd.zip', {
      'DICOMDIR': dicomBytes('dir'),
      'DICOM/ST000/IM0001': dicomBytes('im1'),
      'autorun.inf': strToU8('[autorun]'),
      'viewer/viewer.exe': strToU8('MZ'),
    })

    const entries = await readZipEntries(zip)
    expect(isDicomZip(entries)).toBe(true)
    const files = await extractDicomZip(zip, entries)

    expect(files.map((file) => file.webkitRelativePath)).toEqual(['cd/DICOMDIR', 'cd/DICOM/ST000/IM0001'])
  })

  it('refuses a member whose bytes do not match its CRC, rather than upload them', async () => {
    const bytes = zipSync({ '0.dcm': dicomBytes('zero') }, { level: 0 })
    const zip = new File([bytes as BlobPart], 'damaged.zip')
    const [member] = await readZipEntries(zip)
    // Flip one stored byte inside the member's data.
    const damaged = bytes.slice()
    const at = 30 + '0.dcm'.length + 200
    damaged[at] = (damaged[at] ?? 0) ^ 0xff

    await expect(extractZipEntry(new File([damaged as BlobPart], 'damaged.zip'), member as ZipEntry)).rejects.toBeInstanceOf(ZipReadError)
  })

  it('reads Zip64 sizes and offsets from the extra field', async () => {
    // A one-member stored archive written with Zip64 records, as large exports are.
    const name = strToU8('0.dcm')
    const data = dicomBytes('z64')
    const crc = (await readZipEntries(zipFile('x.zip', { '0.dcm': data }, 0)))[0]?.crc32 ?? 0
    const parts: number[] = []
    const le = (value: number, bytes: number): void => {
      for (let i = 0; i < bytes; i++) parts.push(Math.floor(value / 2 ** (8 * i)) & 0xff)
    }
    // Local header with saturated sizes and a Zip64 extra.
    le(0x04034b50, 4); le(45, 2); le(0, 2); le(0, 2); le(0, 4); le(crc, 4); le(0xffffffff, 4); le(0xffffffff, 4)
    le(name.length, 2); le(20, 2); parts.push(...name); le(1, 2); le(16, 2); le(data.length, 8); le(data.length, 8)
    parts.push(...data)
    const centralOffset = parts.length
    le(0x02014b50, 4); le(45, 2); le(45, 2); le(0, 2); le(0, 2); le(0, 4); le(crc, 4); le(0xffffffff, 4); le(0xffffffff, 4)
    le(name.length, 2); le(28, 2); le(0, 2); le(0, 2); le(0, 2); le(0, 4); le(0xffffffff, 4); parts.push(...name)
    le(1, 2); le(24, 2); le(data.length, 8); le(data.length, 8); le(0, 8)
    const centralSize = parts.length - centralOffset
    const zip64Offset = parts.length
    le(0x06064b50, 4); le(44, 8); le(45, 2); le(45, 2); le(0, 4); le(0, 4); le(1, 8); le(1, 8); le(centralSize, 8); le(centralOffset, 8)
    le(0x07064b50, 4); le(0, 4); le(zip64Offset, 8); le(1, 4)
    le(0x06054b50, 4); le(0, 2); le(0, 2); le(0xffff, 2); le(0xffff, 2); le(0xffffffff, 4); le(0xffffffff, 4); le(0, 2)
    const zip = new File([new Uint8Array(parts) as BlobPart], 'z64.zip')

    const entries = await readZipEntries(zip)

    expect(entries).toEqual([expect.objectContaining({ path: '0.dcm', size: data.length, compressedSize: data.length, localHeaderOffset: 0 })])
    expect((await text(await extractZipEntry(zip, entries[0] as ZipEntry))).slice(128, 135)).toBe('DICMz64')
  })

  it('says a file that is not a ZIP is not one', async () => {
    await expect(readZipEntries(new File(['PK\u0003\u0004xx'], 'short.zip'))).rejects.toBeInstanceOf(ZipReadError)
    await expect(readZipEntries(new File(['x'.repeat(100)], 'plain.zip'))).rejects.toBeInstanceOf(ZipReadError)
  })
})

describe('isDicomZip', () => {
  it('leaves an Epic EHI export with DICOM media to the EHI intake', () => {
    expect(isDicomZip([entry('PATIENT.tsv'), entry('ORDER_PROC.tsv'), entry('media/0001.dcm')])).toBe(false)
  })

  it('leaves a MyChart XDM package with DICOM in it to the MyChart intake', () => {
    expect(isDicomZip([entry('IHE_XDM/Jane/DOC0001.XML'), entry('IHE_XDM/Jane/IMG0001.dcm')])).toBe(false)
    expect(isDicomZip([entry('METADATA.XML'), entry('IMG0001.dcm')])).toBe(false)
  })

  it('needs a DICOM name: extensionless files alone are not enough', () => {
    expect(isDicomZip([entry('IM0001'), entry('IM0002')])).toBe(false)
    expect(isDicomZip([entry('scan.pdf')])).toBe(false)
    expect(isDicomZip([entry('DICOM/'), entry('DICOM/IM0001'), entry('DICOMDIR')])).toBe(true)
  })

  it('keeps a ZIP with a stray document out of the DICOM path', () => {
    expect(isDicomZip([entry('0.dcm'), entry('report.csv')])).toBe(false)
  })
})
