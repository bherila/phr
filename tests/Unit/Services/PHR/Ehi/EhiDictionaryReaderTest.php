<?php

namespace Tests\Unit\Services\PHR\Ehi;

use App\Services\PHR\Import\Ehi\EhiDictionary;
use App\Services\PHR\Import\Ehi\EhiDictionaryReader;
use App\Services\PHR\Import\Ehi\EhiDictionaryTable;
use PHPUnit\Framework\TestCase;

class EhiDictionaryReaderTest extends TestCase
{
    public function test_reads_column_grid_and_primary_key_section(): void
    {
        $dictionary = (new EhiDictionaryReader(1024 * 1024))->read([$this->tablePage('ORDER_RESULTS', [
            ['ORDER_PROC_ID', 'NUMERIC'],
            ['ORD_DATE_REAL', 'FLOAT'],
            ['LINE', 'INTEGER'],
            ['ORD_VALUE', 'VARCHAR'],
        ], ['ORDER_PROC_ID', 'ORD_DATE_REAL', 'LINE'])]);

        self::assertFalse($dictionary->isEmpty());
        self::assertSame(EhiDictionaryReader::VERSION, $dictionary->readerVersion);
        $table = $dictionary->table('ORDER_RESULTS');
        self::assertNotNull($table);
        self::assertSame(['ORDER_PROC_ID', 'ORD_DATE_REAL', 'LINE', 'ORD_VALUE'], $table->columnNames());
        self::assertSame('FLOAT', $table->columns[1]['type']);
        self::assertSame(['ORDER_PROC_ID', 'ORD_DATE_REAL', 'LINE'], $table->primaryKey);
        self::assertSame(1, $dictionary->primaryKeyMarkers);
        self::assertSame(1, $dictionary->primaryKeyTableCount());
    }

    public function test_table_lookup_falls_back_to_one_ascii_case_fold(): void
    {
        $patient = new EhiDictionaryTable('Patient', [['name' => 'PAT_ID', 'type' => 'VARCHAR']], ['PAT_ID']);
        $dictionary = new EhiDictionary([$patient], 'test');

        self::assertSame($patient, $dictionary->table('Patient'));
        self::assertSame($patient, $dictionary->table('PATIENT'), 'a mixed-case heading documents PATIENT.tsv');
        self::assertNull($dictionary->table('PAT_ENC'));

        $ambiguous = new EhiDictionary([$patient, new EhiDictionaryTable('PATIENT', [['name' => 'PAT_ID', 'type' => null]], [])], 'test');
        self::assertSame('PATIENT', $ambiguous->table('PATIENT')?->name, 'an exact name still wins');
        self::assertNull($ambiguous->table('patient'), 'two names that fold together match neither');
    }

    public function test_primary_key_ordinals_order_the_key(): void
    {
        $html = <<<'HTML'
<html><body>
<h2>Table: PAT_IMMUNIZATIONS</h2>
<table><tr><th>Column Name</th><th>Data Type</th></tr>
<tr><td>PAT_ID</td><td>VARCHAR</td></tr><tr><td>LINE</td><td>INTEGER</td></tr><tr><td>IMMUNE_ID</td><td>NUMERIC</td></tr></table>
<h3>Primary Key</h3>
<table><tr><th>Column Name</th><th>Ordinal Position</th></tr>
<tr><td>LINE</td><td>2</td></tr><tr><td>PAT_ID</td><td>1</td></tr></table>
</body></html>
HTML;
        $table = (new EhiDictionaryReader(1024 * 1024))->read([$html])->table('PAT_IMMUNIZATIONS');

        self::assertNotNull($table);
        self::assertSame(['PAT_ID', 'LINE'], $table->primaryKey);
        self::assertSame('NUMERIC', $table->columns[2]['type']);
    }

