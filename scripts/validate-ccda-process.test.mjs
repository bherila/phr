import assert from 'node:assert/strict';
import test from 'node:test';

import { generatorFailureExitCode } from './validate-ccda-process.mjs';

test('an empty successful generator result fails closed', () => {
    assert.equal(generatorFailureExitCode(0, ''), 1);
});

test('generator failures preserve their nonzero exit code', () => {
    assert.equal(generatorFailureExitCode(7, ''), 7);
    assert.equal(generatorFailureExitCode(null, ''), 1);
});

test('nonempty successful output continues validation', () => {
    assert.equal(generatorFailureExitCode(0, '<ClinicalDocument/>'), null);
});
