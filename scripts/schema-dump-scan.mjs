/**
 * Closed recognizer for the two Laravel-generated schema dumps, not a SQL parser
 * or executor. New dump syntax needs a reviewed grammar change. All failures are
 * finite codes: never put SQL, identifiers, literals, or exception text in logs.
 */
export const SCHEMA_PATHS = new Map([
  ['database/schema/mysql-schema.sql', 'mysql'],
  ['database/schema/sqlite-schema.sql', 'sqlite'],
])
export const MAX_SCHEMA_BYTES = 2 * 1024 * 1024
const MAX_TOKENS = 150000
const MAX_STATEMENT_TOKENS = 20000
const TYPES = new Set(['varchar', 'char', 'bigint', 'int', 'integer', 'smallint', 'tinyint', 'timestamp', 'datetime', 'date', 'text', 'mediumtext', 'longtext', 'decimal', 'numeric', 'double', 'float', 'binary', 'varbinary', 'blob', 'enum'])
const COLLATIONS = new Set(['utf8mb4_unicode_ci', 'utf8mb4_bin'])

class Rejected extends Error {
  constructor(code, line) {
    super(code)
    this.code = code
    this.line = line
  }
}

function tokenize(text, dialect) {
  const tokens = []
  const segments = []
  let line = 1
  const push = (kind, value, start, tokenLine, end) => {
    if (tokens.length >= MAX_TOKENS) throw new Rejected('schema-complexity', tokenLine)
    tokens.push({ kind, value, start, end, line: tokenLine })
  }
  function lex(start, end) {
    let i = start
    while (i < end) {
      const c = text[i]
      if (/\s/.test(c)) { if (c === '\n') line++; i++; continue }
      const at = i
      const tokenLine = line
      if ((text.startsWith('--', i) && (dialect === 'sqlite' || i + 2 === end || /\s/.test(text[i + 2]))) || (dialect === 'mysql' && c === '#')) {
        while (i < end && text[i] !== '\n') i++
        segments.push({ text: text.slice(at, i), line: tokenLine })
        continue
      }
      if (text.startsWith('/*', i)) {
        const close = text.indexOf('*/', i + 2)
        if (close < 0 || close >= end || text.slice(i + 2, close).includes('/*')) throw new Rejected('schema-malformed', tokenLine)
        if (text[i + 2] === '!') {
          if (dialect !== 'mysql') throw new Rejected('schema-unsupported', tokenLine)
          const version = /^\d{5,6}\s/.exec(text.slice(i + 3, close))
          if (!version) throw new Rejected('schema-unsupported', tokenLine)
          // Executable comments are SQL, not an escape from the grammar.
          line += (version[0].match(/\n/g) ?? []).length
          lex(i + 3 + version[0].length, close)
        } else {
          if (text[i + 2] === '+' || text[i + 2] === 'M') throw new Rejected('schema-unsupported', tokenLine)
          const comment = text.slice(at, close + 2)
          segments.push({ text: comment, line: tokenLine })
          line += (comment.match(/\n/g) ?? []).length
        }
        i = close + 2
        continue
      }
      if (c === "'" || c === '"' || c === '`') {
        const quote = c
        const kind = quote === "'" ? 'string' : 'identifier'
        if ((quote === '`' && dialect !== 'mysql') || (quote === '"' && dialect !== 'sqlite')) throw new Rejected('schema-unsupported', tokenLine)
        let value = ''
        let closed = false
        i++
        while (i < end) {
          if (text[i] === quote) {
            if (text[i + 1] === quote) { value += quote; i += 2; continue }
            i++; closed = true; break
          }
          if (text[i] === '\n') line++
          if (text[i] === '\\' && kind === 'string' && dialect === 'mysql') {
            i++
            if (i >= end) break
            const escaped = { '0': '\0', b: '\b', n: '\n', r: '\r', t: '\t', Z: '\x1a' }
            value += escaped[text[i]] ?? text[i]
            i++
          } else { value += text[i++]; }
        }
        if (!closed) throw new Rejected('schema-malformed', tokenLine)
        push(kind, value, at, tokenLine, i)
        segments.push({ text: value, line: tokenLine })
        continue
      }
      const word = /^[A-Za-z_][A-Za-z0-9_$]*/.exec(text.slice(i, end))
      if (word) { i += word[0].length; push('word', word[0], at, tokenLine, i); continue }
      const number = /^\d+(?:\.\d+)?/.exec(text.slice(i, end))
      if (number) { i += number[0].length; push('number', number[0], at, tokenLine, i); continue }
      const op = text.startsWith('<=>', i) ? '<=>' : c
      if (!['(', ')', ',', ';', '=', '@', '.', '+', '-', '<=>'].includes(op)) throw new Rejected('schema-unsupported', tokenLine)
      i += op.length
      push('symbol', op, at, tokenLine, i)
    }
  }
  lex(0, text.length)
  return { tokens, segments }
}

