<?php

namespace App\Console\Commands\Phr;

use App\Support\Storage\PhrStorageVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * Read-only: changes nothing on any disk or in the database. docs/storage.md describes
 * running it against both sides of a move before changing PHR_STORAGE.
 */
#[Signature('phr:storage:verify {--disk=* : Limit to these disks} {--size-only : Compare existence and size, not hashes}')]
#[Description('Check every stored object against the database: count, size and hash')]
final class PhrStorageVerifyCommand extends BasePhrCommand
{
    public function handle(PhrStorageVerifier $verifier): int
    {
        $this->line('Storage choice: '.((string) config('phr.storage') ?: 'none (each disk keeps its own setting)'));
        /** @var list<string> $disks */
        $disks = array_values(array_filter((array) $this->option('disk'), 'is_string'));
        $result = $verifier->verify($disks, ! $this->option('size-only'));

        $rows = [];
        foreach ($result['disks'] as $disk => $tally) {
            $rows[] = [
                $disk, (string) config("filesystems.disks.{$disk}.driver", 'unknown'), $tally['rows'], $tally['ok'], $tally['missing'], $tally['wrong_size'],
                $tally['wrong_hash'], $tally['unreadable'], $tally['hashed'], $tally['size_only'], $tally['bytes'], $tally['objects'],
                $tally['unreferenced'], $tally['quarantined'], $tally['in_flight'],
            ];
        }
        $this->table(['disk', 'driver', 'rows', 'ok', 'missing', 'wrong size', 'wrong hash', 'unreadable', 'hashed', 'size only', 'bytes', 'objects', 'unreferenced', 'quarantined', 'in flight'], $rows);

        foreach ($result['problems'] as $problem) {
            $this->error($problem['id'] === null ? "{$problem['table']}: {$problem['problem']}" : "{$problem['table']} row {$problem['id']}: {$problem['problem']}");
        }
        if ($result['problem_count'] > count($result['problems'])) {
            $this->error(($result['problem_count'] - count($result['problems'])).' more problem(s) not listed.');
        }

        return $result['problem_count'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
