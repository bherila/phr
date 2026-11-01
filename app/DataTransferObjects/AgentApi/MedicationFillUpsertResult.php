<?php

namespace App\DataTransferObjects\AgentApi;

use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Services\PHR\Ndc\NdcResolution;

final readonly class MedicationFillUpsertResult
{
    public function __construct(
        public PhrMedicationFill $record,
        public string $outcome,
        public string $version,
        public ?PhrMedication $medication,
        public bool $medicationCreated,
        public ?NdcResolution $resolution,
    ) {}
}
