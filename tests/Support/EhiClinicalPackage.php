<?php

namespace Tests\Support;

use LogicException;

/**
 * A synthetic EHI Tables package shaped for the real mapping contracts
 * (EhiImmunizationMapping, EhiDocumentMapping). Every table's dictionary page
 * declares the primary key the mappings expect, so projection accepts it; every
 * value is invented. Rows are column => value maps; a missing column is an empty
 * cell.
 *
 * standard():
 * - IMMUNE: 601 and 602 Given on the same day (different vaccines), 603 a
 *   historical Given dose, 604 Deleted, 605 an orphan (no PAT_IMMUNIZATIONS
 *   row), 606 Given on the day an existing FHIR-style record holds the same vaccine
 * - PAT_IMMUNIZATIONS links 601, 602, 603, 604, 606 to PATIENT_KEY
 * - HNO_INFO: note 88001 with two contacts on one day (fractional
 *   CONTACT_DATE_REAL) and plain-text lines for both, 88002 with no table text
 *   whose DOC_INFORMATION.SCAN_FILE names an RTF package file; note 88003 is deleted
 * - one RTF file that no documented reference reaches
 */
final class EhiClinicalPackage
{
    public const string PATIENT_KEY = 'Z00094567';

    public const string RTF_MEMBER = 'notes/SYN-SCAN-88002.rtf';

    public const string RTF_BYTES = '{\\rtf1\\ansi\\deff0 {\\fonttbl{\\f0 Synthetic;}}\\pard Synthetic scanned note line one.\\par Synthetic scanned note line two.\\par}';

    public const string UNLINKED_RTF_MEMBER = 'notes/SYN-UNLINKED.rtf';

    /** Contact 9102 is the later contact of note 88001; its lines, in LINE order. */
    public const string NOTE_88001_TEXT = "Synthetic final line 1\nSynthetic final line 2\nSynthetic final line 10";

    /** @var array<string, array{columns: list<string>, key: list<string>}> */
    private const array TABLES = [
        'PATIENT' => ['columns' => ['PAT_ID', 'PAT_NAME'], 'key' => ['PAT_ID']],
        'IMMUNE' => ['columns' => [
            'IMMUNE_ID', 'IMMUNZATN_ID', 'IMMUNZATN_ID_NAME', 'IMMUNE_DATE', 'IMMUNIZATION_TIME',
            'IMMNZTN_STATUS_C_NAME', 'IMM_HISTORIC_ADM_YN', 'DOSE', 'IMMNZTN_DOSE_AMOUNT',
            'IMMNZTN_DOSE_UNIT_C_NAME', 'LOT', 'MFG_C_NAME', 'ROUTE_C_NAME', 'SITE_C_NAME',
        ], 'key' => ['IMMUNE_ID']],
        'PAT_IMMUNIZATIONS' => ['columns' => ['PAT_ID', 'LINE', 'IMMUNE_ID'], 'key' => ['PAT_ID', 'LINE']],
        'PAT_ENC' => ['columns' => ['PAT_ENC_CSN_ID', 'PAT_ID', 'CONTACT_DATE'], 'key' => ['PAT_ENC_CSN_ID']],
        'HNO_INFO' => ['columns' => [
            'NOTE_ID', 'PAT_ENC_CSN_ID', 'IP_NOTE_TYPE_C_NAME', 'NOTE_DESC', 'CREATE_INSTANT_DTTM',
            'DATE_OF_SERVIC_DTTM', 'DELETE_INSTANT_DTTM', 'UNSIGNED_YN',
        ], 'key' => ['NOTE_ID']],
        'NOTE_ENC_INFO' => ['columns' => [
            'CONTACT_SERIAL_NUM', 'NOTE_ID', 'CONTACT_DATE_REAL', 'CONTACT_DATE', 'NOTE_STATUS_C_NAME', 'AUTHOR_USER_ID_NAME',
        ], 'key' => ['CONTACT_SERIAL_NUM']],
        'HNO_PLAIN_TEXT' => ['columns' => ['NOTE_CSN_ID', 'LINE', 'NOTE_ID', 'NOTE_TEXT'], 'key' => ['NOTE_CSN_ID', 'LINE']],
        'DOC_INFORMATION' => ['columns' => [
            'DOC_INFO_ID', 'DOC_PT_ID', 'DOC_CSN', 'DOC_HNO_ID', 'SCAN_FILE', 'DOC_DESCR', 'RECORD_STATE_C_NAME',
        ], 'key' => ['DOC_INFO_ID']],
        'V_EHI_HNO_LINKED_PATS' => ['columns' => ['NOTE_ID', 'LINE', 'LINKED_PAT_ID'], 'key' => ['NOTE_ID', 'LINE']],
    ];

