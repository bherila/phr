<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiTableAccount;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

class EhiDictionaryNameSafetyTest extends TestCase
{
    use EhiIntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    public function test_unicode_heading_alias_does_not_create_a_colliding_missing_table_account(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $grid = '<table><tr><th>Column Name</th><th>PK</th></tr><tr><td>PAT_ID</td><td>Y</td></tr></table>';
        $synthetic = EhiSyntheticPackage::standard()
            ->with('docs/key-schema.html', '<h1>Table: KEY_TABLE</h1>'.$grid)
            ->with('docs/unicode-schema.html', "<h1>Table: \u{212A}EY_TABLE</h1>".$grid);

        $result = $this->ehiIntakeDirectory($synthetic, $patient, $owner->id);
        self::assertSame(PhrEhiPackage::ACCOUNTING_ACCOUNTED, $result->package->accounting_status);
        $accounts = PhrEhiTableAccount::query()->where('package_id', $result->package->id);
        self::assertSame(1, (clone $accounts)->where('table_name', 'KEY_TABLE')->where('state', PhrEhiTableAccount::STATE_MISSING)->count());
        self::assertNotContains("\u{212A}EY_TABLE", $accounts->pluck('table_name')->all());
        self::assertSame(11, $accounts->count(), 'only the valid ASCII missing table is added');

        $replay = $this->ehiIntakeDirectory($synthetic, $patient, $owner->id, 'replay');
        self::assertTrue($replay->replayed);
        self::assertSame(11, $accounts->count());
    }
}
