<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiSource;
use App\Models\PhrPatientUserAccess;
use App\Services\PHR\Import\Ehi\EhiBindingRefused;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

class EhiBindingServiceTest extends TestCase
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

    public function test_binds_to_a_new_source_and_then_to_the_existing_one(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $first = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id, 'first')->package;
        $binding = app(EhiBindingService::class);
        self::assertCount(0, $binding->suggestions($first));

        $bound = $binding->bind($first, $patient, $owner->id, null, 'Synthetic Clinic');

        self::assertSame(PhrEhiPackage::BINDING_BOUND, $bound->binding_status);
        self::assertSame($owner->id, $bound->bound_by_user_id);
        self::assertNotNull($bound->bound_at);
        $source = PhrEhiSource::query()->sole();
        self::assertSame($source->id, $bound->source_id);
        self::assertSame('Synthetic Clinic', $source->label);
        self::assertSame(EhiSyntheticPackage::PATIENT_KEY, $source->source_patient_key);
        self::assertSame($first->dictionary_fingerprint, $source->dictionary_fingerprint);
        self::assertSame($owner->id, $source->created_by_user_id);

        // Idempotent for the same choice.
        self::assertSame($source->id, $binding->bind($first, $patient, $owner->id, null, 'Synthetic Clinic')->source_id);
        self::assertSame($source->id, $binding->bind($first, $patient, $owner->id, $source->id, null)->source_id);
        self::assertSame(1, PhrEhiSource::query()->count());

        // A later package is suggested, not bound, until chosen explicitly.
        $second = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard()->withChangedResults(), $patient, $owner->id, 'second')->package;
        $other = PhrEhiSource::query()->create(['patient_id' => $patient->id, 'label' => 'Other dictionary', 'source_patient_key' => EhiSyntheticPackage::PATIENT_KEY, 'dictionary_fingerprint' => str_repeat('0', 64)]);
        self::assertSame([$source->id, $other->id], $binding->suggestions($second)->pluck('id')->all(), 'a dictionary match ranks first');
        self::assertSame(PhrEhiPackage::BINDING_UNBOUND, $second->fresh()?->binding_status);

        $secondBound = $binding->bind($second, $patient, $owner->id, $source->id, null);
        self::assertSame($source->id, $secondBound->source_id);
        self::assertSame(2, PhrEhiSource::query()->count());
    }

    public function test_refuses_a_source_with_a_different_patient_key_or_of_another_patient(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $package = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id)->package;
        $binding = app(EhiBindingService::class);

        $mismatch = PhrEhiSource::query()->create(['patient_id' => $patient->id, 'label' => 'Mismatch', 'source_patient_key' => EhiSyntheticPackage::OTHER_PATIENT_KEY]);
        $this->assertRefused(EhiBindingRefused::SOURCE_KEY_MISMATCH, fn () => $binding->bind($package, $patient, $owner->id, $mismatch->id, null));
        self::assertCount(0, $binding->suggestions($package));

        $foreign = PhrEhiSource::query()->create(['patient_id' => $this->ehiPatient($owner)->id, 'label' => 'Foreign', 'source_patient_key' => EhiSyntheticPackage::PATIENT_KEY]);
        $this->assertRefused(EhiBindingRefused::SOURCE_NOT_FOUND, fn () => $binding->bind($package, $patient, $owner->id, $foreign->id, null));
        $this->assertRefused(EhiBindingRefused::LABEL_TAKEN, fn () => $binding->bind($package, $patient, $owner->id, null, 'Mismatch'));
        $this->assertRefused(EhiBindingRefused::CHOICE_REQUIRED, fn () => $binding->bind($package, $patient, $owner->id, null, null));
        $this->assertRefused(EhiBindingRefused::LABEL_INVALID, fn () => $binding->bind($package, $patient, $owner->id, null, "bad\nlabel"));

        self::assertSame(PhrEhiPackage::BINDING_UNBOUND, $package->fresh()?->binding_status);
    }

    public function test_refuses_rebinding_to_a_different_source(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $package = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id)->package;
        $binding = app(EhiBindingService::class);
        $bound = $binding->bind($package, $patient, $owner->id, null, 'First');
        $second = PhrEhiSource::query()->create(['patient_id' => $patient->id, 'label' => 'Second', 'source_patient_key' => EhiSyntheticPackage::PATIENT_KEY]);

        $this->assertRefused(EhiBindingRefused::ALREADY_BOUND, fn () => $binding->bind($package, $patient, $owner->id, $second->id, null));
        $this->assertRefused(EhiBindingRefused::ALREADY_BOUND, fn () => $binding->bind($package, $patient, $owner->id, null, 'Third'));
        self::assertSame($bound->source_id, $package->fresh()?->source_id);
    }

    public function test_refuses_unready_packages_and_non_owners(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $manager->id, 'access_level' => PhrPatientUserAccess::LEVEL_MANAGER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $package = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $manager->id)->package;
        $binding = app(EhiBindingService::class);

        try {
            $binding->bind($package, $patient, $manager->id, null, 'Managed');
            self::fail('Only the owner may bind.');
        } catch (\Throwable $denied) {
            self::assertInstanceOf(AuthorizationException::class, $denied);
        }
        self::assertSame(0, PhrEhiSource::query()->count());

        $package->forceFill(['accounting_status' => PhrEhiPackage::ACCOUNTING_FAILED])->save();
        $this->assertRefused(EhiBindingRefused::NOT_READY, fn () => $binding->bind($package, $patient, $owner->id, null, 'Owner'));
        self::assertSame(0, PhrEhiSource::query()->count());
    }

    private function assertRefused(string $reason, callable $bind): void
    {
        try {
            $bind();
            self::fail("Expected binding to be refused ({$reason}).");
        } catch (EhiBindingRefused $refused) {
            self::assertSame($reason, $refused->reason);
            self::assertStringNotContainsString(EhiSyntheticPackage::PATIENT_KEY, $refused->getMessage());
        }
    }
}
