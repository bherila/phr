<?php

namespace App\Services\PHR\Import\Ehi;

final class EhiMemberKind
{
    public const string TABLE = 'table';

    public const string DICTIONARY = 'dictionary';

    public const string INDEX = 'index';

    public const string README = 'readme';

    public const string NOTE = 'note';

    public const string MEDIA = 'media';

    public const string UNKNOWN = 'unknown';

    public const array ALL = [self::TABLE, self::DICTIONARY, self::INDEX, self::README, self::NOTE, self::MEDIA, self::UNKNOWN];
}
