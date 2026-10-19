<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiBlob;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiSource;
use App\Models\PhrPatientUserAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

class PhrEhiCommandsTest extends TestCase
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

    public function test_intake_status_bind_and_verify_print_only_safe_output(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $synthetic = EhiSyntheticPackage::standard();
        $dir = $this->ehiDirectory($synthetic);
        $zip = $synthetic->writeZip($this->ehiRoot.'/package-'.EhiSyntheticPackage::MARKER.'.zip');
        $outputs = [];

        [$exit, $outputs[]] = $this->runCommand('phr:ehi:intake', ['--patient' => $patient->id, '--actor' => $owner->id, '--dir' => $dir]);
        self::assertSame(0, $exit, end($outputs));
        $package = PhrEhiPackage::query()->sole();
        $intake = end($outputs);
        foreach ([
            "package: {$package->id}",
            'replayed: no',
            'resumed: no',
            'preservation: preserved',
            'accounting: accounted',
            'clinical: not_started',
            'binding: unbound',
            'source patient key: *****4567',
            'members: 23 (table 9, dictionary 9, index 1, readme 1, note 1, media 1, unknown 1)',
            'tables: 10 (parsed 7, header_only 1, empty 1, malformed 0, missing 1)',
            'rows: source 13, malformed 1, retained_unmapped 11, out_of_scope 1',
            'findings: malformed_row 1, unknown_column 1, unknown_member 1, unknown_table 1',
        ] as $expected) {
            self::assertStringContainsString($expected, $intake);
        }

        [$exit, $outputs[]] = $this->runCommand('phr:ehi:intake', ['--patient' => $patient->id, '--actor' => $owner->id, '--zip' => $zip]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('replayed: yes', end($outputs));
        self::assertSame(1, PhrEhiPackage::query()->count());

        [$exit, $outputs[]] = $this->runCommand('phr:ehi:status', ['--patient' => $patient->id, '--actor' => $owner->id]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('intakes: 2', end($outputs));

        [$exit, $outputs[]] = $this->runCommand('phr:ehi:bind', ['--patient' => $patient->id, '--actor' => $owner->id, '--package' => $package->id, '--new-source-label' => 'Synthetic Clinic', '--yes' => true]);
        self::assertSame(0, $exit, end($outputs));
        $source = PhrEhiSource::query()->sole();
        self::assertStringContainsString("binding: bound (source {$source->id})", end($outputs));

        PhrEhiBlob::query()->update(['verified_at' => now()->subDay()]);
        [$exit, $outputs[]] = $this->runCommand('phr:ehi:verify', ['--patient' => $patient->id, '--actor' => $owner->id, '--package' => $package->id]);
        self::assertSame(0, $exit);
        self::assertStringContainsString("package {$package->id}: blobs 22, ok 22, missing 0, tampered 0", end($outputs));
        self::assertSame(0, PhrEhiBlob::query()->where('verified_at', '<', now()->subHour())->count());

        foreach ($outputs as $output) {
            $this->assertSafe($output, $dir);
        }
    }

    public function test_bind_requires_confirmation_and_shows_suggestions(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $package = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id)->package;
        $source = PhrEhiSource::query()->create(['patient_id' => $patient->id, 'label' => 'Synthetic Clinic', 'source_patient_key' => EhiSyntheticPackage::PATIENT_KEY, 'dictionary_fingerprint' => $package->dictionary_fingerprint]);
        $args = ['--patient' => $patient->id, '--actor' => $owner->id, '--package' => $package->id, '--source' => $source->id];

        $this->artisan('phr:ehi:bind', $args)
            ->expectsOutputToContain("source {$source->id}: Synthetic Clinic (dictionary matches)")
            ->expectsConfirmation("Bind package {$package->id} to existing source {$source->id}?", 'no')
            ->expectsOutput('Not bound.')
            ->assertExitCode(1);
        self::assertSame(PhrEhiPackage::BINDING_UNBOUND, $package->refresh()->binding_status);

        $this->artisan('phr:ehi:bind', $args)
            ->expectsConfirmation("Bind package {$package->id} to existing source {$source->id}?", 'yes')
            ->assertExitCode(0);
        self::assertSame($source->id, $package->refresh()->source_id);

        $mismatch = PhrEhiSource::query()->create(['patient_id' => $patient->id, 'label' => 'Other', 'source_patient_key' => EhiSyntheticPackage::OTHER_PATIENT_KEY]);
        [$exit, $output] = $this->runCommand('phr:ehi:bind', [...$args, '--source' => $mismatch->id, '--yes' => true]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('refused: already_bound', $output);
    }

    public function test_bind_requires_the_owner(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $manager->id, 'access_level' => PhrPatientUserAccess::LEVEL_MANAGER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $package = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $manager->id)->package;

        $this->expectException(AuthorizationException::class);
        Artisan::call('phr:ehi:bind', ['--patient' => $patient->id, '--actor' => $manager->id, '--package' => $package->id, '--new-source-label' => 'Managed', '--yes' => true]);
    }

    public function test_intake_refusals_print_a_reason_code_only(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $dir = $this->ehiDirectory(EhiSyntheticPackage::standard()->withSecondPatient());

        [$exit, $output] = $this->runCommand('phr:ehi:intake', ['--patient' => $patient->id, '--actor' => $owner->id, '--dir' => $dir]);
        self::assertSame(1, $exit);
        self::assertSame('refused: multiple_patients', trim($output));
        $this->assertSafe($output, $dir);
        $this->assertNoEhiRows();

        [$exit] = $this->runCommand('phr:ehi:intake', ['--patient' => $patient->id, '--actor' => $owner->id]);
        self::assertSame(2, $exit);
        [$exit] = $this->runCommand('phr:ehi:intake', ['--patient' => $patient->id, '--actor' => $owner->id, '--dir' => $dir, '--zip' => $dir]);
        self::assertSame(2, $exit);
    }

    public function test_intake_requires_write_access(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $viewer->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $dir = $this->ehiDirectory(EhiSyntheticPackage::standard());

        try {
            Artisan::call('phr:ehi:intake', ['--patient' => $patient->id, '--actor' => $viewer->id, '--dir' => $dir]);
            self::fail('A viewer must not intake.');
        } catch (\Throwable $denied) {
            self::assertInstanceOf(AuthorizationException::class, $denied);
        }
        $this->assertNoEhiRows();

        // Read access is enough for status.
        [$exit, $output] = $this->runCommand('phr:ehi:status', ['--patient' => $patient->id, '--actor' => $viewer->id]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('No EHI packages.', $output);
    }

    public function test_verify_reports_missing_and_tampered_blobs(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $result = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id);
        $disk = Storage::disk((string) config('phr.ehi_disk'));
        $blobs = PhrEhiBlob::query()->where('byte_size', '>', 5)->orderBy('id')->get();
        $disk->delete($blobs[0]->storage_path);
        $disk->put($blobs[1]->storage_path, $this->flipFirstByte((string) $disk->get($blobs[1]->storage_path)));
        $disk->put($blobs[2]->storage_path, 'short');
        PhrEhiBlob::query()->update(['verified_at' => null]);

        [$exit, $output] = $this->runCommand('phr:ehi:verify', ['--patient' => $patient->id, '--actor' => $owner->id]);

        self::assertSame(1, $exit);
        self::assertStringContainsString("package {$result->package->id}: blobs 22, ok 19, missing 1, tampered 2", $output);
        self::assertStringContainsString('Verification failed: 3 blob(s) missing or tampered.', $output);
        self::assertSame(19, PhrEhiBlob::query()->whereNotNull('verified_at')->count(), 'only intact blobs are marked verified');
        $this->assertSafe($output, $this->ehiRoot);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: int, 1: string}
     */
    private function runCommand(string $command, array $parameters): array
    {
        $exit = Artisan::call($command, $parameters);

        return [$exit, Artisan::output()];
    }

    private function assertSafe(string $output, string $root): void
    {
        self::assertStringNotContainsString(EhiSyntheticPackage::MARKER, $output);
        self::assertStringNotContainsString(EhiSyntheticPackage::PATIENT_KEY, $output);
        self::assertStringNotContainsString($root, $output);
        foreach (['ORDER_RESULTS', 'PAT_ENC', 'SYNTH_EXTRA_COL', 'PAT_ADDRESS', '.tsv', '.htm', '.rtf', '.png'] as $name) {
            self::assertStringNotContainsString($name, $output);
        }
    }

    private function flipFirstByte(string $bytes): string
    {
        $bytes[0] = $bytes[0] === 'X' ? 'Y' : 'X';

        return $bytes;
    }
}
