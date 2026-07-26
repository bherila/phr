<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalize the pre-PHR-domain lab and vital tables into the patient/access model.
 *
 * Project-specific assumption: any legacy `phr_lab_results` / `phr_patient_vitals`
 * rows on this deployment are attached to `users.id = 1` (the original site owner)
 * because the legacy `user_id` column stored opaque auth strings that don't map
 * back to Laravel's integer primary key. The migration creates a single
 * "Legacy PHR Patient" profile owned by user 1 and attaches every legacy row to
 * it. This is safe here because the only legacy rows in existence belong to that
 * user; do NOT copy this migration into another project without auditing the
 * data first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createPatientTables();

        $legacyPatientId = $this->legacyPatientId();

        Schema::disableForeignKeyConstraints();

        try {
            $this->rebuildLabResults($legacyPatientId);
            $this->rebuildPatientVitals($legacyPatientId);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            $this->rebuildLegacyLabResults();
            $this->rebuildLegacyPatientVitals();
            Schema::dropIfExists('phr_patient_user_access');
            Schema::dropIfExists('phr_patients');
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function createPatientTables(): void
    {
        if (! Schema::hasTable('phr_patients')) {
            Schema::create('phr_patients', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('display_name')->nullable();
                $table->string('relationship', 50)->nullable();
                $table->date('birth_date')->nullable();
                $table->string('sex_at_birth', 50)->nullable();
                $table->text('notes')->nullable();
                $table->dateTime('archived_at')->nullable();
                $table->timestamps();

                $table->index(['owner_user_id', 'display_name'], 'phr_patients_owner_name_idx');
                $table->index('archived_at', 'phr_patients_archived_idx');
            });
        }

        if (! Schema::hasTable('phr_patient_user_access')) {
            Schema::create('phr_patient_user_access', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('access_level', 32)->default('viewer');
                $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('granted_at')->nullable();
                $table->timestamps();

                $table->unique(['patient_id', 'user_id'], 'phr_patient_access_patient_user_unique');
                $table->index(['user_id', 'access_level'], 'phr_patient_access_user_level_idx');
            });
        }
    }

    private function legacyPatientId(): ?int
    {
        if (! DB::table('phr_lab_results')->exists() && ! DB::table('phr_patient_vitals')->exists()) {
            return null;
        }

        if (! DB::table('users')->where('id', 1)->exists()) {
            throw new RuntimeException('Cannot migrate legacy PHR rows because users.id=1 does not exist.');
        }

        $now = now();
        $patientId = DB::table('phr_patients')
            ->where('owner_user_id', 1)
            ->where('display_name', 'Legacy PHR Patient')
            ->value('id');

        if ($patientId === null) {
            $patientId = DB::table('phr_patients')->insertGetId([
                'owner_user_id' => 1,
                'display_name' => 'Legacy PHR Patient',
                'relationship' => 'self',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('phr_patient_user_access')->updateOrInsert(
            [
                'patient_id' => $patientId,
                'user_id' => 1,
            ],
            [
                'access_level' => 'owner',
                'granted_by_user_id' => 1,
                'granted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        return (int) $patientId;
    }

    private function rebuildLabResults(?int $legacyPatientId): void
    {
        Schema::dropIfExists('phr_lab_results_new');

        Schema::create('phr_lab_results_new', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('test_name')->nullable();
            $table->dateTime('collection_datetime')->nullable();
            $table->dateTime('result_datetime')->nullable();
            $table->string('result_status', 50)->nullable();
            $table->string('ordering_provider', 100)->nullable();
            $table->string('resulting_lab', 100)->nullable();
            $table->string('analyte', 100)->nullable();
            $table->string('value')->nullable();
            $table->decimal('value_numeric', 18, 10)->nullable();
            $table->string('unit', 50)->nullable();
            $table->decimal('range_min', 18, 10)->nullable();
            $table->decimal('range_max', 18, 10)->nullable();
            $table->string('range_unit', 50)->nullable();
            $table->string('reference_range_text')->nullable();
            $table->string('normal_value', 100)->nullable();
            $table->string('abnormal_flag', 50)->nullable();
            $table->mediumText('message_from_provider')->nullable();
            $table->mediumText('result_comment')->nullable();
            $table->string('lab_director', 100)->nullable();
            $table->string('source', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'result_datetime'], 'phr_labs_patient_result_dt_idx');
            $table->index(['user_id', 'result_datetime'], 'phr_labs_user_result_dt_idx');
            $table->index(['patient_id', 'analyte'], 'phr_labs_patient_analyte_idx');
        });

        $now = now();

        DB::table('phr_lab_results')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($legacyPatientId, $now): void {
                foreach ($rows as $row) {
                    if ($legacyPatientId === null) {
                        throw new RuntimeException('Cannot migrate legacy PHR lab row without a legacy patient.');
                    }

                    DB::table('phr_lab_results_new')->insert([
                        'id' => $this->rowValue($row, 'id'),
                        'patient_id' => $legacyPatientId,
                        'user_id' => 1,
                        'test_name' => $this->rowValue($row, 'test_name'),
                        'collection_datetime' => $this->rowValue($row, 'collection_datetime'),
                        'result_datetime' => $this->rowValue($row, 'result_datetime'),
                        'result_status' => $this->rowValue($row, 'result_status'),
                        'ordering_provider' => $this->rowValue($row, 'ordering_provider'),
                        'resulting_lab' => $this->rowValue($row, 'resulting_lab'),
                        'analyte' => $this->rowValue($row, 'analyte'),
                        'value' => $this->rowValue($row, 'value'),
                        'value_numeric' => $this->decimalOrNull($this->rowValue($row, 'value')),
                        'unit' => $this->rowValue($row, 'unit'),
                        'range_min' => $this->decimalOrNull($this->rowValue($row, 'range_min')),
                        'range_max' => $this->decimalOrNull($this->rowValue($row, 'range_max')),
                        'range_unit' => $this->rowValue($row, 'range_unit'),
                        'normal_value' => $this->rowValue($row, 'normal_value'),
                        'message_from_provider' => $this->rowValue($row, 'message_from_provider'),
                        'result_comment' => $this->rowValue($row, 'result_comment'),
                        'lab_director' => $this->rowValue($row, 'lab_director'),
                        'created_at' => $this->rowValue($row, 'created_at') ?? $now,
                        'updated_at' => $this->rowValue($row, 'updated_at') ?? $now,
                    ]);
                }
            });

        Schema::drop('phr_lab_results');
        Schema::rename('phr_lab_results_new', 'phr_lab_results');
    }

    private function rebuildPatientVitals(?int $legacyPatientId): void
    {
        Schema::dropIfExists('phr_patient_vitals_new');

        Schema::create('phr_patient_vitals_new', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('vital_name')->nullable();
            $table->date('vital_date')->nullable();
            $table->dateTime('observed_at')->nullable();
            $table->string('vital_value')->nullable();
            $table->decimal('value_numeric', 18, 10)->nullable();
            $table->decimal('value_numeric_secondary', 18, 10)->nullable();
            $table->string('unit', 50)->nullable();
            $table->string('secondary_unit', 50)->nullable();
            $table->string('body_site', 100)->nullable();
            $table->string('source', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'vital_date'], 'phr_vitals_patient_date_idx');
            $table->index(['user_id', 'vital_date'], 'phr_vitals_user_date_idx');
            $table->index(['patient_id', 'vital_name'], 'phr_vitals_patient_name_idx');
        });

        $now = now();

        DB::table('phr_patient_vitals')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($legacyPatientId, $now): void {
                foreach ($rows as $row) {
                    if ($legacyPatientId === null) {
                        throw new RuntimeException('Cannot migrate legacy PHR vital row without a legacy patient.');
                    }

                    [$primaryValue, $secondaryValue] = $this->numericPair($this->rowValue($row, 'vital_value'));

                    DB::table('phr_patient_vitals_new')->insert([
                        'id' => $this->rowValue($row, 'id'),
                        'patient_id' => $legacyPatientId,
                        'user_id' => 1,
                        'vital_name' => $this->rowValue($row, 'vital_name'),
                        'vital_date' => $this->rowValue($row, 'vital_date'),
                        'observed_at' => $this->rowValue($row, 'observed_at'),
                        'vital_value' => $this->rowValue($row, 'vital_value'),
                        'value_numeric' => $primaryValue,
                        'value_numeric_secondary' => $secondaryValue,
                        'created_at' => $this->rowValue($row, 'created_at') ?? $now,
                        'updated_at' => $this->rowValue($row, 'updated_at') ?? $now,
                    ]);
                }
            });

        Schema::drop('phr_patient_vitals');
        Schema::rename('phr_patient_vitals_new', 'phr_patient_vitals');
    }

    private function rebuildLegacyLabResults(): void
    {
        if (! Schema::hasTable('phr_lab_results')) {
            return;
        }

        Schema::dropIfExists('phr_lab_results_legacy');

        Schema::create('phr_lab_results_legacy', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id')->nullable();
            $table->string('test_name')->nullable();
            $table->timestamp('collection_datetime')->nullable();
            $table->timestamp('result_datetime')->nullable();
            $table->string('result_status', 50)->nullable();
            $table->string('ordering_provider', 100)->nullable();
            $table->string('resulting_lab', 100)->nullable();
            $table->string('analyte', 100)->nullable();
            $table->string('value', 20)->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('range_min', 10, 2)->nullable();
            $table->decimal('range_max', 10, 2)->nullable();
            $table->string('range_unit', 20)->nullable();
            $table->string('normal_value', 50)->nullable();
            $table->mediumText('message_from_provider')->nullable();
            $table->mediumText('result_comment')->nullable();
            $table->string('lab_director', 100)->nullable();
        });

        DB::table('phr_lab_results')
            ->orderBy('id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('phr_lab_results_legacy')->insert([
                        'id' => $this->rowValue($row, 'id'),
                        'user_id' => (string) $this->rowValue($row, 'user_id'),
                        'test_name' => $this->rowValue($row, 'test_name'),
                        'collection_datetime' => $this->rowValue($row, 'collection_datetime'),
                        'result_datetime' => $this->rowValue($row, 'result_datetime'),
                        'result_status' => $this->rowValue($row, 'result_status'),
                        'ordering_provider' => $this->rowValue($row, 'ordering_provider'),
                        'resulting_lab' => $this->rowValue($row, 'resulting_lab'),
                        'analyte' => $this->rowValue($row, 'analyte'),
                        'value' => $this->rowValue($row, 'value'),
                        'unit' => $this->rowValue($row, 'unit'),
                        'range_min' => $this->decimalOrNull($this->rowValue($row, 'range_min')),
                        'range_max' => $this->decimalOrNull($this->rowValue($row, 'range_max')),
                        'range_unit' => $this->rowValue($row, 'range_unit'),
                        'normal_value' => $this->rowValue($row, 'normal_value'),
                        'message_from_provider' => $this->rowValue($row, 'message_from_provider'),
                        'result_comment' => $this->rowValue($row, 'result_comment'),
                        'lab_director' => $this->rowValue($row, 'lab_director'),
                    ]);
                }
            });

        Schema::drop('phr_lab_results');
        Schema::rename('phr_lab_results_legacy', 'phr_lab_results');
    }

    private function rebuildLegacyPatientVitals(): void
    {
        if (! Schema::hasTable('phr_patient_vitals')) {
            return;
        }

        Schema::dropIfExists('phr_patient_vitals_legacy');

        Schema::create('phr_patient_vitals_legacy', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id', 50)->nullable();
            $table->string('vital_name')->nullable();
            $table->date('vital_date')->nullable();
            $table->string('vital_value')->nullable();
        });

        DB::table('phr_patient_vitals')
            ->orderBy('id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('phr_patient_vitals_legacy')->insert([
                        'id' => $this->rowValue($row, 'id'),
                        'user_id' => (string) $this->rowValue($row, 'user_id'),
                        'vital_name' => $this->rowValue($row, 'vital_name'),
                        'vital_date' => $this->rowValue($row, 'vital_date'),
                        'vital_value' => $this->rowValue($row, 'vital_value'),
                    ]);
                }
            });

        Schema::drop('phr_patient_vitals');
        Schema::rename('phr_patient_vitals_legacy', 'phr_patient_vitals');
    }

    private function rowValue(object $row, string $column): mixed
    {
        return property_exists($row, $column) ? $row->{$column} : null;
    }

    private function decimalOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace([',', ' '], '', trim((string) $value));

        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function numericPair(mixed $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        $text = trim((string) $value);

        if (preg_match('/^\s*([+-]?\d+(?:\.\d+)?)\s*\/\s*([+-]?\d+(?:\.\d+)?)\s*$/', $text, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return [$this->decimalOrNull($value), null];
    }
};
