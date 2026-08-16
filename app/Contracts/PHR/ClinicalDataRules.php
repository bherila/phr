<?php

namespace App\Contracts\PHR;

interface ClinicalDataRules
{
    /** @return array<string, mixed> */
    public static function rules(bool $partial = false): array;

    /** @return array<string, mixed> */
    public static function jsonSchema(): array;
}