    /**
     * Tables only a package built with withLabs() supplies, so standard() keeps its
     * exact members. Each dictionary page declares the key the real mapping expects.
     *
     * @var array<string, array{columns: list<string>, key: list<string>}>
     */
    private const array LAB_TABLES = [
        'ORDER_PROC' => ['columns' => [
            'ORDER_PROC_ID', 'PAT_ID', 'PAT_ENC_CSN_ID', 'DISPLAY_NAME', 'DESCRIPTION', 'ORDER_TYPE_C_NAME',
            'ORDER_STATUS_C_NAME', 'AUTHRZING_PROV_ID_PROV_NAME', 'RESULT_TIME',
        ], 'key' => ['ORDER_PROC_ID']],
        'ORDER_RESULTS' => ['columns' => [
            'ORDER_PROC_ID', 'ORD_DATE_REAL', 'LINE', 'PAT_ENC_CSN_ID', 'COMPONENT_ID', 'COMPONENT_ID_NAME',
            'COMPON_LNC_ID_LNC_LONG_NAME', 'ORD_VALUE', 'ORD_RAW_VALUE', 'ORD_NUM_VALUE', 'VALUE_NORMALIZED',
            'REFERENCE_LOW', 'REFERENCE_HIGH', 'REFERENCE_UNIT', 'REF_NORMAL_VALS', 'RESULT_FLAG_C_NAME',
            'RESULT_STATUS_C_NAME', 'LAB_STATUS_C_NAME', 'RESULT_DATE', 'COMP_OBS_INST_TM', 'COMP_ANL_INST_TM',
            'COMPONENT_COMMENT', 'RESULTING_LAB_ID_LLB_NAME',
        ], 'key' => ['ORDER_PROC_ID', 'ORD_DATE_REAL', 'LINE']],
        'ORDER_RES_COMMENT' => ['columns' => ['ORDER_ID', 'CONTACT_DATE_REAL', 'LINE', 'LINE_COMMENT', 'RESULTS_CMT'], 'key' => ['ORDER_ID', 'CONTACT_DATE_REAL', 'LINE', 'LINE_COMMENT']],
        'ORDER_NARRATIVE' => ['columns' => ['ORDER_PROC_ID', 'ORD_DATE_REAL', 'LINE', 'NARRATIVE'], 'key' => ['ORDER_PROC_ID', 'ORD_DATE_REAL', 'LINE']],
    ];

    /**
     * @param  array<string, list<array<string, string>>>  $rows  table => rows
     * @param  array<string, string>  $files  extra members: path => bytes
     */
    private function __construct(private array $rows, private array $files) {}

