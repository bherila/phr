<?php

namespace App\Services\PHR\Ndc;

/** The outcome of resolving a captured NDC against the openFDA packaging directory, then RxNav's NDC history for discontinued products. */
final readonly class NdcResolution
{
    public const string RESOLVED = 'resolved';

    public const string AMBIGUOUS = 'ambiguous';

    public const string UNRESOLVED = 'unresolved';

    public const string REASON_INVALID = 'invalid_ndc';

    public const string REASON_NOT_FOUND = 'not_found';

    public const string REASON_UNAVAILABLE = 'lookup_unavailable';

    /** @param list<NdcCandidate> $candidates */
    public function __construct(
        public string $status,
        public string $ndcAsCaptured,
        public array $candidates = [],
        public ?string $reason = null,
    ) {}

    /** The single package a resolved NDC means. */
    public function chosen(): ?NdcCandidate
    {
        return $this->status === self::RESOLVED ? ($this->candidates[0] ?? null) : null;
    }

    /**
     * The contract's NdcLookupResult, plus the reason and the next step an agent should take.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $chosen = $this->chosen();

        return [
            'status' => $this->status,
            'input' => $this->ndcAsCaptured,
            'selected' => $chosen?->toArray(['package NDC exact']),
            'candidates' => array_map(
                static fn (NdcCandidate $candidate): array => $candidate->toArray(['package NDC exact']),
                $this->candidates,
            ),
            'reason' => $this->reason,
            'next_step' => match ($this->status) {
                self::RESOLVED => null,
                self::AMBIGUOUS => 'Several packages match this 10-digit NDC, whose layout is unknown. Ask the person which one is on the label, then pass that candidate\'s ndc_10 as ndc_candidate.',
                default => $this->reason === self::REASON_UNAVAILABLE
                    ? 'openFDA or RxNav (which covers discontinued products) could not be reached. Try again shortly; do not guess the drug.'
                    : 'Neither openFDA nor RxNav (which covers discontinued products) lists a package matching this NDC. Re-read the NDC from the source, try ndc_search by drug name, or leave the drug fields for the person to enter.',
            },
        ];
    }
}
