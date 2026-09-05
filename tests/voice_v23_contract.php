<?php
declare(strict_types=1);

function need_file(string $path): string {
    if (!is_file($path)) { fwrite(STDERR, "missing file: {$path}\n"); exit(1); }
    $text = file_get_contents($path);
    if (!is_string($text)) { fwrite(STDERR, "unreadable file: {$path}\n"); exit(1); }
    return $text;
}
function need(string $text, string $marker, string $label): void {
    if (!str_contains($text, $marker)) { fwrite(STDERR, "{$label} missing marker: {$marker}\n"); exit(1); }
}
function forbid(string $text, string $marker, string $label): void {
    if (str_contains($text, $marker)) { fwrite(STDERR, "{$label} contains forbidden marker: {$marker}\n"); exit(1); }
}

$api = need_file(__DIR__ . '/../web/api/voice-lab-v23.php');
$audio = need_file(__DIR__ . '/../web/api/voice-lab-v23-audio.php');
$helper = need_file(__DIR__ . '/../tools/meso_xtts_sweep_v23_client.py');
$deploy = need_file(__DIR__ . '/../deploy/deploy_to_xampp.ps1');

foreach ([
    ['voice-lab-v23', 'v2.3 private root'],
    ["'meso-v2.3'", 'v2.3 status'],
    ['voice_sweep_unavailable', 'safe unavailable error'],
    ["['A','B','C','D','E']", 'blind labels'],
] as [$marker, $label]) need($api, $marker, $label);

need($api, "count(\$refs)<2||count(\$refs)>4", '2-4 reference API contract');
need($helper, 'voice-lab-v23\\sweep.json', 'v2.3 sweep map');
need($helper, 'meso-v2.3', 'v2.3 helper identity');
need($helper, '2 <= len(refs) <= 4', 'v2.3 multi-reference contract');
need($audio, 'voice-lab-v23', 'v2.3 audio private root');
need($deploy, "tools\\meso_xtts_sweep_v23_client.py", 'deploy v2.3 helper source');
need($deploy, "meso_xtts_sweep_v23_client.py", 'deploy v2.3 helper destination');
need($deploy, 'MESO_V23_XTTS_SWEEP_RUNTIME_STAGED=true', 'deploy v2.3 helper verification');

// Rejected v2.3 remains reproducible as a private archived review API, but the
// active browser is intentionally allowed to advance to later Voice Lab versions.
foreach ([[$api, 'profile.json', 'API'], [$helper, 'votes.jsonl', 'helper']] as [$text,$marker,$label]) {
    forbid($text, $marker, $label);
}
$runtime = need_file(__DIR__ . '/../tools/meso_xtts_client.py');
forbid($runtime, 'MESO_V23_PROFILE', 'production runtime before explicit promotion work');

fwrite(STDOUT, "MESO_VOICE_V23_ARCHIVED_CONTRACT=PASS\n");
