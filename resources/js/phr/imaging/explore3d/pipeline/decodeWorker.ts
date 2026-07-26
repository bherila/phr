import { decodeDicomSlice } from './dicomSlice'
import type { DecodeWorkerRequest, DecodeWorkerResponse } from './protocol'

const scope = self as unknown as {
  onmessage: ((event: MessageEvent<DecodeWorkerRequest>) => void) | null
  postMessage: (message: DecodeWorkerResponse, transfer?: Transferable[]) => void
}

scope.onmessage = (event: MessageEvent<DecodeWorkerRequest>) => {
  const request = event.data
  if (request.type !== 'decode') {
    return
  }
  void handleDecode(request.instanceId, request.url, request.transferSyntaxUid)
}

async function handleDecode(instanceId: number, url: string, transferSyntaxUid: string): Promise<void> {
  try {
    const response = await fetch(url, { credentials: 'include' })
    if (!response.ok) {
      throw new Error(`download failed (${response.status})`)
    }
    const buffer = await response.arrayBuffer()
    const slice = await decodeDicomSlice(buffer, transferSyntaxUid)
    scope.postMessage(
      {
        type: 'slice-decoded',
        instanceId,
        pixels: slice.pixels.buffer as ArrayBuffer,
        rows: slice.rows,
        columns: slice.columns,
        geom: slice.geom,
      },
      [slice.pixels.buffer as ArrayBuffer],
    )
  } catch (caught: unknown) {
    scope.postMessage({
      type: 'slice-error',
      instanceId,
      message: caught instanceof Error ? caught.message : String(caught),
    })
  }
}
