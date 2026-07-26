export function encode(input: unknown): string {
  return JSON.stringify(input, null, 2)
}

export function decode(input: string): unknown {
  return JSON.parse(input)
}
