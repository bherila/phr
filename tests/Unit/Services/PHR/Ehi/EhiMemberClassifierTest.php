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
            ['Tables/Synthetic Mixed.tsv', null, EhiMemberKind::TABLE, 'Synthetic Mixed'],
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
}
