<?php

namespace Tests\Unit\PHR;

use App\Models\PhrDicomInstance;
use App\Models\PhrDicomSeries;
use App\Services\PHR\DICOM\VolumeSeriesInspector;
use PHPUnit\Framework\TestCase;

class VolumeSeriesInspectorTest extends TestCase
{
    public function test_orientation_key_rounding_combines_nearly_identical_orientations(): void
    {
        $instances = [];
        for ($index = 0; $index < 20; $index++) {
            $orientation = [1, 0, 0, 0, 1, $index < 10 ? 0.42773400001 : 0.42773399];
            $instances[] = $this->instance($index + 1, (float) $index, $orientation);
        }

        $result = $this->inspect($instances);

        $this->assertTrue($result['eligible']);
        $this->assertSame(0, $result['excluded_instance_count']);
        $this->assertSame(20, $result['volume']['slice_count']);
    }

    public function test_median_spacing_adds_non_uniform_spacing_warning(): void
    {
        $positions = range(0, 18);
        $positions[] = 19.5;

        $result = $this->inspect(array_map(
            fn (float|int $position, int $index): PhrDicomInstance => $this->instance($index + 1, (float) $position),
            $positions,
            array_keys($positions),
        ));

        $this->assertTrue($result['eligible']);
        $this->assertSame(1.0, $result['volume']['slice_spacing']);
        $this->assertSame(['non_uniform_spacing'], $result['warnings']);
    }

    public function test_duplicate_projections_make_series_ineligible(): void
    {
        $positions = array_map(static fn (int $position): float => (float) $position, range(0, 18));
        $positions[] = 18.0005;

        $result = $this->inspect(array_map(
            fn (float $position, int $index): PhrDicomInstance => $this->instance($index + 1, $position),
            $positions,
            array_keys($positions),
        ));

        $this->assertFalse($result['eligible']);
        $this->assertContains('duplicate_positions', $result['reasons']);
        $this->assertNull($result['volume']);
        $this->assertSame([], $result['instances']);
    }

    public function test_dominant_group_selection_drops_orientation_outlier(): void
    {
        $instances = [];
        for ($index = 0; $index < 20; $index++) {
            $instances[] = $this->instance($index + 1, (float) $index);
        }
        $instances[] = $this->instance(21, 20.0, [0, 1, 0, 1, 0, 0]);

        $result = $this->inspect($instances);

        $this->assertTrue($result['eligible']);
        $this->assertSame(1, $result['excluded_instance_count']);
        $this->assertSame(range(1, 20), array_column($result['instances'], 'id'));
    }

    /**
     * @param  list<PhrDicomInstance>  $instances
     * @return array<string, mixed>
     */
    private function inspect(array $instances): array
    {
        $series = new PhrDicomSeries(['modality' => 'CT']);
        $series->setRelation('instances', collect($instances));

        return (new VolumeSeriesInspector)->inspect($series);
    }

    /**
     * @param  list<float|int>  $orientation
     */
    private function instance(int $id, float $position, array $orientation = [1, 0, 0, 0, 1, 0]): PhrDicomInstance
    {
        $instance = new PhrDicomInstance([
            'sop_instance_uid' => '1.2.840.10008.'.$id,
            'instance_number' => $id,
            'transfer_syntax_uid' => '1.2.840.10008.1.2.1',
            'rows' => 512,
            'columns' => 512,
            'number_of_frames' => 1,
            'metadata_json' => [
                'ImagePositionPatient' => [0, 0, $position],
                'ImageOrientationPatient' => $orientation,
                'PixelSpacing' => ['0.4277', 0.4277],
                'BitsAllocated' => '16',
                'PixelRepresentation' => '1',
                'SamplesPerPixel' => 1,
                'PhotometricInterpretation' => 'MONOCHROME2',
            ],
        ]);
        $instance->id = $id;

        return $instance;
    }
}
