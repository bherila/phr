<?php

namespace Tests\Support;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiSource;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;

/**
 * Builds bound synthetic packages that carry the SYNTH_ITEM table EhiFakeMapping
 * projects, and registers that mapping alone. Every value is invented; names
 * carry EhiSyntheticPackage::MARKER so output tests can prove they never leak.
 */
trait EhiProjectionFixtures
{
    use EhiIntakeFixtures;

    protected function setUpEhiProjection(): void
    {
        $this->setUpEhi();
        EhiFakeMapping::reset();
        config(['phr.ehi_mappings' => [EhiFakeMapping::class]]);
    }

    protected function tearDownEhiProjection(): void
    {
        EhiFakeMapping::reset();
        $this->tearDownEhi();
    }

    /**
     * The standard package plus SYNTH_ITEM rows and its dictionary page.
     *
     * @param  list<list<string>>  $rows  ITEM_ID, LINE, NAME, GIVEN_ON, STATUS
     * @param  list<string>|null  $primaryKey  as the dictionary documents it; null leaves the table undocumented
     */
    protected function itemPackage(array $rows, ?array $primaryKey = ['ITEM_ID', 'LINE']): EhiSyntheticPackage
    {
        $columns = ['ITEM_ID', 'LINE', 'NAME', 'GIVEN_ON', 'STATUS'];
        $tsv = implode("\t", $columns)."\n".implode('', array_map(static fn (array $row): string => implode("\t", $row)."\n", $rows));
        $package = EhiSyntheticPackage::standard()->with(EhiFakeMapping::TABLE.'.tsv', $tsv);
        if ($primaryKey === null) {
            return $package;
        }

        $columnRows = implode('', array_map(static fn (string $column): string => "<tr><td>{$column}</td><td>VARCHAR</td><td>Synthetic</td></tr>", $columns));
        $keyRows = implode('', array_map(static fn (string $column): string => "<tr><td>{$column}</td></tr>", $primaryKey));
        $table = EhiFakeMapping::TABLE;

        return $package->with("docs/{$table}.htm", <<<HTML
<html><head><title>{$table}</title></head><body>
<h1>{$table}</h1>
<h2>Column Information</h2>
<table class="SubList"><tr><th>Column Name</th><th>Type</th><th>Description</th></tr>{$columnRows}</table>
<h2>Primary Key</h2>
<table class="SubList"><tr><th>Column Name</th></tr>{$keyRows}</table>
</body></html>
HTML);
    }

    /** Intake and bind (to a new or the existing source) one synthetic package. */
    protected function boundPackage(EhiSyntheticPackage $synthetic, PhrPatient $patient, User $owner, string $name = 'package'): PhrEhiPackage
    {
        $package = $this->ehiIntakeDirectory($synthetic, $patient, (int) $owner->id, $name)->package;
        $source = PhrEhiSource::query()->where('patient_id', $patient->id)->first();

        return app(EhiBindingService::class)->bind($package, $patient, (int) $owner->id, $source?->id, $source === null ? 'Synthetic Clinic' : null);
    }

    /** @return list<list<string>> */
    protected function standardItems(): array
    {
        $marker = EhiSyntheticPackage::MARKER;

        return [
            ['100', '1', "Synthetic vaccine A {$marker}", '2020-01-01', 'A'],
            ['100', '2', "Synthetic vaccine B {$marker}", '2020-02-01', 'A'],
            ['0100', '1', "Synthetic vaccine C {$marker}", '', 'A'],
            ['300', '1', "Synthetic vaccine D {$marker}", '2020-03-01', 'D'],
        ];
    }
}