    public static function standard(): self
    {
        $key = self::PATIENT_KEY;
        $dose = static fn (string $id, string $name, string $date, array $extra = []): array => [
            'IMMUNE_ID' => $id,
            'IMMUNZATN_ID' => '9'.$id,
            'IMMUNZATN_ID_NAME' => $name,
            'IMMUNE_DATE' => $date,
            'IMMNZTN_STATUS_C_NAME' => 'Given',
            'IMM_HISTORIC_ADM_YN' => 'N',
            ...$extra,
        ];

        return new self([
            'PATIENT' => [['PAT_ID' => $key, 'PAT_NAME' => 'SYNTHETIC PERSON']],
            'IMMUNE' => [
                $dose('601', 'SYNTHETIC VACCINE A', '2024-03-05', [
                    'IMMUNIZATION_TIME' => '2024-03-05 09:15', 'DOSE' => '0.5 mL', 'IMMNZTN_DOSE_AMOUNT' => '0.50',
                    'IMMNZTN_DOSE_UNIT_C_NAME' => 'mL', 'LOT' => 'SYN-LOT-1', 'MFG_C_NAME' => 'SYNTHETIC MAKER',
                    'ROUTE_C_NAME' => 'Intramuscular', 'SITE_C_NAME' => 'Left deltoid',
                ]),
                $dose('602', 'SYNTHETIC VACCINE B', '2024-03-05', ['IMMNZTN_DOSE_AMOUNT' => '1.0', 'IMMNZTN_DOSE_UNIT_C_NAME' => 'mL', 'LOT' => 'SYN-LOT-2']),
                $dose('603', 'SYNTHETIC VACCINE C', '2019-06-01', ['IMM_HISTORIC_ADM_YN' => 'Y']),
                $dose('604', 'SYNTHETIC VACCINE A', '2023-01-10', ['IMMNZTN_STATUS_C_NAME' => 'Deleted']),
                $dose('605', 'SYNTHETIC VACCINE E', '2022-02-02'),
                $dose('606', 'SYNTHETIC VACCINE D', '2023-10-01', ['LOT' => 'SYN-LOT-6']),
            ],
            'PAT_IMMUNIZATIONS' => [
                ['PAT_ID' => $key, 'LINE' => '1', 'IMMUNE_ID' => '601'],
                ['PAT_ID' => $key, 'LINE' => '2', 'IMMUNE_ID' => '602'],
                ['PAT_ID' => $key, 'LINE' => '3', 'IMMUNE_ID' => '603'],
                ['PAT_ID' => $key, 'LINE' => '4', 'IMMUNE_ID' => '604'],
                ['PAT_ID' => $key, 'LINE' => '5', 'IMMUNE_ID' => '606'],
            ],
            'PAT_ENC' => [
                ['PAT_ENC_CSN_ID' => '7001', 'PAT_ID' => $key, 'CONTACT_DATE' => '2024-03-05'],
                ['PAT_ENC_CSN_ID' => '7002', 'PAT_ID' => $key, 'CONTACT_DATE' => '2024-04-01'],
            ],
            'HNO_INFO' => [
                ['NOTE_ID' => '88001', 'PAT_ENC_CSN_ID' => '7001', 'IP_NOTE_TYPE_C_NAME' => 'Progress Notes', 'NOTE_DESC' => 'Synthetic progress note', 'CREATE_INSTANT_DTTM' => '2024-03-05 14:30:00', 'UNSIGNED_YN' => 'N'],
                ['NOTE_ID' => '88002', 'PAT_ENC_CSN_ID' => '7002', 'IP_NOTE_TYPE_C_NAME' => 'Scanned Notes', 'NOTE_DESC' => 'Synthetic scanned note', 'CREATE_INSTANT_DTTM' => '2024-04-01 10:00:00', 'UNSIGNED_YN' => 'N'],
                ['NOTE_ID' => '88003', 'PAT_ENC_CSN_ID' => '7001', 'IP_NOTE_TYPE_C_NAME' => 'Progress Notes', 'NOTE_DESC' => 'Synthetic deleted note', 'CREATE_INSTANT_DTTM' => '2024-03-05 15:00:00', 'DELETE_INSTANT_DTTM' => '2024-03-06 08:00:00'],
            ],
            'NOTE_ENC_INFO' => [
                // The later contact is listed first; CONTACT_DATE_REAL orders them.
                ['CONTACT_SERIAL_NUM' => '9102', 'NOTE_ID' => '88001', 'CONTACT_DATE_REAL' => '66000.02', 'CONTACT_DATE' => '2024-03-05', 'NOTE_STATUS_C_NAME' => 'Addendum', 'AUTHOR_USER_ID_NAME' => 'SYNTHETIC AUTHOR'],
                ['CONTACT_SERIAL_NUM' => '9101', 'NOTE_ID' => '88001', 'CONTACT_DATE_REAL' => '66000.01', 'CONTACT_DATE' => '2024-03-05', 'NOTE_STATUS_C_NAME' => 'Signed', 'AUTHOR_USER_ID_NAME' => 'SYNTHETIC AUTHOR'],
                ['CONTACT_SERIAL_NUM' => '9201', 'NOTE_ID' => '88002', 'CONTACT_DATE_REAL' => '66027', 'CONTACT_DATE' => '2024-04-01', 'NOTE_STATUS_C_NAME' => 'Signed'],
                ['CONTACT_SERIAL_NUM' => '9301', 'NOTE_ID' => '88003', 'CONTACT_DATE_REAL' => '66000.03', 'CONTACT_DATE' => '2024-03-05', 'NOTE_STATUS_C_NAME' => 'Signed'],
            ],
            'HNO_PLAIN_TEXT' => [
                ['NOTE_CSN_ID' => '9101', 'LINE' => '1', 'NOTE_ID' => '88001', 'NOTE_TEXT' => 'Synthetic draft line 1'],
                // Physical order differs from LINE order, and LINE 10 sorts after 2.
                ['NOTE_CSN_ID' => '9102', 'LINE' => '10', 'NOTE_ID' => '88001', 'NOTE_TEXT' => 'Synthetic final line 10'],
                ['NOTE_CSN_ID' => '9102', 'LINE' => '2', 'NOTE_ID' => '88001', 'NOTE_TEXT' => 'Synthetic final line 2'],
                ['NOTE_CSN_ID' => '9102', 'LINE' => '1', 'NOTE_ID' => '88001', 'NOTE_TEXT' => 'Synthetic final line 1'],
                ['NOTE_CSN_ID' => '9301', 'LINE' => '1', 'NOTE_ID' => '88003', 'NOTE_TEXT' => 'Synthetic deleted line'],
            ],
            'DOC_INFORMATION' => [
                ['DOC_INFO_ID' => '5001', 'DOC_PT_ID' => $key, 'DOC_CSN' => '7002', 'DOC_HNO_ID' => '88002', 'SCAN_FILE' => basename(self::RTF_MEMBER), 'DOC_DESCR' => 'Synthetic scan'],
            ],
            'V_EHI_HNO_LINKED_PATS' => [
                ['NOTE_ID' => '88002', 'LINE' => '1', 'LINKED_PAT_ID' => $key],
            ],
        ], [
            self::RTF_MEMBER => self::RTF_BYTES,
            self::UNLINKED_RTF_MEMBER => '{\\rtf1\\ansi Synthetic unreferenced note.}',
            'README.txt' => "Synthetic export readme\n",
        ]);
    }

