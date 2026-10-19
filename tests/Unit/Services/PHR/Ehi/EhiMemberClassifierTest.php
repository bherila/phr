<?php

namespace Tests\Unit\Services\PHR\Ehi;

use App\Services\PHR\Import\Ehi\EhiMemberClassifier;
use App\Services\PHR\Import\Ehi\EhiMemberKind;
use PHPUnit\Framework\TestCase;

class EhiMemberClassifierTest extends TestCase
{
    public function test_provisional_rules(): void
    {
        $classifier = new EhiMemberClassifier;
        $cases = [
            ['Tables/PAT_ENC.tsv', "PAT_ID\tPAT_ENC_CSN_ID", EhiMemberKind::TABLE, 'PAT_ENC'],
            ['Tables/order_results.TSV', null, EhiMemberKind::TABLE, 'order_results'],
            ['Tables/Synthetic_Mixed9.tsv', null, EhiMemberKind::TABLE, 'Synthetic_Mixed9'],
            ['PATIENT.txt', "PAT_ID\tPAT_MRN_ID", EhiMemberKind::TABLE, 'PATIENT'],
            ['notes.txt', 'plain prose without tabs', EhiMemberKind::UNKNOWN, null],
            ['notes.txt', null, EhiMemberKind::UNKNOWN, null],
            ['README.txt', "has\ttabs", EhiMemberKind::README, null],
            ['docs/ReadMe.md', null, EhiMemberKind::README, null],
            ['read_me', null, EhiMemberKind::README, null],
            ['Documentation/PAT_ENC.htm', '<html>', EhiMemberKind::DICTIONARY, null],
            ['Documentation/data dictionary.html', null, EhiMemberKind::DICTIONARY, null],
            ['Documentation/page.html', '<html><body><th>Column Name</th>', EhiMemberKind::DICTIONARY, null],
            ['index.html', '<html><body><th>Column Name</th>', EhiMemberKind::INDEX, null],
            ['Documentation/start.htm', '<html>', EhiMemberKind::INDEX, null],
            ['Rich Text/NOTE_1.rtf', '{\\rtf1', EhiMemberKind::NOTE, null],
            ['Media/scan.PDF', null, EhiMemberKind::MEDIA, null],
            ['Media/photo.jpeg', null, EhiMemberKind::MEDIA, null],
            ['Media/clip.mp4', null, EhiMemberKind::MEDIA, null],
            ['Media/image.dcm', null, EhiMemberKind::MEDIA, null],
            ['data.json', null, EhiMemberKind::UNKNOWN, null],
            ['.hidden', null, EhiMemberKind::UNKNOWN, null],
            ['noextension', null, EhiMemberKind::UNKNOWN, null],
        ];
        foreach ($cases as [$path, $firstLine, $kind, $table]) {
            self::assertSame(['kind' => $kind, 'table' => $table], $classifier->classify($path, $firstLine), $path);
        }
    }

    public function test_table_names_must_be_ascii_identifiers(): void
    {
        $classifier = new EhiMemberClassifier;
        $unsupported = ['kind' => EhiMemberKind::UNKNOWN, 'table' => null, 'unsupported_table_name' => true];
        foreach ([
            "Tables/Caf\u{e9}.tsv",
            'Tables/Synthetic Mixed.tsv',
            'Tables/SYNTH-TABLE.tsv',
            'Tables/1PATIENT.tsv',
            'Tables/_PATIENT.tsv',
            'Tables/PAT$ENC.tsv',
            'Tables/'.str_repeat('A', 129).'.tsv',
            "Tables/PATIENT\n.tsv",
        ] as $path) {
            self::assertSame($unsupported, $classifier->classify($path, null), $path);
        }
        self::assertSame($unsupported, $classifier->classify("Tables/Caf\u{e9}.txt", "PAT_ID\tX"));

        $longest = str_repeat('A', 128);
        self::assertSame(['kind' => EhiMemberKind::TABLE, 'table' => $longest], $classifier->classify("Tables/{$longest}.tsv", null));
        self::assertSame(['kind' => EhiMemberKind::TABLE, 'table' => 'CAFE'], $classifier->classify('Tables/CAFE.tsv', null));
    }
}