    public function test_primary_key_column_markers_and_multi_table_grid(): void
    {
        $html = <<<'HTML'
<html><head><title>Catalogue</title></head><body>
<table><thead><tr><th>Table Name</th><th>Column Name</th><th>Type</th><th>PK</th></tr></thead>
<tbody>
<tr><td>IMMUNE</td><td>IMMUNE_ID</td><td>NUMERIC</td><td>Y</td></tr>
<tr><td>IMMUNE</td><td>DOSE</td><td>VARCHAR</td><td></td></tr>
<tr><td>HNO_INFO</td><td>NOTE_ID</td><td>VARCHAR</td><td>yes</td></tr>
<tr><td>not a table</td><td>X</td><td>Y</td><td></td></tr>
</tbody></table></body></html>
HTML;
        $dictionary = (new EhiDictionaryReader(1024 * 1024))->read([$html]);

        self::assertSame(['HNO_INFO', 'IMMUNE'], array_keys($dictionary->tables));
        $immune = $dictionary->table('IMMUNE');
        self::assertNotNull($immune);
        self::assertSame(['IMMUNE_ID'], $immune->primaryKey);
        self::assertSame(['IMMUNE_ID', 'DOSE'], $immune->columnNames());
        self::assertSame(['NOTE_ID'], $dictionary->table('HNO_INFO')?->primaryKey);
        self::assertSame(3, $dictionary->columnCount());
    }

    public function test_many_documents_merge_and_fingerprint_is_canonical(): void
    {
        $reader = new EhiDictionaryReader(1024 * 1024);
        $a = $this->tablePage('PATIENT', [['PAT_ID', 'VARCHAR']], ['PAT_ID']);
        $b = $this->tablePage('PAT_ENC', [['PAT_ENC_CSN_ID', 'NUMERIC'], ['PAT_ID', 'VARCHAR']], ['PAT_ENC_CSN_ID']);

        $forward = $reader->read([$a, $b]);
        $backward = $reader->read((function () use ($a, $b) {
            yield $b;
            yield $a;
        })());

        self::assertSame(2, $forward->documents);
        self::assertSame(['PATIENT', 'PAT_ENC'], array_keys($forward->tables));
        self::assertSame($forward->fingerprint(), $backward->fingerprint());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $forward->fingerprint());

        $changed = $reader->read([$a, $this->tablePage('PAT_ENC', [['PAT_ID', 'VARCHAR'], ['PAT_ENC_CSN_ID', 'NUMERIC']], ['PAT_ENC_CSN_ID'])]);
        self::assertNotSame($forward->fingerprint(), $changed->fingerprint(), 'column order is part of the catalogue');

