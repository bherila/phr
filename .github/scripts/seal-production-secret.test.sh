#!/usr/bin/env bash
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
PHR_SEAL_TOOL="$script_dir/seal-production-secret.php" PHR_SEAL_ROOT="$scratch" php <<'PHP'
<?php
$tool = getenv('PHR_SEAL_TOOL'); $root = getenv('PHR_SEAL_ROOT');
$pair = sodium_crypto_box_keypair();
putenv('DEPLOY_KEY=synthetic-deployment-key');
putenv('SECRET_PUBLIC_KEY='.base64_encode(sodium_crypto_box_publickey($pair)));
putenv('SECRET_KEY_ID=synthetic-key-id');
putenv('SECRET_COPY_DESTINATION='.$root.'/copy.json');
$process = proc_open(['php', $tool], [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
fclose($pipes[0]); $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
if (proc_close($process)!==0 || str_contains($output.$error,'synthetic-deployment-key')) { exit(1); }
$payload=json_decode(file_get_contents($root.'/copy.json'),true);
if ($payload['key_id']!=='synthetic-key-id' || sodium_crypto_box_seal_open(base64_decode($payload['encrypted_value']),$pair)!=='synthetic-deployment-key' || (fileperms($root.'/copy.json')&0777)!==0600) { exit(1); }
putenv('SECRET_PUBLIC_KEY=invalid-public-key');
putenv('SECRET_COPY_DESTINATION='.$root.'/invalid.json');
$process = proc_open(['php', $tool], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
fclose($pipes[0]); $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
if (proc_close($process)===0 || file_exists($root.'/invalid.json') || str_contains($output.$error,'synthetic-deployment-key')) { exit(1); }
echo "Secret encryption round-trip, mode 600, invalid-key rejection and redaction passed.\n";
PHP
