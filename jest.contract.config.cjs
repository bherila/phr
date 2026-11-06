module.exports = {
  rootDir: __dirname,
  testEnvironment: 'node',
  testMatch: ['<rootDir>/tests/contracts/*.contract.ts'],
  testTimeout: 10000,
  maxWorkers: 1,
  transform: {
    '^.+\\.(ts|mjs)$': ['@swc/jest', {
      jsc: { parser: { syntax: 'typescript' }, target: 'es2022' },
    }],
  },
}