    /**
     * standard() plus lab tables:
     * - order 8101, encounter 7001: contact 66000.01 has line 1 (LDL "<50", with
     *   VALUE_NORMALIZED, comments stored out of order, a time) and line 2 (glucose
     *   95.0 with a comma raw value and a range), plus a narrative out of order;
     *   contact 66000.02 repeats line 1 the same day with a different value.
     * - order 8102: a text result; 8103: canceled; 8104: no results, only a
     *   narrative; 8105: Incomplete status; 8107: an imaging order's result.
     * - order 8106 is named by a result but not supplied: an orphan.
     */
    public static function withLabs(): self
    {
        $key = self::PATIENT_KEY;
        $order = static fn (string $id, array $extra = []): array => [
            'ORDER_PROC_ID' => $id,
            'PAT_ID' => $key,
            'PAT_ENC_CSN_ID' => '7001',
            'DISPLAY_NAME' => 'SYNTHETIC PANEL',
            'ORDER_TYPE_C_NAME' => 'Lab',
            'ORDER_STATUS_C_NAME' => 'Completed',
            'AUTHRZING_PROV_ID_PROV_NAME' => 'SYNTHETIC PROVIDER',
            ...$extra,
        ];
        $result = static fn (string $id, string $contact, string $line, array $extra = []): array => [
            'ORDER_PROC_ID' => $id,
            'ORD_DATE_REAL' => $contact,
            'LINE' => $line,
            'COMPONENT_ID' => '4'.$line,
            'COMPONENT_ID_NAME' => 'SYNTHETIC ANALYTE',
            'ORD_VALUE' => '1',
            'RESULT_STATUS_C_NAME' => 'Final',
            'RESULT_DATE' => '2024-03-05',
            ...$extra,
        ];
        $standard = self::standard();

        return new self([...$standard->rows,
            'ORDER_PROC' => [
                $order('8101'),
                $order('8102', ['DISPLAY_NAME' => 'SYNTHETIC CULTURE']),
                $order('8103', ['ORDER_STATUS_C_NAME' => 'Canceled']),
                $order('8104', ['ORDER_TYPE_C_NAME' => 'Imaging']),
                $order('8105'),
                $order('8107', ['ORDER_TYPE_C_NAME' => 'Imaging']),
            ],
            'ORDER_RESULTS' => [
                $result('8101', '66000.01', '1', [
                    'PAT_ENC_CSN_ID' => '7001',
                    'COMPONENT_ID_NAME' => 'SYNTHETIC LDL',
                    'ORD_VALUE' => '<50',
                    'ORD_NUM_VALUE' => '9999999',
                    'VALUE_NORMALIZED' => '<50',
                    'REFERENCE_UNIT' => 'mg/dL',
                    'REF_NORMAL_VALS' => 'Below 100',
                    'RESULT_FLAG_C_NAME' => 'Low',
                    'COMP_OBS_INST_TM' => '2024-03-05 09:15',
                    'COMP_ANL_INST_TM' => '2024-03-05 11:40:30',
                    'COMPONENT_COMMENT' => 'Synthetic short comment',
                    'RESULTING_LAB_ID_LLB_NAME' => 'SYNTHETIC LAB',
                ]),
                $result('8101', '66000.01', '2', [
                    'COMPONENT_ID_NAME' => 'SYNTHETIC GLUCOSE',
                    'COMPON_LNC_ID_LNC_LONG_NAME' => 'Synthetic glucose long name',
                    'ORD_VALUE' => '95.0',
                    'ORD_RAW_VALUE' => '95,0',
                    'ORD_NUM_VALUE' => '95',
                    'REFERENCE_LOW' => '70.00',
                    'REFERENCE_HIGH' => '99',
                    'REFERENCE_UNIT' => 'mg/dL',
                ]),
                $result('8101', '66000.02', '1', [
                    'COMPONENT_ID_NAME' => 'SYNTHETIC LDL',
                    'ORD_VALUE' => '52',
                    'REFERENCE_UNIT' => 'mg/dL',
                    'RESULT_DATE' => '2024-03-05',
                ]),
                $result('8102', '66001', '1', ['COMPONENT_ID_NAME' => 'SYNTHETIC CULTURE RESULT', 'ORD_VALUE' => 'No growth', 'RESULT_DATE' => '3/6/2024']),
                $result('8103', '66002', '1'),
                $result('8105', '66003', '1', ['RESULT_STATUS_C_NAME' => 'Incomplete']),
                $result('8106', '66004', '1'),
                $result('8107', '66005', '1'),
            ],
            'ORDER_RES_COMMENT' => [
                ['ORDER_ID' => '8101', 'CONTACT_DATE_REAL' => '66000.01', 'LINE' => '1', 'LINE_COMMENT' => '10', 'RESULTS_CMT' => 'Synthetic comment line 10'],
                ['ORDER_ID' => '8101', 'CONTACT_DATE_REAL' => '66000.01', 'LINE' => '1', 'LINE_COMMENT' => '2', 'RESULTS_CMT' => 'Synthetic comment line 2'],
                ['ORDER_ID' => '8101', 'CONTACT_DATE_REAL' => '66000.01', 'LINE' => '1', 'LINE_COMMENT' => '1', 'RESULTS_CMT' => 'Synthetic comment line 1'],
            ],
            'ORDER_NARRATIVE' => [
                ['ORDER_PROC_ID' => '8101', 'ORD_DATE_REAL' => '66000.01', 'LINE' => '2', 'NARRATIVE' => 'Synthetic narrative line 2'],
                ['ORDER_PROC_ID' => '8101', 'ORD_DATE_REAL' => '66000.01', 'LINE' => '1', 'NARRATIVE' => 'Synthetic narrative line 1'],
                ['ORDER_PROC_ID' => '8104', 'ORD_DATE_REAL' => '66006', 'LINE' => '1', 'NARRATIVE' => 'Synthetic imaging narrative'],
            ],
        ], $standard->files);
    }

