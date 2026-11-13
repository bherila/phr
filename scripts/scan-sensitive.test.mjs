import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { randomBytes, randomInt } from 'node:crypto'
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const source = path.dirname(fileURLToPath(import.meta.url))
const schema = (dialect) => `database/schema/${dialect}-schema.sql`
const sqlite = 'CREATE TABLE "items"("id" integer not null);\n'
const mysql = 'CREATE TABLE `items` (`id` bigint NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n'
const memberColumn = ['member', 'id'].join('_')
const ledgerName = `${2026}_11_13_000000_create_items_table`

function repository(t) {
  const root = mkdtempSync(path.join(tmpdir(), 'phr-sensitive-scan-'))
  t.after(() => rmSync(root, { recursive: true, force: true }))
  mkdirSync(path.join(root, 'scripts'))
  mkdirSync(path.join(root, 'database/schema'), { recursive: true })
  for (const file of ['scan-sensitive.mjs', 'schema-dump-scan.mjs']) copyFileSync(path.join(source, file), path.join(root, 'scripts', file))
  const git = (...args) => {
    const result = spawnSync('git', args, { cwd: root, encoding: 'utf8' })
    assert.equal(result.status, 0, 'fixture Git operation failed')
    return result.stdout
  }
  git('init', '--quiet')
  return {
    root, git,
    write(file, content, stage = true) {
      writeFileSync(path.join(root, file), content)
      if (stage) git('add', '--', file)
    },
    scan(staged = false) {
      const result = spawnSync(process.execPath, ['scripts/scan-sensitive.mjs', ...(staged ? ['--staged'] : [])], { cwd: root, encoding: 'utf8', timeout: 10000 })
      assert.equal(result.error, undefined, 'scanner must terminate within its focused test bound')
      return { status: result.status, output: result.stdout + result.stderr }
    },
  }
}
function clean(result) { assert.equal(result.status, 0, result.output) }
function refused(result, values = []) {
  assert.equal(result.status, 1, 'unsafe dump must be rejected')
  assert.match(result.output, /\[schema-|\[(?:medical-record-number|real-email|date-of-birth|insurance-member-id|us-ssn|national-provider-id)\]/)
  for (const value of values) assert.ok(!result.output.includes(value), 'diagnostics must never echo the canary')
}
function canaries() {
  const digits = () => String(randomInt(10000000, 99999999))
  const record = ['M', 'R', 'N'].join('') + ': ' + digits()
  const email = `${randomBytes(6).toString('hex')}@mail-${randomBytes(5).toString('hex')}.org`
  return { record, email, identifier: randomBytes(8).toString('hex').toUpperCase(), dob: `${randomInt(1950, 1990)}-04-12` }
}

test('actual generated MySQL and SQLite dumps, ledgers and security triggers pass the CLI', (t) => {
  const repo = repository(t)
  for (const dialect of ['mysql', 'sqlite']) repo.write(schema(dialect), readFileSync(path.join(source, '..', schema(dialect))))
  clean(repo.scan())
  clean(repo.scan(true))
})

test('quoted semicolons, multiline DDL, escaped literals and strict ledger tuples are accepted', (t) => {
  const repo = repository(t)
  repo.write(schema('sqlite'), `${sqlite}CREATE TABLE "notes"(\n"id" integer primary key autoincrement,\n"note" varchar default('safe; it''s a template')\n);\nINSERT INTO migrations VALUES(1,'${ledgerName}',1),(2,'2026_11_13_000001_add_notes',2);`)
  repo.write(schema('mysql'), `${mysql}/*!40101 SET @saved_cs_client = @@character_set_client */;\nCREATE TABLE \`notes\`(\`note\` varchar(200) DEFAULT 'safe; it\\'s a template') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\nINSERT INTO \`migrations\` (\`id\`,\`migration\`,\`batch\`) VALUES (1,'${ledgerName}',1);`)
  clean(repo.scan())
})

test('only a parsed foreign-key keyword gets the insurance-pattern exemption', (t) => {
  const repo = repository(t)
  repo.write(schema('sqlite'), `CREATE TABLE "membership"("${memberColumn}" integer, foreign key("${memberColumn}") REFERENCES "items"("id"));`)
  clean(repo.scan())
  const { identifier } = canaries()
  repo.write('ordinary.txt', `${memberColumn}: ${identifier}`)
  const result = repo.scan()
  assert.equal(result.status, 1)
  assert.match(result.output, /insurance-member-id/)
  // The generic scanner's existing redaction policy is deliberately unchanged.
  assert.ok(!result.output.includes(identifier))
  // A SQL-looking keyword in ordinary source must not gain a global exemption.
  repo.write('ordinary.txt', `"${memberColumn}") REFERENCES "items"("id")`)
  const ordinaryKeyword = repo.scan()
  assert.equal(ordinaryKeyword.status, 1)
  assert.match(ordinaryKeyword.output, /insurance-member-id/)
})

test('all non-ledger writes and unsupported statements are rejected', (t) => {
  const repo = repository(t)
  const cases = [
    "INSERT INTO items VALUES(1)", "REPLACE INTO items VALUES(1)", 'UPDATE items SET id=2',
    'DELETE FROM items', 'TRUNCATE TABLE items', 'SELECT 1', 'ALTER TABLE items ADD x text',
    'CREATE TABLE other AS SELECT * FROM items', 'CREATE VIEW other AS SELECT * FROM items',
    'ATTACH DATABASE \'other\' AS other', 'PRAGMA writable_schema=1',
    `INSERT OR REPLACE INTO migrations VALUES(1,'${ledgerName}',1)`,
    'INSERT INTO migrations SELECT * FROM items',
    `INSERT INTO migrations(id,migration,batch,extra) VALUES(1,'${ledgerName}',1,1)`,
    `INSERT INTO migrations(migration,id,batch) VALUES('${ledgerName}',1,1)`,
    `INSERT INTO migrations VALUES(0,'${ledgerName}',1)`,
    `INSERT INTO migrations VALUES(1,'${ledgerName}',-1)`,
    "INSERT INTO migrations VALUES(1,'not_a_migration',1)",
    `INSERT INTO migrations VALUES(1,'${ledgerName}',1) RETURNING id`,
    'CREATE TABLE other(id integer DEFAULT (SELECT 1))',
  ]
  for (const sql of cases) {
    repo.write(schema('sqlite'), `${sqlite}${sql};`)
    refused(repo.scan())
  }
})

test('executable comments cannot conceal DML, arbitrary SET or trigger bodies', (t) => {
  const repo = repository(t)
  const actual = readFileSync(path.join(source, '..', schema('mysql')), 'utf8')
  for (const extra of [
    '/*!50003 INSERT INTO items VALUES(1) */;',
    '/*!50003 REPLACE INTO items VALUES(1) */;',
    '/*!50003 UPDATE items SET id=2 */;',
    '/*!50003 SET @anything=1 */;',
    '/*!50003 SET SQL_MODE=\'new mode\' */;',
    '/*M!50003 DELETE FROM items */;',
    'DELIMITER $$\n',
    '--INSERT INTO items VALUES(1);',
  ]) {
    repo.write(schema('mysql'), mysql + extra)
    refused(repo.scan())
  }
  repo.write(schema('mysql'), actual.replace('THEN OLD.oauth_security_version + 1', 'THEN OLD.oauth_security_version + 2'))
  refused(repo.scan())
  const sqliteDump = readFileSync(path.join(source, '..', schema('sqlite')), 'utf8')
  repo.write(schema('mysql'), mysql)
  repo.write(schema('sqlite'), sqliteDump.replace('WHERE id = NEW.id; END', 'WHERE id = OLD.id; END'))
  refused(repo.scan())
})

test('malformed, incomplete and oversized grammar fails closed without payloads', (t) => {
  const repo = repository(t)
  for (const malformed of [
    '', 'CREATE TABLE x(', 'CREATE TABLE x(id integer)', 'CREATE TABLE x(id integer));',
    "CREATE TABLE x(id varchar DEFAULT 'unclosed);", '/* unclosed', '/* /* nested */ */',
    'CREATE TABLE x(id integer) trailing;', 'CREATE TABLE x(id integer); unexpected;',
    'CREATE TABLE x(id integer DEFAULT (((1))));',
    'CREATE TABLE x(id integer DEFAULT 1 DEFAULT 2);',
    'CREATE TABLE x(id integer NULL NOT NULL);',
  ]) {
    repo.write(schema('sqlite'), malformed)
    refused(repo.scan())
  }
  repo.write(schema('sqlite'), sqlite + ' '.repeat(2 * 1024 * 1024))
  refused(repo.scan())
  refused(repo.scan(true))
})

test('disclosure rules inspect comments and decoded labelled defaults without inline-ignore escapes', (t) => {
  const repo = repository(t)
  const { record, email, identifier, dob } = canaries()
  const cases = [
    `${sqlite}-- ${record} sensitive-scan-ignore\n`,
    `${sqlite}/* ${email} sensitive-scan-ignore */`,
    `CREATE TABLE x(id integer, email varchar DEFAULT '${email}');`,
    `CREATE TABLE x("${memberColumn}" varchar DEFAULT '${identifier}');`,
    `CREATE TABLE x(birth_date varchar DEFAULT '${dob}');`,
    `CREATE TABLE x(note text DEFAULT '${record}');`,
    `CREATE TABLE x("${record.slice(0, 3)}" integer DEFAULT ${record.split(': ')[1]});`,
    `CREATE TABLE x(note text DEFAULT '${record.replace(': ', ':\n')}');`,
  ]
  for (const sql of cases) {
    repo.write(schema('sqlite'), sql)
    refused(repo.scan(), [record, email, identifier, dob])
  }
  repo.write(schema('sqlite'), sqlite)
  repo.write(schema('mysql'), `${mysql}CREATE TABLE \`extra\`(\`note\` text DEFAULT '${record.replace(': ', ':\\n')}') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;`)
  refused(repo.scan(), [record, email, identifier, dob])
  // Even an explicit value allowlist cannot admit patient rows.
  writeFileSync(path.join(repo.root, 'scripts/sensitive-scan-allow.txt'), `${email}\n`)
  repo.write(schema('mysql'), mysql)
  repo.write(schema('sqlite'), `${sqlite}INSERT INTO items VALUES('${email}'); -- sensitive-scan-ignore`)
  refused(repo.scan(), [email])
})

test('known schema paths cannot escape via NUL, non-UTF-8, symlinks or missing tracked files', (t) => {
  const repo = repository(t)
  const file = schema('sqlite')
  for (const bytes of [Buffer.from(`${sqlite}\0`), Buffer.concat([Buffer.from(sqlite), Buffer.from([0xff])])]) {
    repo.write(file, bytes)
    refused(repo.scan())
    refused(repo.scan(true))
  }
  repo.write(file, sqlite)
  rmSync(path.join(repo.root, file))
  refused(repo.scan())
  clean(repo.scan(true))
  writeFileSync(path.join(repo.root, 'safe.sql'), sqlite)
  symlinkSync('../../safe.sql', path.join(repo.root, file))
  refused(repo.scan())
  repo.git('add', '--', file)
  refused(repo.scan(true))
})

test('staged mode inspects the index blob, not the working copy', (t) => {
  const repo = repository(t)
  const file = schema('sqlite')
  const { email } = canaries()
  const unsafe = `${sqlite}INSERT INTO items VALUES('${email}');`
  repo.write(file, unsafe)
  repo.write(file, sqlite, false)
  refused(repo.scan(true), [email])
  clean(repo.scan())
  repo.write(file, sqlite)
  repo.write(file, unsafe, false)
  clean(repo.scan(true))
  refused(repo.scan(), [email])
})
