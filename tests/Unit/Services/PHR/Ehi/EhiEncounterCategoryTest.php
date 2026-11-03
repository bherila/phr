<?php

namespace Tests\Unit\Services\PHR\Ehi;

use App\Services\PHR\Import\Ehi\Mapping\Encounter\EhiEncounterCategory as Category;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EhiEncounterCategoryTest extends TestCase
{
    /** @return array<string, array{0: string|null, 1: string|null, 2: string|null, 3: bool, 4: string}> */
    public static function encounters(): array
    {
        return [
            'an office visit' => ['Office Visit', 'Completed', null, false, Category::CLINICAL],
            'case does not matter' => ['OFFICE VISIT', 'completed', null, false, Category::CLINICAL],
            'a hospital encounter has no appointment status' => ['Hospital Encounter', null, null, true, Category::CLINICAL],
            'no type, but the appointment happened' => [null, 'Arrived', null, false, Category::CLINICAL],
            'no type, but an admission' => [null, null, null, true, Category::CLINICAL],
            'an appointment says only what its status says' => ['Appointment', 'Completed', null, false, Category::CLINICAL],
            'telephone' => ['Telephone', null, null, false, Category::TELEPHONE],
            'telephone even when completed' => ['Telephone Visit', 'Completed', null, false, Category::TELEPHONE],
            'portal' => ['Patient Message', null, null, false, Category::PORTAL],
            'canceled' => ['Office Visit', 'Canceled', null, false, Category::CANCELLED],
            'no show' => [null, 'No Show', null, false, Category::CANCELLED],
            'left without being seen' => ['Hospital Encounter', 'Left without seen', null, true, Category::CANCELLED],
            'canceled wins over a telephone type' => ['Telephone', 'Canceled', null, false, Category::CANCELLED],
            'orders only' => ['Orders Only', null, null, false, Category::ADMINISTRATIVE],
            'billing' => ['Charge', 'Completed', null, false, Category::ADMINISTRATIVE],
            'scheduled has not happened' => ['Office Visit', 'Scheduled', null, false, Category::ADMINISTRATIVE],
            'reporting marks it invalid' => ['Office Visit', 'Completed', 'Invalid', false, Category::ADMINISTRATIVE],
            'a type this list does not know' => ['Synthetic Local Type', 'Completed', null, false, Category::UNKNOWN],
            'a status the spec does not document' => ['Office Visit', 'Synthetic Status', null, false, Category::UNKNOWN],
            'nothing stated' => [null, null, null, false, Category::UNKNOWN],
            'blank cells state nothing' => ['  ', ' ', ' ', false, Category::UNKNOWN],
        ];
    }

    #[DataProvider('encounters')]
    public function test_classifies_from_what_the_row_states(?string $type, ?string $status, ?string $calculated, bool $admitted, string $expected): void
    {
        self::assertSame($expected, Category::of($type, $status, $calculated, $admitted));
    }
}