    /**
     * Override cells of the one row of a table matching $match (column => value).
     *
     * @param  array<string, string>  $match
     * @param  array<string, string>  $cells
     */
    public function changing(string $table, array $match, array $cells): self
    {
        $rows = $this->rows;
        $found = 0;
        foreach ($rows[$table] as $index => $row) {
            if (array_intersect_assoc($match, $row) === $match) {
                $rows[$table][$index] = [...$row, ...$cells];
                $found++;
            }
        }
        if ($found !== 1) {
            throw new LogicException('EhiClinicalPackage::changing() must match exactly one row.');
        }

        return new self($rows, $this->files);
    }

    /** @param  array<string, string>  $row */
    public function adding(string $table, array $row): self
    {
        $rows = $this->rows;
        $rows[$table][] = $row;

        return new self($rows, $this->files);
    }

    /**
     * Drop the one row of a table matching $match.
     *
     * @param  array<string, string>  $match
     */
    public function without(string $table, array $match): self
    {
        $rows = $this->rows;
        $kept = array_values(array_filter($rows[$table], static fn (array $row): bool => array_intersect_assoc($match, $row) !== $match));
        if (count($kept) !== count($rows[$table]) - 1) {
            throw new LogicException('EhiClinicalPackage::without() must match exactly one row.');
        }
        $rows[$table] = $kept;

        return new self($rows, $this->files);
    }

