#!/usr/bin/env node
/**
 * Patch a checked-out OHIF Viewer source tree so the resulting build:
 *   1. mounts its React router under `/ohif/` instead of `/`
 *   2. defaults to the built-in `dicomjson` data source instead of the
 *      upstream demo's AWS CloudFront WADO server
 *
 * Both edits target `platform/app/public/config/default.js`. The OHIF config
 * is a plain JS module (assigning to `window.config = { ... }`) so we do
 * surgical regex replaces rather than parsing the whole module.
 *
 * If OHIF ever renames/restructures these fields, this script will fail
 * loudly with a non-zero exit so the workflow stops before producing a
 * misconfigured build.
 *
 * Invoked from .github/workflows/ohif-dist.yml after `yarn install` and
 * before `yarn build`.
 *
 * Usage: node .github/ohif/patch-config.mjs <path-to-ohif-checkout>
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import process from 'node:process';

const ROUTER_BASENAME = '/ohif/';
const DEFAULT_DATA_SOURCE = 'dicomjson';

const checkoutDir = process.argv[2];
if (!checkoutDir) {
  console.error('Usage: patch-config.mjs <ohif-checkout-dir>');
  process.exit(1);
}

const configPath = resolve(checkoutDir, 'platform/app/public/config/default.js');
const before = readFileSync(configPath, 'utf8');

const patches = [
  {
    label: 'routerBasename',
    // matches `routerBasename: null,` or `routerBasename: '/anything',`
    pattern: /routerBasename:\s*(?:null|'[^']*'|"[^"]*"),/,
    replacement: `routerBasename: '${ROUTER_BASENAME}',`,
  },
  {
    label: 'defaultDataSourceName',
    pattern: /defaultDataSourceName:\s*'[^']*',/,
    replacement: `defaultDataSourceName: '${DEFAULT_DATA_SOURCE}',`,
  },
];

let after = before;
for (const { label, pattern, replacement } of patches) {
  if (!pattern.test(after)) {
    console.error(`patch-config: could not find ${label} in ${configPath}`);
    console.error('OHIF config layout may have changed — update patch-config.mjs.');
    process.exit(2);
  }
  after = after.replace(pattern, replacement);
}

if (after === before) {
  console.log('patch-config: no changes (file already patched).');
  process.exit(0);
}

writeFileSync(configPath, after);
console.log(`patch-config: applied ${patches.length} patches to ${configPath}`);
