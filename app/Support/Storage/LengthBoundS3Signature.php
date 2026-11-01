<?php

namespace App\Support\Storage;

use Aws\Signature\S3SignatureV4;

/**
 * S3 SigV4 presigner that keeps `Content-Length` and `Content-Type` among the
 * signed headers. The SDK's presigner drops both, so a presigned PUT would
 * accept a body of any size; with them signed, the storage server rejects a
 * PUT whose length (or type) differs from what the app authorized. R2 has no
 * POST-policy uploads, so this is how an upload size is enforced there.
 */
class LengthBoundS3Signature extends S3SignatureV4
{
    /** @return array<string, bool> */
    protected function getHeaderBlacklist(): array
    {
        $blacklist = parent::getHeaderBlacklist();
        unset($blacklist['content-length'], $blacklist['content-type']);

        return $blacklist;
    }

    /** @return array<string, bool> */
    protected function getPresignHeaderDenyList(): array
    {
        $denyList = parent::getPresignHeaderDenyList();
        unset($denyList['content-type']);

        return $denyList;
    }
}
