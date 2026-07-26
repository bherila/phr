<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $study_id
 * @property int $series_id
 * @property int $upload_id
 * @property int $file_id
 * @property string $sop_instance_uid
 * @property string|null $sop_class_uid
 * @property int|null $instance_number
 * @property string|null $transfer_syntax_uid
 * @property int|null $rows
 * @property int|null $columns
 * @property int|null $number_of_frames
 * @property array<string, mixed>|null $metadata_json
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PhrPatient $patient
 * @property-read PhrDicomStudy $study
 * @property-read PhrDicomSeries $series
 * @property-read PhrDicomUpload $upload
 * @property-read PhrDicomFile $file
 */
class PhrDicomInstance extends Model
{
    use SerializesDatesAsLocal;

    protected $fillable = [
        'patient_id',
        'study_id',
        'series_id',
        'upload_id',
        'file_id',
        'sop_instance_uid',
        'sop_class_uid',
        'instance_number',
        'transfer_syntax_uid',
        'rows',
        'columns',
        'number_of_frames',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'study_id' => 'integer',
            'series_id' => 'integer',
            'upload_id' => 'integer',
            'file_id' => 'integer',
            'instance_number' => 'integer',
            'rows' => 'integer',
            'columns' => 'integer',
            'number_of_frames' => 'integer',
            'metadata_json' => 'array',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<PhrDicomStudy, $this> */
    public function study(): BelongsTo
    {
        return $this->belongsTo(PhrDicomStudy::class, 'study_id');
    }

    /** @return BelongsTo<PhrDicomSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(PhrDicomSeries::class, 'series_id');
    }

    /** @return BelongsTo<PhrDicomUpload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PhrDicomUpload::class, 'upload_id');
    }

    /** @return BelongsTo<PhrDicomFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(PhrDicomFile::class, 'file_id');
    }
}
