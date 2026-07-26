const sourceMaps = process.env.JEST_INLINE_SOURCEMAPS === '1' ? 'inline' : false;
// Unlike the monorepo this was extracted from, every test here is a games test —
// there is no JEST_EXCLUDE_GAME_TESTS split to preserve.
const defaultTestPathIgnorePatterns = process.env.JEST_INCLUDE_SLOW_TESTS === '1'
  ? []
  : ['\\.slow\\.test\\.[tj]sx?$'];

const shared = {
  // Per-test ceiling with headroom for the shared CI runners (jest default is 5000ms). Combined with
  // the capped worker pool (--maxWorkers in the test:ci:* scripts) this absorbs transient scheduling
  // latency without letting a genuinely hung test run unbounded — the CI job keeps its 10-minute cap.
  // NOTE: Jest 30 does not honor `testTimeout` when it is nested inside a `projects[]` entry, so the
  // effective ceiling is applied via `jest.setTimeout(45000)` in the per-project setup files. This
  // value is kept for documentation and for any non-project invocation.
  testTimeout: 45000,
  moduleNameMapper: {
    '^@/(.*)$': '<rootDir>/resources/js/$1',
    '^@toon-format/toon$': '<rootDir>/resources/js/__mocks__/toon.ts',
    '\\.(css|less|scss|sass)$': '<rootDir>/resources/js/__mocks__/styleMock.ts',
  },
  transformIgnorePatterns: [
    '/node_modules/(?!.*(?:dayjs|three)[@/]).+\\.js$',
  ],
  transform: {
    '^.+\\.(ts|tsx)$': ['@swc/jest', {
      jsc: {
        parser: { syntax: 'typescript', tsx: true, decorators: true },
        transform: { react: { runtime: 'automatic' } },
        target: 'es2022',
      },
      sourceMaps,
    }],
    '^.+\\.(js|jsx|mjs|cjs)$': ['@swc/jest', {
      jsc: {
        parser: { syntax: 'ecmascript', jsx: true },
        transform: { react: { runtime: 'automatic' } },
        target: 'es2022',
      },
      sourceMaps,
    }],
  },
};

module.exports = {
  projects: [
    {
      displayName: 'jsdom',
      testEnvironment: 'jsdom',
      testMatch: [
        '<rootDir>/resources/js/**/*.test.tsx',
        '<rootDir>/tests-ts/**/*.test.tsx',
        '<rootDir>/resources/js/**/*.dom.test.ts',
        '<rootDir>/tests-ts/**/*.dom.test.ts',
      ],
      testPathIgnorePatterns: defaultTestPathIgnorePatterns,
      setupFilesAfterEnv: ['<rootDir>/tests-ts/jest.setup.ts'],
      ...shared,
    },
    {
      displayName: 'node',
      testEnvironment: 'node',
      testMatch: [
        '<rootDir>/resources/js/**/*.test.ts',
        '<rootDir>/tests-ts/**/*.test.ts',
      ],
      testPathIgnorePatterns: [
        '\\.dom\\.test\\.ts$',
        ...defaultTestPathIgnorePatterns,
      ],
      setupFilesAfterEnv: ['<rootDir>/tests-ts/jest.setup.node.ts'],
      ...shared,
    },
  ],
};
