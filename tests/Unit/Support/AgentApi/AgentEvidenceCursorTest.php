<?php

namespace Tests\Unit\Support\AgentApi;

use App\Support\AgentApi\AgentEvidenceCursor;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AgentEvidenceCursorTest extends TestCase
{
    /**
     * Every target type AgentEvidenceController can put in a next-page cursor.
     *
     * @return array<string, array{string}>
     */
    public static function emittedTargetTypes(): array
    {
        $types = [
            'allergy', 'condition', 'document', 'eob', 'eob-line', 'episode', 'immunization',
            'lab-result', 'medication', 'medication-fill', 'medication-transition',
            'office-visit', 'procedure', 'vital',
        ];

        return array_combine($types, array_map(static fn (string $type): array => [$type], $types));
    }

    #[DataProvider('emittedTargetTypes')]
    public function test_a_cursor_the_controller_hands_out_decodes(string $type): void
    {
        $cursor = ['target_type' => $type, 'target_id' => 7];

        $this->assertSame($cursor, AgentEvidenceCursor::decode(AgentEvidenceCursor::encode($cursor)));
    }

    public function test_an_unknown_target_type_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        AgentEvidenceCursor::decode(AgentEvidenceCursor::encode(['target_type' => 'unknown', 'target_id' => 7]));
    }
}
