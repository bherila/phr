<?php

namespace Tests\Support;

use App\Services\PHR\Documents\UploadUrlSigner;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The store's side of direct uploads for the default suite, over NoLocalPathDisk: it keeps
 * what a real store keeps, and refuses what a real store refuses. The same tests run against
 * a real server with PHR_TEST_S3_ENDPOINT, where UploadUrlSigner itself is used.
 */
final class FakeObjectStoreUploads extends UploadUrlSigner
{
    /** @var array<string, array{disk: string, path: string, bytes: int, expires: Carbon, upload_id: string|null, part: int|null}> */
    private array $urls = [];

    /** @var array<string, array{disk: string, path: string, initiated: Carbon, parts: array<int, array{etag: string, bytes: int, body: string}>}> */
    public array $uploads = [];

    public bool $failAborts = false;

    public bool $failLists = false;

    public bool $failHeads = false;

    public function directUploadsEnabled(): bool
    {
        return true;
    }

    public function sign(string $disk, string $path, string $contentType, int $bytes, Carbon $expires): array
    {
        return $this->remember($disk, $path, $bytes, $expires, null, null, ['Content-Type' => $contentType]);
    }

    public function signPart(string $disk, string $path, string $uploadId, int $partNumber, int $bytes, Carbon $expires): array
    {
        return $this->remember($disk, $path, $bytes, $expires, $uploadId, $partNumber, []);
    }

    public function createMultipartUpload(string $disk, string $path, string $contentType): string
    {
        $id = Str::random(32);
        $this->uploads[$id] = ['disk' => $disk, 'path' => $path, 'initiated' => Carbon::now(), 'parts' => []];

        return $id;
    }

    public function listParts(string $disk, string $path, string $uploadId): array
    {
        if ($this->failLists || ! isset($this->uploads[$uploadId])) {
            throw new RuntimeException('The store refused the listing, as it does for an upload that has finished.');
        }

        return array_map(static fn (array $part): array => ['etag' => $part['etag'], 'bytes' => $part['bytes']], $this->uploads[$uploadId]['parts']);
    }

    public function completeMultipartUpload(string $disk, string $path, string $uploadId, array $etags): void
    {
        ksort($etags);
        $body = '';
        foreach ($etags as $number => $etag) {
            $part = $this->uploads[$uploadId]['parts'][$number] ?? null;
            if ($part === null || $part['etag'] !== self::normalizeEtag($etag)) {
                throw new RuntimeException('InvalidPart');
            }
            $body .= $part['body'];
        }
        Storage::disk($disk)->put($path, $body);
        unset($this->uploads[$uploadId]);
    }

    public function abortMultipartUpload(string $disk, string $path, string $uploadId): void
    {
        $this->refuseAborts();
        unset($this->uploads[$uploadId]);
    }

    public function abortIncompleteUploads(string $disk, string $prefix, ?CarbonInterface $startedBefore = null, ?callable $keep = null): int
    {
        $this->refuseAborts();
        $aborted = 0;
        foreach ($this->uploads as $id => $upload) {
            if ($upload['disk'] !== $disk || ! str_starts_with($upload['path'], $prefix)
                || ($startedBefore !== null && $upload['initiated']->gte($startedBefore))
                || ($keep !== null && $keep($id))) {
                continue;
            }
            unset($this->uploads[$id]);
            $aborted++;
        }

        return $aborted;
    }

    public function head(string $disk, string $path): ?array
    {
        if ($this->failHeads) {
            throw new RuntimeException('The store refused the request.');
        }

        $body = Storage::disk($disk)->get($path);

        return is_string($body) ? ['bytes' => strlen($body), 'etag' => md5($body)] : null;
    }

    /**
     * What a PUT to a signed URL does.
     *
     * @return array{status: int, etag: string|null}
     */
    public function receive(string $url, string $body): array
    {
        $signed = $this->urls[$url] ?? null;
        if ($signed === null || strlen($body) !== $signed['bytes'] || $signed['expires']->isPast()) {
            return ['status' => 403, 'etag' => null];
        }
        $etag = md5($body);
        if ($signed['upload_id'] === null) {
            Storage::disk($signed['disk'])->put($signed['path'], $body);

            return ['status' => 200, 'etag' => '"'.$etag.'"'];
        }
        if (! isset($this->uploads[$signed['upload_id']])) {
            return ['status' => 404, 'etag' => null];
        }
        $this->uploads[$signed['upload_id']]['parts'][(int) $signed['part']] = ['etag' => $etag, 'bytes' => strlen($body), 'body' => $body];

        return ['status' => 200, 'etag' => '"'.$etag.'"'];
    }

    private function refuseAborts(): void
    {
        if ($this->failAborts) {
            throw new RuntimeException('The store refused the abort.');
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{url: string, headers: array<string, string>}
     */
    private function remember(string $disk, string $path, int $bytes, Carbon $expires, ?string $uploadId, ?int $part, array $headers): array
    {
        $url = 'https://objects.example.test/'.Str::random(24);
        $this->urls[$url] = ['disk' => $disk, 'path' => $path, 'bytes' => $bytes, 'expires' => $expires->copy(), 'upload_id' => $uploadId, 'part' => $part];

        return ['url' => $url, 'headers' => $headers];
    }
}
