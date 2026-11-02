<?php

namespace App\Services\PHR\Documents;

use App\Support\Storage\LengthBoundS3Signature;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * The one place PHR talks to an S3-compatible store about uploads a browser or an agent
 * sends straight to it: signed PutObject and UploadPart URLs, and the multipart calls around
 * them. Provider-neutral and core S3 API only; credentials and endpoints stay in disk
 * configuration. Paths are disk-relative, like everywhere else.
 */
class UploadUrlSigner
{
    /** Whether browsers send bytes straight to the store: only on an installation that chose one. */
    public function directUploadsEnabled(): bool
    {
        return config('phr.storage') === 's3';
    }

    /** @return array{url: string, headers: array<string, string>} */
    public function sign(string $disk, string $path, string $contentType, int $bytes, Carbon $expires): array
    {
        return $this->presign($disk, 'PutObject', [
            'Key' => $this->objectKey($disk, $path), 'ContentType' => $contentType, 'ContentLength' => $bytes,
        ], $expires);
    }

    /**
     * A URL for one part, with its length in the signature, so the store refuses any other
     * size. The part number and upload id are in the URL.
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public function signPart(string $disk, string $path, string $uploadId, int $partNumber, int $bytes, Carbon $expires): array
    {
        return $this->presign($disk, 'UploadPart', [
            'Key' => $this->objectKey($disk, $path), 'UploadId' => $uploadId, 'PartNumber' => $partNumber, 'ContentLength' => $bytes,
        ], $expires);
    }

    public function createMultipartUpload(string $disk, string $path, string $contentType): string
    {
        $result = $this->client($disk)->createMultipartUpload([
            'Bucket' => $this->bucket($disk), 'Key' => $this->objectKey($disk, $path), 'ContentType' => $contentType,
        ]);

        return (string) $result['UploadId'];
    }

    /** @return array<int, array{etag: string, bytes: int}> what the store holds, by part number */
    public function listParts(string $disk, string $path, string $uploadId): array
    {
        $parts = [];
        $pages = $this->client($disk)->getPaginator('ListParts', [
            'Bucket' => $this->bucket($disk), 'Key' => $this->objectKey($disk, $path), 'UploadId' => $uploadId,
        ]);
        foreach ($pages as $page) {
            foreach ($page['Parts'] ?? [] as $part) {
                $parts[(int) $part['PartNumber']] = ['etag' => self::normalizeEtag((string) $part['ETag']), 'bytes' => (int) $part['Size']];
            }
        }

        return $parts;
    }

    /** @param array<int, string> $etags by part number */
    public function completeMultipartUpload(string $disk, string $path, string $uploadId, array $etags): void
    {
        ksort($etags);
        $parts = [];
        foreach ($etags as $number => $etag) {
            $parts[] = ['PartNumber' => $number, 'ETag' => '"'.self::normalizeEtag($etag).'"'];
        }
        $this->client($disk)->completeMultipartUpload([
            'Bucket' => $this->bucket($disk), 'Key' => $this->objectKey($disk, $path), 'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);
    }

    /** An upload the store no longer knows is already gone, so that is a success. */
    public function abortMultipartUpload(string $disk, string $path, string $uploadId): void
    {
        try {
            $this->client($disk)->abortMultipartUpload([
                'Bucket' => $this->bucket($disk), 'Key' => $this->objectKey($disk, $path), 'UploadId' => $uploadId,
            ]);
        } catch (AwsException $failure) {
            if ($failure->getAwsErrorCode() !== 'NoSuchUpload') {
                throw $failure;
            }
        }
    }

    /**
     * Abort incomplete uploads under a disk-relative prefix. They are invisible to a listing
     * of objects and to exists(), and they still hold bytes, so nothing else can find them.
     *
     * @param  (callable(string $uploadId): bool)|null  $keep  uploads that are still wanted
     * @return int how many were aborted
     */
    public function abortIncompleteUploads(string $disk, string $prefix, ?CarbonInterface $startedBefore = null, ?callable $keep = null): int
    {
        $aborted = 0;
        $client = $this->client($disk);
        $pages = $client->getPaginator('ListMultipartUploads', [
            'Bucket' => $this->bucket($disk), 'Prefix' => $this->objectKey($disk, $prefix),
        ]);
        foreach ($pages as $page) {
            foreach ($page['Uploads'] ?? [] as $upload) {
                $uploadId = (string) $upload['UploadId'];
                if (($startedBefore !== null && Carbon::parse((string) $upload['Initiated'])->gte($startedBefore))
                    || ($keep !== null && $keep($uploadId))) {
                    continue;
                }
                $client->abortMultipartUpload(['Bucket' => $this->bucket($disk), 'Key' => (string) $upload['Key'], 'UploadId' => $uploadId]);
                $aborted++;
            }
        }

        return $aborted;
    }

    /** @return array{bytes: int, etag: string}|null null when there is no such object */
    public function head(string $disk, string $path): ?array
    {
        try {
            $result = $this->client($disk)->headObject(['Bucket' => $this->bucket($disk), 'Key' => $this->objectKey($disk, $path)]);
        } catch (AwsException $failure) {
            if ($failure->getStatusCode() === 404) {
                return null;
            }
            throw $failure;
        }

        return ['bytes' => (int) $result['ContentLength'], 'etag' => self::normalizeEtag((string) $result['ETag'])];
    }

    public static function normalizeEtag(string $etag): string
    {
        return trim($etag, " \t\"");
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{url: string, headers: array<string, string>}
     */
    private function presign(string $disk, string $operation, array $params, Carbon $expires): array
    {
        $client = $this->client($disk);
        $command = $client->getCommand($operation, ['Bucket' => $this->bucket($disk), ...$params]);
        $command->getHandlerList()->remove('signer');
        $command->getHandlerList()->remove('s3.checksum');
        $request = (new LengthBoundS3Signature('s3', (string) $client->getRegion()))->presign(
            \Aws\serialize($command), $client->getCredentials()->wait(), $expires,
        );
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (! in_array(strtolower($name), ['host', 'content-length'], true)) {
                $headers[$name] = implode(', ', $values);
            }
        }

        return ['url' => (string) $request->getUri(), 'headers' => $headers];
    }

    private function client(string $disk): S3Client
    {
        $storage = Storage::disk($disk);
        if (! $storage instanceof AwsS3V3Adapter) {
            throw new LogicException('Direct uploads require an S3-compatible staging disk.');
        }

        return $storage->getClient();
    }

    private function bucket(string $disk): string
    {
        return (string) config("filesystems.disks.{$disk}.bucket");
    }

    /**
     * The client takes the whole object key, while the disk reads and deletes under its
     * `root`. Without the prefix a signed PUT lands where the disk never looks.
     */
    private function objectKey(string $disk, string $path): string
    {
        $root = trim((string) config("filesystems.disks.{$disk}.root", ''), '/');

        return $root === '' ? $path : $root.'/'.$path;
    }
}
