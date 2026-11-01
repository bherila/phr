<?php

namespace App\Services\PHR\Documents;

use App\Support\Storage\LengthBoundS3Signature;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;

/** Provider-neutral S3-compatible signing; credentials and endpoints stay in disk configuration. */
class UploadUrlSigner
{
    /** @return array{url: string, headers: array<string, string>} */
    public function sign(string $disk, string $path, string $contentType, int $bytes, Carbon $expires): array
    {
        $storage = Storage::disk($disk);
        if (! $storage instanceof AwsS3V3Adapter) {
            throw new LogicException('Direct uploads require an S3-compatible staging disk.');
        }
        $client = $storage->getClient();
        $command = $client->getCommand('PutObject', [
            'Bucket' => config("filesystems.disks.{$disk}.bucket"),
            'Key' => $path, 'ContentType' => $contentType, 'ContentLength' => $bytes,
        ]);
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
}
