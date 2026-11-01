<?php

namespace Database\Seeders;

use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\OAuthClientConsents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;
use Tests\Support\PhrSyntheticChartFixture;

/**
 * Synthetic accounts for the isolated Playwright database.
 *
 * This seeder is never called by DatabaseSeeder or production deployment. The
 * E2E global setup invokes it explicitly after creating a fresh SQLite file.
 */
class PlaywrightSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->forceCreate([
            'id' => 1,
            'name' => 'E2E Reserved Administrator',
            'email' => 'reserved-admin@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('not-used'),
            'user_role' => 'admin',
        ]);

        $this->createOAuthUser(
            id: 2,
            subject: 'playwright-owner',
            name: 'E2E Record Owner',
            email: 'record-owner@example.test',
        );

        $this->createOAuthUser(
            id: 3,
            subject: 'playwright-outsider',
            name: 'E2E Unrelated User',
            email: 'unrelated-user@example.test',
        );

        PhrPatient::query()->create([
            'owner_user_id' => 2,
            'display_name' => 'Synthetic Bootstrap Patient',
            'relationship' => 'self',
        ]);

        // Realistic-looking synthetic labs and conditions for layout checks and screenshots.
        PhrSyntheticChartFixture::seed(2);

        $this->createMedicationLists();
        $this->createConnectedApps();
    }

    /**
     * A patient whose medications and fills have the long names, wordy packages and pending
     * reviews that real pharmacy data has, for the record list layout checks.
     */
    private function createMedicationLists(): void
    {
        $patient = PhrPatient::query()->create([
            'owner_user_id' => 2,
            'display_name' => 'Synthetic Medication Patient',
            'relationship' => 'self',
        ]);
        $owned = ['patient_id' => $patient->id, 'user_id' => 2];

        $medications = [];
        foreach ([
            ['fluticasone propionate and salmeterol xinafoate (Advair Diskus)', '250-50', 'ug', 'respiratory (inhalation)', 'twice daily', '2024-11-27', null, 'active', 'confirmed', 'Ana Rivera'],
            ['hydrochlorothiazide-lisinopril (Zestoretic)', '12.5-20', 'mg', 'oral', 'daily', '2024-11-27', null, 'active', 'confirmed', null],
            ['mometasone furoate', '50', 'ug', 'nasal', 'daily', '2021-08-12', null, 'active', 'pending_review', 'Ana Rivera'],
            ['alcohol swabs', null, null, null, null, '2021-10-15', null, 'active', 'pending_review', null],
            ['lisinopril', '10', 'mg', 'oral', 'take one tablet by mouth every morning with food', '2021-10-15', null, 'active', 'pending_review', 'Marcus Chen'],
            ['omeprazole', '20', 'mg', 'oral', 'daily', '2022-01-18', null, 'active', 'confirmed', null],
            ['amoxicillin-clavulanate (Augmentin)', '875-125', 'mg', 'oral', 'twice daily', '2023-02-01', '2023-02-11', 'completed', 'confirmed', 'Marcus Chen'],
            ['loratadine', '10', 'mg', 'oral', 'daily', '2020-04-01', '2020-09-11', 'discontinued', 'confirmed', null],
            ['sertraline', '50', 'mg', 'oral', 'daily', '2021-03-08', null, 'on_hold', 'pending_review', 'Marcus Chen'],
        ] as [$name, $dose, $unit, $route, $frequency, $started, $ended, $status, $review, $prescriber]) {
            $medications[$name] = PhrMedication::query()->create([...$owned,
                'name' => $name, 'dose' => $dose, 'dose_unit' => $unit, 'route' => $route, 'frequency' => $frequency,
                'started_on' => $started, 'ended_on' => $ended, 'status' => $status, 'review_status' => $review, 'prescriber_name' => $prescriber,
            ]);
        }

        $drugs = [
            ['mometasone furoate', '50 ug/1', 'spray, metered', 'nasal', 'Example Pharmaceutical Manufacturing Company, Incorporated', '1 bottle, pump in 1 carton (00000-0000-01) / 120 spray, metered in 1 bottle, pump'],
            ['lisinopril', '10 mg/1', 'tablet', 'oral', 'Example Generics LLC', '90 tablet in 1 bottle (00000-0000-02)'],
            ['fluticasone propionate and salmeterol xinafoate (Advair Diskus)', '250 ug/1; 50 ug/1', 'powder, metered', 'respiratory (inhalation)', 'Example Pharmaceutical Manufacturing Company, Incorporated', '1 inhaler in 1 carton (00000-0000-03) / 60 blister pack in 1 inhaler'],
        ];
        $pharmacies = ['Northside Community Pharmacy and Compounding Center #061', 'Lakeview Drug', 'Harbor Specialty Pharmacy Mail Order Services'];
        for ($index = 0; $index < 30; $index++) {
            [$substance, $strength, $form, $route, $labeler, $package] = $drugs[$index % 3];
            PhrMedicationFill::query()->create([...$owned,
                'medication_id' => $medications[$index % 3 === 0 ? 'mometasone furoate' : ($index % 3 === 1 ? 'lisinopril' : 'fluticasone propionate and salmeterol xinafoate (Advair Diskus)')]->id,
                'review_status' => $index % 2 === 0 ? 'pending_review' : 'confirmed',
                'ndc_status' => 'resolved', 'ndc_10' => '0000-0000-'.str_pad((string) ($index % 3 + 1), 2, '0', STR_PAD_LEFT), 'ndc_source' => 'openfda',
                'substance_names' => [$substance], 'strengths' => [$strength], 'dosage_form' => $form, 'route' => $route,
                'labeler_name' => $labeler, 'package_description' => $package,
                'filled_on' => now()->setDate(2026, 10, 28)->subDays($index * 11)->toDateString(),
                'quantity' => '90.000', 'quantity_unit' => 'tablet', 'days_supply' => 90, 'rx_number' => 'RX'.(1000 + $index), 'refills_remaining' => $index % 4,
                'directions' => 'Take as directed by your prescriber.', 'prescriber_name' => $index % 2 === 0 ? 'Rivera, Ana Maria, MD' : 'Chen, Marcus',
                'paid_cents' => 1250 + $index * 100, 'retail_cents' => $index % 5 === 0 ? 18999 : null,
                'pharmacy_name' => $pharmacies[$index % 3], 'source_kind' => 'receipt',
            ]);
        }
    }

    private function createConnectedApps(): void
    {
        $clients = app(ClientRepository::class);
        $consents = app(OAuthClientConsents::class);
        $rest = url('/api/v1');
        $mcp = url('/api/v1/mcp');

        // A retry gets a fresh target because global setup does not rerun after
        // the first attempt has withdrawn its consent.
        foreach ([0, 1] as $attempt) {
            $target = $clients->createAuthorizationCodeGrantClient(
                "Synthetic Connected App {$attempt}", ['https://connected-app.example.test/callback'], true,
            );
            $consents->grant(2, (string) $target->id, $rest, [AgentApiScopes::IDENTITY_READ]);
            $consents->grant(2, (string) $target->id, $mcp, [AgentApiScopes::MCP_USE]);
            $consents->grant(3, (string) $target->id, $rest, [AgentApiScopes::IDENTITY_READ]);
        }

        $other = $clients->createAuthorizationCodeGrantClient(
            'Synthetic Other Connected App', ['https://other-app.example.test/callback'], true,
        );
        $consents->grant(2, (string) $other->id, $rest, [AgentApiScopes::IDENTITY_READ]);

        $outsiderOnly = $clients->createAuthorizationCodeGrantClient(
            'Synthetic Outsider Only App', ['https://outsider-app.example.test/callback'], true,
        );
        $consents->grant(3, (string) $outsiderOnly->id, $rest, [AgentApiScopes::IDENTITY_READ]);

        $revoked = $clients->createAuthorizationCodeGrantClient(
            'Synthetic Previously Revoked App', ['https://revoked-app.example.test/callback'], true,
        );
        $consents->grant(2, (string) $revoked->id, $rest, [AgentApiScopes::IDENTITY_READ])
            ?->forceFill(['revoked_at' => now()])->save();

        $tokenOnly = $clients->createAuthorizationCodeGrantClient(
            'Synthetic Token Only App', ['https://token-only-app.example.test/callback'], true,
        );
        Token::query()->create([
            'id' => 'synthetic-playwright-legacy-token',
            'user_id' => 2,
            'client_id' => $tokenOnly->id,
            'scopes' => [AgentApiScopes::IDENTITY_READ],
            'revoked' => false,
            'expires_at' => now()->addHour(),
        ]);
    }

    private function createOAuthUser(int $id, string $subject, string $name, string $email): void
    {
        User::query()->forceCreate([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make('not-used'),
            'user_role' => 'user',
            'oauth_provider' => 'playwright',
            'oauth_subject' => $subject,
        ]);
    }
}