// These are session scaffolding emitted by the current mysqldump, not arbitrary SET.
const MYSQL_SETTINGS = [
  '@OLD_TIME_ZONE=@@TIME_ZONE', "TIME_ZONE='+00:00'", 'TIME_ZONE=@OLD_TIME_ZONE',
  '@OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS,UNIQUE_CHECKS=0', 'UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS',
  '@OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS,FOREIGN_KEY_CHECKS=0', 'FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS',
  "@OLD_SQL_MODE=@@SQL_MODE,SQL_MODE='NO_AUTO_VALUE_ON_ZERO'", 'SQL_MODE=@OLD_SQL_MODE',
  '@OLD_SQL_NOTES=@@SQL_NOTES,SQL_NOTES=0', 'SQL_NOTES=@OLD_SQL_NOTES',
  '@saved_cs_client=@@character_set_client', '@saved_cs_results=@@character_set_results',
  '@saved_col_connection=@@collation_connection', '@saved_sql_mode=@@sql_mode',
  'character_set_client=utf8mb4', 'character_set_results=utf8mb4', 'collation_connection=utf8mb4_unicode_ci',
  'character_set_client=@saved_cs_client', 'character_set_results=@saved_cs_results',
  'collation_connection=@saved_col_connection', 'sql_mode=@saved_sql_mode',
  "sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
]
const SQLITE_TRIGGER = 'CREATE TRIGGER users_increment_oauth_security_version AFTER UPDATE OF user_role ON users FOR EACH ROW WHEN OLD.user_role IS NOT NEW.user_role BEGIN UPDATE users SET oauth_security_version = OLD.oauth_security_version + 1 WHERE id = NEW.id; END'
const MYSQL_TRIGGER = 'CREATE DEFINER=`root`@`localhost` TRIGGER users_increment_oauth_security_version BEFORE UPDATE ON users FOR EACH ROW SET NEW.oauth_security_version = CASE WHEN NOT (OLD.user_role <=> NEW.user_role) THEN OLD.oauth_security_version + 1 ELSE NEW.oauth_security_version END'
const signature = (tokens) => JSON.stringify(tokens.map((t) => [t.kind === 'identifier' ? 'word' : t.kind, t.kind === 'string' ? t.value : t.value.toLowerCase()]))
const MYSQL_SET_SIGNATURES = new Set(MYSQL_SETTINGS.map((s) => signature(tokenize(`SET ${s}`, 'mysql').tokens)))

