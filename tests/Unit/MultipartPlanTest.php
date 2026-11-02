<?php

namespace Tests\Unit;

use App\Support\Storage\MultipartPlan;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MultipartPlanTest extends TestCase
{
    private const int MIB = 1024 * 1024;

    private const int GIB = 1024 * self::MIB;

    /** @return array<string, array{int, int, int, int}> file bytes, preferred part, expected part, expected count */
    public static function files(): array
    {
        return [
            'one byte is one short part' => [1, 8 * self::MIB, 8 * self::MIB, 1],
            'just under a part' => [8 * self::MIB - 1, 8 * self::MIB, 8 * self::MIB, 1],
            'exactly one part' => [8 * self::MIB, 8 * self::MIB, 8 * self::MIB, 1],
            'just over one part' => [8 * self::MIB + 1, 8 * self::MIB, 8 * self::MIB, 2],
            'a preference under the store minimum is raised' => [20 * self::MIB, self::MIB, 5 * self::MIB, 4],
            'the largest default package' => [10 * self::GIB, 8 * self::MIB, 8 * self::MIB, 1280],
            'exactly at the part limit with the preferred size' => [80_000 * self::MIB, 8 * self::MIB, 8 * self::MIB, 10_000],
            'one byte over grows the part' => [80_000 * self::MIB + 1, 8 * self::MIB, 9 * self::MIB, 8889],
            'a size that needs a fractional part rounds up' => [100 * self::GIB, 5 * self::MIB, 11 * self::MIB, 9310],
        ];
    }

    #[DataProvider('files')]
    public function test_a_plan_keeps_within_the_store_limits(int $fileBytes, int $preferred, int $partBytes, int $partCount): void
    {
        $plan = MultipartPlan::forFile($fileBytes, $preferred);

        $this->assertSame($partBytes, $plan->partBytes);
        $this->assertSame($partCount, $plan->partCount());
        $this->assertLessThanOrEqual(MultipartPlan::MAX_PARTS, $plan->partCount());
        $this->assertGreaterThanOrEqual(MultipartPlan::MIN_PART_BYTES, $plan->partBytes);
        $this->assertSame($fileBytes, $plan->bytesBefore($partCount) + $plan->bytesOf($partCount), 'the parts add up to the file');
    }

    public function test_every_part_but_the_last_is_the_part_size(): void
    {
        $plan = MultipartPlan::forFile(12 * self::MIB + 3, 5 * self::MIB);

        $this->assertSame([5 * self::MIB, 5 * self::MIB, 2 * self::MIB + 3], [$plan->bytesOf(1), $plan->bytesOf(2), $plan->bytesOf(3)]);
    }

    public function test_a_part_outside_the_file_does_not_exist(): void
    {
        $plan = MultipartPlan::forFile(6 * self::MIB, 5 * self::MIB);

        $this->assertFalse($plan->holds(0));
        $this->assertTrue($plan->holds(2));
        $this->assertFalse($plan->holds(3));
        $this->expectException(InvalidArgumentException::class);
        $plan->bytesOf(3);
    }

    public function test_a_plan_the_store_would_refuse_cannot_be_made(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartPlan(10 * self::MIB, 5 * self::MIB - 1);
    }

    public function test_a_plan_over_the_part_limit_cannot_be_made(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MultipartPlan(10_001 * 5 * self::MIB, 5 * self::MIB);
    }
}
