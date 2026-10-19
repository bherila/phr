<?php

namespace App\Services\PHR\Import\Ehi;

/**
 * Pure, provisional member classification by normalized path and first line.
 *
 * Rules (VERSION ehi-classify-2; bump on any change):
 * - readme-like basename (readme, read_me, read-me, about, notice), any extension → readme
 * - `.tsv`, or `.txt` whose first line contains a tab → table; the table name is
 *   the basename without its extension, exactly as supplied (never invented);
 *   a basename that is not an ASCII identifier (TABLE_NAME) → unknown, flagged
 *   `unsupported_table_name`, since a database collation could merge it with
 *   another table name that PHP compares as distinct
 * - `.htm`/`.html` → dictionary when the name or first line looks like table
 *   documentation (an upper-case identifier basename; else, unless the name says
 *   index/contents/toc, dictionary/schema/table wording in the name or
 *   column/key/type wording on the first line), else index
 * - `.rtf` → note
 * - image, PDF, audio, video, and DICOM extensions → media
 * - anything else → unknown
 */
final class EhiMemberClassifier
{
    public const string VERSION = 'ehi-classify-2';

    /** `\z`, not `$`: a trailing newline must not pass. */
    public const string TABLE_NAME = '/^[A-Za-z][A-Za-z0-9_]{0,127}\z/';

    private const array MEDIA_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'bmp', 'tif', 'tiff', 'webp', 'heic', 'heif', 'avif', 'svg',
        'pdf',
        'mp3', 'wav', 'm4a', 'aac', 'ogg', 'oga', 'flac', 'wma',
        'mp4', 'm4v', 'mov', 'avi', 'wmv', 'mkv', 'webm', 'mpg', 'mpeg',
        'dcm',
    ];

    /**
     * Shaped like a table whatever its kind: `.tsv`, or `.txt` whose first line
     * has a tab. Intake patient-scopes every such member, including one kept as
     * a readme or an unknown member, since any of them could hold PAT_ID rows.
     */
    public static function isTableShaped(string $normalizedPath, ?string $firstLine): bool
    {
        $extension = strtolower(pathinfo(basename(str_replace('\\', '/', $normalizedPath)), PATHINFO_EXTENSION));

        return $extension === 'tsv' || ($extension === 'txt' && $firstLine !== null && str_contains($firstLine, "\t"));
    }

    /** @return array{kind: string, table: ?string, unsupported_table_name?: true} */
    public function classify(string $normalizedPath, ?string $firstLine): array
    {
        $basename = basename(str_replace('\\', '/', $normalizedPath));
        $dot = strrpos($basename, '.');
        $stem = $dot === false || $dot === 0 ? $basename : substr($basename, 0, $dot);
        $extension = $dot === false || $dot === 0 ? '' : strtolower(substr($basename, $dot + 1));

        if (preg_match('/^(?:readme|read[_ -]me|about|notice)(?:$|[^a-z])/i', $stem) === 1) {
            return ['kind' => EhiMemberKind::README, 'table' => null];
        }
        if ($extension === 'tsv' || ($extension === 'txt' && $firstLine !== null && str_contains($firstLine, "\t"))) {
            if (preg_match(self::TABLE_NAME, $stem) !== 1) {
                return ['kind' => EhiMemberKind::UNKNOWN, 'table' => null, 'unsupported_table_name' => true];
            }

            return ['kind' => EhiMemberKind::TABLE, 'table' => $stem];
        }
        if ($extension === 'htm' || $extension === 'html') {
            return ['kind' => $this->looksLikeDictionary($stem, $firstLine) ? EhiMemberKind::DICTIONARY : EhiMemberKind::INDEX, 'table' => null];
        }
        if ($extension === 'rtf') {
            return ['kind' => EhiMemberKind::NOTE, 'table' => null];
        }
        if (in_array($extension, self::MEDIA_EXTENSIONS, true)) {
            return ['kind' => EhiMemberKind::MEDIA, 'table' => null];
        }

        return ['kind' => EhiMemberKind::UNKNOWN, 'table' => null];
    }

    private function looksLikeDictionary(string $stem, ?string $firstLine): bool
    {
        if (preg_match('/^[A-Z][A-Z0-9_$#]+$/', $stem) === 1) {
            return true;
        }
        if (preg_match('/index|contents|(?:^|[^a-z])toc(?:$|[^a-z])/i', $stem) === 1) {
            return false;
        }
        if (preg_match('/dictionary|schema|tables?(?:$|[^a-z])/i', $stem) === 1) {
            return true;
        }

        return $firstLine !== null && preg_match('/column\s*name|primary\s*key|data\s*type/i', $firstLine) === 1;
    }
}
