<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Immunization;

use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiRevision;
use App\Models\PhrImmunization;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Support\PHR\PhrRecordLifecycle;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * The patient's immunizations and their evidence links, read once, so reconciling a
 * row costs no query of its own. A preview has a time limit, and the budget check a
 * per-row read carried is about a dozen queries.
 */
final class EhiImmunizationRecordIndex
{
    /** @var array<int, list<PhrEhiRecordLink>> entity id => links, oldest first */
    private array $links;

    /** @var array<int, PhrImmunization> records a link names, withdrawn or not */
    private array $linked = [];

    /** @var array<string, PhrImmunization> this source's records by exact external id */
    private array $held = [];

    /** @var array<int, PhrEhiRevision> revisions a link names */
    private array $revisions = [];

    /** @var array<string, list<array{id: int, name: string|null, lot: string|null}>> Y-m-d => live records, by ascending id */
    private array $byDay = [];

    public function __construct(EhiMappingContext $context, string $ownImportSource)
    {
        $this->links = $context->linksByEntity('immunization_id');
        $scanned = 0;
        $inclusive = static fn () => PhrImmunization::query()->withoutGlobalScope(SoftDeletingScope::class)->where('patient_id', $context->patient->id);

        $recordIds = [];
        $revisionIds = [];
        foreach ($this->links as $links) {
            foreach ($links as $link) {
                $recordIds[(int) $link->immunization_id] = true;
                $revisionIds[(int) $link->revision_id] = true;
            }
        }
        foreach (array_chunk(array_keys($recordIds), 500) as $chunk) {
            foreach ($inclusive()->whereIn('id', $chunk)->get() as $record) {
                $context->budget->charge(++$scanned, 0);
                $this->linked[$record->id] = $record;
            }
        }
        foreach (array_chunk(array_keys($revisionIds), 500) as $chunk) {
            foreach (PhrEhiRevision::query()->whereIn('id', $chunk)->get(['id', 'values_json']) as $revision) {
                $this->revisions[$revision->id] = $revision;
            }
        }
        foreach ($inclusive()->where('import_source', $ownImportSource)->lazyById(500) as $record) {
            $context->budget->charge(++$scanned, 0);
            if ($record->external_id !== null) {
                $this->held[$record->external_id] = $record;
            }
        }
        // Duplicate candidates are never merged automatically; a person refused a rejected
        // one, and it is left out. So is what this source holds under its own identity: a
        // different key is by definition a different dose, whatever day it was given.
        $live = PhrImmunization::query()->where('patient_id', $context->patient->id)->where('review_status', '!=', PhrReviewStatus::REJECTED)
            ->where(static fn ($query) => $query->whereNull('import_source')->orWhere('import_source', '!=', $ownImportSource));
        PhrRecordLifecycle::scopeLive($live);
        foreach ($live->select(['id', 'vaccine_name', 'lot_number', 'administered_on'])->lazyById(500) as $record) {
            $context->budget->charge(++$scanned, 0);
            $date = $record->administered_on?->format('Y-m-d');
            if ($date !== null) {
                $this->byDay[$date][] = ['id' => $record->id, 'name' => $record->vaccine_name, 'lot' => $record->lot_number];
            }
        }
    }

    /** @return list<PhrEhiRecordLink> */
    public function linksOf(int $entityId): array
    {
        return $this->links[$entityId] ?? [];
    }

    public function linkedRecord(int $id): ?PhrImmunization
    {
        return $this->linked[$id] ?? null;
    }

    public function revision(int $id): ?PhrEhiRevision
    {
        return $this->revisions[$id] ?? null;
    }

    public function heldIdentity(string $externalId): ?PhrImmunization
    {
        return $this->held[$externalId] ?? null;
    }

    /** @return list<array{id: int, name: string|null, lot: string|null}> */
    public function onDate(string $date): array
    {
        return $this->byDay[$date] ?? [];
    }
}