class Grammar {
  constructor(tokens, dialect, segments) {
    this.tokens = tokens
    this.dialect = dialect
    this.segments = segments
    this.i = 0
    this.references = new Set()
    this.delimiter = 1
  }
  reject(code = 'schema-unsupported') { throw new Rejected(code, this.tokens[this.i]?.line ?? this.tokens.at(-1)?.line ?? 1) }
  is(value) { const t = this.tokens[this.i]; return t && t.kind !== 'string' && t.kind !== 'identifier' && t.value.toLowerCase() === value }
  eat(value) { if (!this.is(value)) return false; this.i++; return true }
  need(value) { if (!this.eat(value)) this.reject('schema-malformed') }
  identifier() {
    const t = this.tokens[this.i++]
    if (!t || !['identifier', 'word'].includes(t.kind) || !/^[A-Za-z_][A-Za-z0-9_]{0,127}$/.test(t.value)) this.reject('schema-malformed')
    return t
  }
  integer() {
    const t = this.tokens[this.i++]
    if (!t || t.kind !== 'number' || !/^[1-9]\d*$/.test(t.value) || !Number.isSafeInteger(Number(t.value))) this.reject('schema-malformed')
  }
  string() {
    const t = this.tokens[this.i++]
    if (!t || t.kind !== 'string') this.reject('schema-malformed')
    return t
  }
  columns() {
    this.need('(')
    do { this.identifier() } while (this.eat(','))
    this.need(')')
  }
  foreign() {
    this.need('foreign'); this.need('key'); this.columns()
    // Only this successfully parsed REFERENCES token can suppress a raw FK false positive.
    const reference = this.tokens[this.i]
    this.need('references'); this.identifier(); this.columns()
    while (this.eat('on')) {
      if (!this.eat('delete') && !this.eat('update')) this.reject()
      if (this.eat('set')) this.need('null')
      else if (this.eat('no')) this.need('action')
      else if (!this.eat('cascade') && !this.eat('restrict')) this.reject()
    }
    this.references.add(reference.start)
  }
  check(column) {
    this.need('(')
    if (this.eat('json_valid')) {
      this.need('(')
      if (this.identifier().value !== column.value) this.reject()
      this.need(')')
    } else {
      if (this.identifier().value !== column.value) this.reject()
      this.need('in'); this.need('(')
      do { this.string() } while (this.eat(','))
      this.need(')')
    }
    this.need(')')
  }
  column() {
    const column = this.identifier()
    const type = this.tokens[this.i++]
    if (!type || type.kind !== 'word' || !TYPES.has(type.value.toLowerCase())) this.reject()
    if (this.eat('(')) {
      if (type.value.toLowerCase() === 'enum') {
        do { this.string() } while (this.eat(','))
      } else {
        this.integer()
        if (this.eat(',')) this.integer()
      }
      this.need(')')
    }
    const options = new Set()
    while (!this.is(',') && !this.is(')')) {
      const option = this.tokens[this.i]?.value.toLowerCase()
      const key = option === 'not' ? 'null' : option
      if (options.has(key)) this.reject('schema-malformed')
      options.add(key)
      if (this.dialect === 'sqlite' && ['unsigned', 'auto_increment', 'character', 'collate', 'comment'].includes(option)) this.reject()
      if (this.dialect === 'mysql' && option === 'autoincrement') this.reject()
      if (this.eat('not')) this.need('null')
      else if (this.eat('primary')) this.need('key')
      else if (this.eat('character')) { this.need('set'); this.need('utf8mb4') }
      else if (this.eat('collate')) { if (!COLLATIONS.has(this.identifier().value)) this.reject() }
      else if (this.eat('default')) {
        const wrapped = this.dialect === 'sqlite' && this.eat('(')
        const t = this.tokens[this.i]
        if (t?.kind === 'string') {
          this.i++
          this.segments.push({ text: `${column.value}: ${t.value}`, line: t.line })
        } else if (t?.kind === 'number') {
          this.i++
          this.segments.push({ text: `${column.value}: ${t.value}`, line: t.line })
        }
        else if (this.eat('null')) { /* no data */ }
        else if (this.eat('current_timestamp')) {
          if (this.eat('(')) this.need(')')
        } else this.reject()
        if (wrapped) this.need(')')
      } else if (this.eat('comment')) this.string()
      else if (this.eat('check')) this.check(column)
      else if (!this.eat('null') && !this.eat('unsigned') && !this.eat('auto_increment') && !this.eat('autoincrement') && !this.eat('unique')) this.reject()
    }
  }
  table() {
    this.need('table'); this.identifier(); this.need('(')
    do {
      if (this.eat('constraint')) { this.identifier(); this.foreign() }
      else if (this.is('foreign')) this.foreign()
      else if (this.eat('primary')) { this.need('key'); this.columns() }
      else if (this.eat('unique')) {
        if (this.eat('key') || this.eat('index')) this.identifier()
        this.columns()
      } else if (this.eat('key') || this.eat('index')) { this.identifier(); this.columns() }
      else this.column()
    } while (this.eat(','))
    this.need(')')
    if (this.dialect === 'mysql') {
      this.need('engine'); this.need('='); this.need('innodb')
      this.need('default'); this.need('charset'); this.need('='); this.need('utf8mb4')
      this.need('collate'); this.need('='); this.need('utf8mb4_unicode_ci')
    }
  }
  ledger() {
    this.need('insert'); this.need('into')
    if (this.identifier().value !== 'migrations') this.reject('schema-data')
    if (this.eat('(')) {
      for (const [i, name] of ['id', 'migration', 'batch'].entries()) {
        if (i) this.need(',')
        if (this.identifier().value !== name) this.reject('schema-data')
      }
      this.need(')')
    }
    this.need('values')
    do {
      this.need('('); this.integer(); this.need(',')
      const migration = this.string()
      if (!/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z][a-z0-9_]{0,180}$/.test(migration.value)) this.reject('schema-data')
      this.need(','); this.integer(); this.need(')')
    } while (this.eat(','))
  }
  trigger(start) {
    const expected = tokenize(this.dialect === 'mysql' ? MYSQL_TRIGGER : SQLITE_TRIGGER, this.dialect).tokens
    const actual = this.tokens.slice(start, start + expected.length)
    if (signature(actual) !== signature(expected)) this.reject('schema-trigger')
    this.i = start + expected.length
  }
  statement() {
    const start = this.i
    if (this.eat('create')) {
      if (this.is('table')) this.table()
      else if (this.is('trigger') || this.is('definer')) this.trigger(start)
      else if (this.dialect === 'sqlite') {
        this.eat('unique'); this.need('index'); this.identifier(); this.need('on'); this.identifier(); this.columns()
      } else this.reject()
    } else if (this.is('insert')) this.ledger()
    else if (this.dialect === 'mysql' && this.eat('drop')) { this.need('table'); this.need('if'); this.need('exists'); this.identifier() }
    else if (this.dialect === 'mysql' && this.is('set')) {
      while (this.tokens[this.i] && !this.is(';')) this.i++
      if (!MYSQL_SET_SIGNATURES.has(signature(this.tokens.slice(start, this.i)))) this.reject('schema-setting')
    } else this.reject('schema-data')
    if (this.i - start > MAX_STATEMENT_TOKENS) this.reject('schema-complexity')
    for (let n = 0; n < this.delimiter; n++) this.need(';')
  }
  parse() {
    while (this.i < this.tokens.length) {
      if (this.dialect === 'mysql' && this.eat('delimiter')) {
        const directive = this.tokens[this.i - 1]
        this.need(';')
        this.delimiter = this.eat(';') ? 2 : 1
        if (this.tokens[this.i]?.line === directive.line) this.reject()
      } else this.statement()
    }
    if (this.delimiter !== 1 || this.tokens.length === 0) this.reject('schema-malformed')
  }
}

export function inspectSchemaDump(text, dialect) {
  try {
    const { tokens, segments } = tokenize(text, dialect)
    const grammar = new Grammar(tokens, dialect, segments)
    grammar.parse()
    return { segments, references: grammar.references, error: null }
  } catch (error) {
    // No caller ever receives parser exceptions or payloads.
    return { segments: [], references: new Set(), error: { rule: error instanceof Rejected ? error.code : 'schema-malformed', line: error instanceof Rejected ? error.line : 1 } }
  }
}
