import { execFileSync } from 'node:child_process'

import { e2eEnv, e2eLiveStore } from './environment'

type Rule = 'documented' | 'hides-etag' | 'none'

/**
 * Sets the bucket's CORS rule, for a run against a real store. `documented` is the rule
 * docs/storage.md gives, for this run's origin. The others are how a bucket gets it wrong.
 */
export function setLiveStoreCors(rule: Rule): void {
  if (!e2eLiveStore) {
    return
  }
  const script = 'require "vendor/autoload.php"; [$_, $endpoint, $region, $key, $secret, $bucket, $origin, $rule] = $argv;'
    + ' $client = new Aws\\S3\\S3Client(["version" => "latest", "region" => $region, "endpoint" => $endpoint, "use_path_style_endpoint" => true, "credentials" => ["key" => $key, "secret" => $secret]]);'
    + ' if ($rule === "none") { $client->deleteBucketCors(["Bucket" => $bucket]); return; }'
    + ' $client->putBucketCors(["Bucket" => $bucket, "CORSConfiguration" => ["CORSRules" => [["AllowedOrigins" => [$origin], "AllowedMethods" => ["PUT"], "AllowedHeaders" => ["content-type"], "ExposeHeaders" => $rule === "documented" ? ["ETag"] : [], "MaxAgeSeconds" => 3600]]]]);'
  execFileSync('php', ['-r', script, e2eLiveStore.endpoint, e2eLiveStore.region, e2eLiveStore.key, e2eLiveStore.secret, e2eLiveStore.bucket, e2eEnv.APP_URL ?? '', rule], { stdio: 'inherit' })
}
