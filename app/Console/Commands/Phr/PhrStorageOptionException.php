<?php

namespace App\Console\Commands\Phr;

use InvalidArgumentException;

/**
 * An invalid option passed to a PHR storage maintenance command.
 *
 * Its message is safe to print to the console because it can only be built from
 * the named constructors below, and every one of them assembles its text from
 * literals and code-owned allow-lists. It never carries the operator's input or
 * text from another exception, so printing it cannot disclose storage keys,
 * patient data, or SQL detail (see issue #163).
 */
final class PhrStorageOptionException extends InvalidArgumentException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /** @param list<string> $allowed */
    public static function invalidDisk(array $allowed): self
    {
        return new self('--disk must be one of: '.implode(', ', $allowed).'.');
    }

    /** @param list<string> $allowed */
    public static function invalidArtifact(array $allowed): self
    {
        return new self('--artifact must be one of: '.implode(', ', $allowed).'.');
    }

    public static function invalidPatient(): self
    {
        return new self('--patient must be a positive integer.');
    }

    public static function incompatibleScope(): self
    {
        return new self('--disk and --artifact select incompatible storage areas.');
    }
}