    public function build(): EhiSyntheticPackage
    {
        $files = $this->files;
        $supplied = array_intersect_key(self::LAB_TABLES, $this->rows);
        foreach ([...self::TABLES, ...$supplied] as $table => $shape) {
            $lines = [implode("\t", $shape['columns'])];
            foreach ($this->rows[$table] ?? [] as $row) {
                $unknown = array_diff(array_keys($row), $shape['columns']);
                if ($unknown !== []) {
                    throw new LogicException('EhiClinicalPackage row names a column its table does not have.');
                }
                $lines[] = implode("\t", array_map(static fn (string $column): string => $row[$column] ?? '', $shape['columns']));
            }
            $files["{$table}.tsv"] = implode("\n", $lines)."\n";
            $files["docs/{$table}.htm"] = self::dictionaryPage($table, $shape['columns'], $shape['key']);
        }

        return EhiSyntheticPackage::fromFiles($files);
    }

    /**
     * The dictionary pages say what the package's own key is. Replacing one is how a
     * test proves a key the mapping does not expect is refused.
     *
     * @param  list<string>  $key
     */
    public function buildWithDocumentedKey(string $table, array $key): EhiSyntheticPackage
    {
        $shape = self::LAB_TABLES[$table] ?? self::TABLES[$table] ?? throw new LogicException('Unknown synthetic table.');

        return $this->build()->with("docs/{$table}.htm", self::dictionaryPage($table, $shape['columns'], $key));
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $key
     */
    private static function dictionaryPage(string $table, array $columns, array $key): string
    {
        $columnRows = implode('', array_map(static fn (string $column): string => "<tr><td>{$column}</td><td>VARCHAR</td><td>Synthetic</td></tr>", $columns));
        $keyRows = implode('', array_map(static fn (string $column): string => "<tr><td>{$column}</td></tr>", $key));

        return <<<HTML
<html><head><title>{$table}</title><meta charset="utf-8"></head><body>
<h1>{$table}</h1>
<h2>Column Information</h2>
<table class="SubList"><tr><th>Column Name</th><th>Type</th><th>Description</th></tr>{$columnRows}</table>
<h2>Primary Key</h2>
<table class="SubList"><tr><th>Column Name</th></tr>{$keyRows}</table>
</body></html>
HTML;
    }
}
