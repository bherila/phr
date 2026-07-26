declare module '@cornerstonejs/codec-openjpeg/decodewasmjs' {
  const factory: (options: { locateFile: () => string }) => Promise<unknown>
  export default factory
}
