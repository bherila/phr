<?php

namespace App\Services\PHR\Import;

/**
 * What PhrCcdaMisroutedMedications did, or in a dry run would do, as medication
 * row ids: rows retracted, rows restored, and rows left for a person because they
 * confirmed them or prescription fills are linked to them.
 */
final readonly class PhrCcdaMisroutedMedicationsOutcome
{
    /**
     * @param  list<int>  $retracted
     * @param  list<int>  $restored
     * @param  list<int>  $confirmed
     * @param  list<int>  $filled
     */
    public function __construct(
        public array $retracted = [],
        public array $restored = [],
        public array $confirmed = [],
        public array $filled = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->retracted === [] && $this->restored === [] && $this->confirmed === [] && $this->filled === [];
    }

    /**
     * What an import tells the person about it, in its result's warnings.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];
        if ($this->retracted !== []) {
            $warnings[] = count($this->retracted).' medication(s) an earlier import read from a hospital medication list (admission, administered or discharge) were retracted: they are not home medications.';
        }
        if ($this->restored !== []) {
            $warnings[] = count($this->restored).' medication(s) this document lists as current medications were restored after an earlier import retracted them.';
        }
        if ($this->confirmed !== []) {
            $warnings[] = count($this->confirmed).' medication(s) a person confirmed came from a hospital medication list and were left as they are; check whether they are home medications.';
        }
        if ($this->filled !== []) {
            $warnings[] = count($this->filled).' medication(s) from a hospital medication list have prescription fills linked to them and were left as they are; check whether they are home medications.';
        }

        return $warnings;
    }
}
