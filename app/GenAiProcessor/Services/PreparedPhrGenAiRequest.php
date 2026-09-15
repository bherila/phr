<?php

namespace App\GenAiProcessor\Services;

final readonly class PreparedPhrGenAiRequest
{
    /** @param array<string, mixed> $submissionSchema */
    public function __construct(
        public string $prompt,
        public array $submissionSchema,
    ) {}
}