        $conflict = $reader->read([$a, $this->tablePage('PATIENT', [['PAT_ID', 'NUMERIC']], [])]);
        self::assertSame(1, $conflict->conflictingTables);
        self::assertSame('VARCHAR', $conflict->table('PATIENT')?->columns[0]['type']);
    }

    public function test_unknown_structure_yields_an_empty_catalogue(): void
    {
        $dictionary = (new EhiDictionaryReader(1024 * 1024))->read([
            '<html><body><p>Nothing tabular here.</p><table><tr><td>a</td><td>b</td></tr></table></body></html>',
            '',
            "\x00\x01 not html at all",
        ]);

        self::assertTrue($dictionary->isEmpty());
        self::assertSame(0, $dictionary->columnCount());
        self::assertSame(hash('sha256', '[]'), $dictionary->fingerprint());
    }

    public function test_hostile_html_is_neither_fetched_nor_expanded(): void
    {
        $secretPath = tempnam(sys_get_temp_dir(), 'ehi-entity-');
        self::assertIsString($secretPath);
        file_put_contents($secretPath, 'SYNTHETIC-ENTITY-SECRET');
        $loaderCalls = 0;
        libxml_set_external_entity_loader(function () use (&$loaderCalls) {
            $loaderCalls++;

            return null;
        });

        try {
            $uri = 'file://'.$secretPath;
            $html = <<<HTML
<!DOCTYPE html [<!ENTITY e SYSTEM "{$uri}">]>
<html><head>
<link rel="stylesheet" href="http://127.0.0.1:9/evil.css">
<script src="http://127.0.0.1:9/evil.js"></script>
<script>document.write('<h1>SCRIPT_TABLE</h1>')</script>
<iframe src="{$uri}"></iframe>
</head><body onload="alert(1)">
<h1>PATIENT</h1>
<img src="http://127.0.0.1:9/pixel.png">
<table><tr><th>Column Name</th><th>Type</th></tr>
<tr><td>PAT_ID</td><td>&e;</td></tr>
<tr><td>PAT_NAME</td><td><script>SCRIPT_TYPE</script>VARCHAR</td></tr>
</table></body></html>
HTML;
            $dictionary = (new EhiDictionaryReader(1024 * 1024))->read([$html]);
            $document = EhiDictionaryReader::loadDocument($html);
        } finally {
            libxml_set_external_entity_loader(null);
            @unlink($secretPath);
        }

        self::assertSame(['PATIENT'], array_keys($dictionary->tables));
        $serialized = json_encode(array_map(fn ($table) => $table->toArray(), $dictionary->tables), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SYNTHETIC-ENTITY-SECRET', $serialized);
        self::assertStringNotContainsString('SCRIPT_', $serialized);
        self::assertSame('VARCHAR', $dictionary->table('PATIENT')?->columns[1]['type']);
        self::assertSame(0, $loaderCalls, 'the external entity loader is never consulted');

        self::assertNotNull($document);
        $markup = (string) $document->saveHTML();
        self::assertStringNotContainsString('SYNTHETIC-ENTITY-SECRET', $markup);
        self::assertStringNotContainsStringIgnoringCase('<script', $markup);
        self::assertStringNotContainsStringIgnoringCase('<iframe', $markup);
        self::assertStringNotContainsStringIgnoringCase('onload', $markup);
    }

    public function test_oversize_document_is_refused(): void
    {
        $page = $this->tablePage('PATIENT', [['PAT_ID', 'VARCHAR']], []);
        $dictionary = (new EhiDictionaryReader(strlen($page) - 1))->read([$page]);

        self::assertTrue($dictionary->isEmpty());
        self::assertSame(1, $dictionary->refusedDocuments);
        self::assertSame(0, $dictionary->documents);
    }

    public function test_utf8_text_survives_without_a_charset_declaration(): void
    {
        $html = "<html><body><h1>PATIENT</h1><table><tr><th>Column Name</th><th>Type</th></tr><tr><td>PAT_ID</td><td>VARCHAR\u{00A0}(18) \u{00E9}\u{2014}</td></tr></table></body></html>";
        $table = (new EhiDictionaryReader(1024 * 1024))->read([$html])->table('PATIENT');

        self::assertSame("VARCHAR (18) \u{00E9}\u{2014}", $table?->columns[0]['type']);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $columns
     * @param  list<string>  $key
     */
    private function tablePage(string $name, array $columns, array $key): string
    {
        $rows = implode('', array_map(fn (array $column): string => "<tr><td>{$column[0]}</td><td>{$column[1]}</td><td>Synthetic description</td></tr>", $columns));
        $keyRows = implode('', array_map(fn (string $column): string => "<tr><td>{$column}</td></tr>", $key));

        return <<<HTML
<html><head><title>{$name}</title><meta charset="utf-8"></head><body>
<div class="header"><h1>{$name}</h1><p>Synthetic table description.</p></div>
<h2>Column Information</h2>
<table class="SubList"><tr><th>Column Name</th><th>Type</th><th>Description</th></tr>{$rows}</table>
<h2>Primary Key</h2>
<table class="SubList"><tr><th>Column Name</th></tr>{$keyRows}</table>
</body></html>
HTML;
    }
}
