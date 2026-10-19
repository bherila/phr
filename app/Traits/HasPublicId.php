<?php

namespace App\Traits;

use App\Services\PHR\Identity\PhrPublicIds;
use Illuminate\Database\Eloquent\Model;

/**
 * Gives a patient, document or EOB its public id in the same transaction that
 * writes it, so the id exists before any backup or API read. Rows written
 * without model events get theirs on first API read ({@see PhrPublicIds}).
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::created(static function (Model $record): void {
            app(PhrPublicIds::class)->assign($record);
        });
    }
}
