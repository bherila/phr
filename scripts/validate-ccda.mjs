import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';

import { generatorFailureExitCode } from './validate-ccda-process.mjs';

const require = createRequire(import.meta.url);
const { validate } = require('cda-schematron-validator');

const root = resolve(import.meta.dirname, '..');
const cache = join(tmpdir(), 'phr-ccda-validation-v5');
const fixture = join(cache, 'synthetic-ccda.xml');

// Pin both official HL7 repositories by immutable commit and every downloaded byte by
// SHA-256. A moving branch would let a standards update silently change this gate.
const cdaCoreCommit = 'e922fc35586fd2629f0c8a021080bca9ab424e18';
const ccdaCommit = 'af05b6bde9409182428580148ce41c8b3c4da3ab';
const schemaBase = `https://raw.githubusercontent.com/HL7/CDA-core-2.0/${cdaCoreCommit}/schema/extensions/SDTC`;
const schemaFiles = new Map([
    ['infrastructure/cda/CDA_SDTC.xsd', 'd596141f0a457b7b31c1a5b4e97ae55d16bedf8c08356e644475c339263d76e7'],
    ['infrastructure/cda/POCD_MT000040_SDTC.xsd', 'a9d1169721efe71124f2c5f7a7d53441a2129424742336e8324f27bce80e1eaf'],
    ['infrastructure/cda/SDTC.xsd', '3a16dbaa0526005850eaf32f4f061ba4db63ee1f9e1267783c8f734feaf73a58'],
    ['processable/coreschemas/NarrativeBlock.xsd', '92a9ec2c6c00d10cd40a9afdf4d70f18c823bdec15db9e8b116cb5076d11f66e'],
    ['processable/coreschemas/datatypes-base_SDTC.xsd', '832527e03eac5cb671880b87c9515e55c2089e8ef3fc82634e69c7337adb440f'],
    ['processable/coreschemas/datatypes.xsd', '0238ba379eec458d9989ff2c2d9012da2964c9d29d5177cf2d74d4c17a7a6be2'],
    ['processable/coreschemas/infrastructureRoot.xsd', 'dff44f710386745645ffe96c1d46629062e07d4f03b82ef26e9ba180082432c9'],
    ['processable/coreschemas/voc.xsd', '63bacc8e6c0a662fe630b3377950a1bad8fa659242021a5db0d4778762ae8099'],
]);
const schematron = {
    path: 'CCDA-5.0.sch',
    url: `https://raw.githubusercontent.com/HL7/CDA-ccda/${ccdaCommit}/validation/CCDA-5.0.sch`,
    sha256: '679bfc6bf680a5e7e6afe9086e53e18b92e67882a389590c4647c4424418efc2',
};

const expectedErrors = [
    ['AllergiesAndIntolerancesSection-errors', 'AllergiesAndIntolerancesSection-errors-root', 'If section/@nullFlavor is not present, SHALL contain at least one Allergy Concern Act'],
    ['MedicationsSection-errors', 'MedicationsSection-errors-root', 'If section/@nullFlavor is not present, SHALL contain at least one Medication Activity'],
    ['ProblemSection-errors', 'ProblemSection-errors-root', 'If section/@nullFlavor is not present, SHALL contain at least one Problem Concern Act'],
    ['ProceduresSection-errors', 'ProceduresSection-errors-root', 'If section/@nullFlavor is not present, SHALL contain at least one Procedure Activity Procedure'],
    ['ResultsSection-errors', 'ResultsSection-errors-root', 'If section/@nullFlavor is not present, SHALL contain at least one Result Organizer'],
    ['VitalSignsSection-errors', 'VitalSignsSection-errors-root', 'If section/@nullFlavor is not present, SHALL contain at least one Vital Signs Organizer or Average Blood Pressure Organizer'],
    ['ImmunizationsSection-errors', 'ImmunizationsSection-errors-root', 'If section/@nullFlavor is not present, SHALL contain at least one Immunization Activity'],
    ['EncountersSection-errors', 'EncountersSection-errors-root', 'If section/@nullFlavor is not present, SHALL contain at least one Encounter Activity'],
];

