<?php

namespace App\Services\PHR\Import\Ehi;

use RuntimeException;

/**
 * A package refused before any bytes were stored. `reason` is a fixed code and the
 * message is structural; neither ever contains a source value or member name.
 */
final class EhiPackageRejected extends RuntimeException
{
    public const string PATH_UNSAFE = 'path_unsafe';

    public const string PATH_COLLISION = 'path_collision';

    public const string PATH_TOO_DEEP = 'path_too_deep';

    public const string PATH_TOO_LONG = 'path_too_long';

    public const string SYMLINK = 'symlink';

    public const string SPECIAL_FILE = 'special_file';

    public const string ENCRYPTED = 'encrypted';

    public const string TOO_MANY_MEMBERS = 'too_many_members';

    public const string MEMBER_TOO_LARGE = 'member_too_large';

    public const string PACKAGE_TOO_LARGE = 'package_too_large';

    public const string EXPANSION_RATIO = 'expansion_ratio';

    public const string SIZE_MISMATCH = 'size_mismatch';

    public const string UNREADABLE = 'unreadable';

    public const string NOT_EHI = 'not_ehi';

    public const string MULTIPLE_PATIENTS = 'multiple_patients';

    public const string NO_PATIENT = 'no_patient';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