const expectedWarnings = [
    ['Documents SHOULD contain a RelatedPerson participant', 3],
    ['SHOULD contain Smoking Status', 1],
    ['SHOULD contain a Plan of Treatment Section', 1],
    ["SHOULD contain an id with root='2.16.840.1.113883.4.6' (NPI)", 3],
    ['SHOULD contain code', 5],
    ['SHOULD contain languageCommunication', 3],
    ['SHOULD contain legalAuthenticator', 3],
    ['SHOULD contain maritalStatusCode', 2],
    ['SHOULD contain performer', 5],
];

// cda-schematron-validator cannot resolve two generated value-set variables in their
// union-context rules. Freeze both known engine limitations too: new ignored assertions
// are regressions, not an excuse to weaken validation.
const expectedIgnored = [
    ['USRealmAddress-errors', 'USRealmAddress-errors-nullFlavor', 'SHALL be selected from ValueSet CDANullFlavor'],
    ['USRealmHeader-warnings', 'USRealmHeader-warnings-confidentialityCode.code', 'SHOULD be selected from ValueSet HL7BasicConfidentialityKind'],
];

function sha256(data) {
    return createHash('sha256').update(data).digest('hex');
}

async function downloadVerified(url, destination, expectedHash) {
    if (existsSync(destination)) {
        const cached = readFileSync(destination);
        if (sha256(cached) === expectedHash) {
            return;
        }
    }

    const response = await fetch(url);
    if (!response.ok) {
        throw new Error(`Unable to fetch pinned HL7 validation artifact (HTTP ${response.status}).`);
    }

    const body = Buffer.from(await response.arrayBuffer());
    if (sha256(body) !== expectedHash) {
        throw new Error('Pinned HL7 validation artifact failed its SHA-256 check.');
    }

    mkdirSync(dirname(destination), { recursive: true });
    writeFileSync(destination, body);
}

function fingerprint(result) {
    return [result.patternId ?? '', result.ruleId ?? '', result.description ?? ''];
}

function warningSummary(results) {
    const counts = new Map();
    for (const result of results) {
        const description = (result.description ?? '').trim().replace(/\s+/g, ' ');
        counts.set(description, (counts.get(description) ?? 0) + 1);
    }

    return [...counts.entries()];
}

function sorted(value) {
    return [...value].sort((left, right) => JSON.stringify(left).localeCompare(JSON.stringify(right)));
}

function assertExact(label, actual, expected) {
    const actualJson = JSON.stringify(sorted(actual));
    const expectedJson = JSON.stringify(sorted(expected));
    if (actualJson !== expectedJson) {
        throw new Error(`${label} changed.\nExpected: ${expectedJson}\nActual:   ${actualJson}`);
    }
}

mkdirSync(cache, { recursive: true });
await Promise.all([
    ...[...schemaFiles.entries()].map(([path, hash]) =>
        downloadVerified(`${schemaBase}/${path}`, join(cache, 'schema', path), hash)),
    downloadVerified(schematron.url, join(cache, schematron.path), schematron.sha256),
]);

const generated = spawnSync('php', [join(root, 'scripts/generate-synthetic-ccda.php')], {
    encoding: 'utf8',
});
const generatorExitCode = generatorFailureExitCode(generated.status, generated.stdout);
if (generatorExitCode !== null) {
    process.stderr.write(generated.stderr);
    if (generated.status === 0) {
        process.stderr.write('Synthetic C-CDA generator produced no output.\n');
    }
    process.exit(generatorExitCode);
}
writeFileSync(fixture, generated.stdout);

const schema = spawnSync('php', [
    join(root, 'scripts/validate-ccda-schema.php'),
    fixture,
    join(cache, 'schema/infrastructure/cda/CDA_SDTC.xsd'),
], { encoding: 'utf8' });

if (schema.status !== 0) {
    process.stderr.write(schema.stderr);
    process.exit(schema.status ?? 1);
}
process.stdout.write(schema.stdout);

const documentXml = readFileSync(fixture, 'utf8');
const schematronXml = readFileSync(join(cache, schematron.path), 'utf8');
const result = validate(documentXml, schematronXml, {
    includeWarnings: true,
    xmlSnippetMaxLength: 1,
});

assertExact('Documented C-CDA v5 conformance gaps', result.errors.map(fingerprint), expectedErrors);
assertExact('Documented C-CDA v5 recommendations', warningSummary(result.warnings), expectedWarnings);
assertExact('Ignored Schematron assertions', result.ignored.map(fingerprint), expectedIgnored);

console.log(`C-CDA v5 Schematron: ${result.errorCount} documented gaps and ${result.warningCount} documented recommendations, no unexpected results`);
