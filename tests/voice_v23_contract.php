<?php
declare(strict_types=1);

function need_file(string $path): string {
    if (!is_file($path)) {
        fwrite(STDERR, "missing file: {$path}\n");
        exit(1);
    }
    $text = file_get_contents($path);
    if (!is_string($text)) {
        fwrite(STDERR, "unreadable file: {$path}\n");
        exit(1);
    }
    return $text;
}
function need(string $text, string $marker, string $label): void {
    if (!str_contains($text, $marker)) {
        fwrite(STDERR, "{$label} missing marker: {$marker}\n");
        exit(1);
    }
}
function forbid(string $text, string $marker, string $label): void {
    if (str_contains($text, $marker)) {
        fwrite(STDERR, "{$label} contains forbidden marker: {$marker}\n");
        exit(1);
    }
}

$api = need_file(__DIR__ . '/../web/api/voice-lab-v23.php');
$audio = need_file(__DIR__ . '/../web/api/voice-lab-v23-audio.php');
$helper = need_file(__DIR__ . '/../tools/meso_xtts_sweep_v23_client.py');
$js = need_file(__DIR__ . '/../web/voice-lab/voice-lab.js');
$index = need_file(__DIR__ . '/../web/voice-lab/index.php');

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
need($js, '/meso/api/voice-lab-v23.php', 'browser v2.3 API');
need($js, '/meso/api/voice-lab-v23-audio.php', 'browser v2.3 audio');
need($index, 'Voice v2.3', 'browser v2.3 badge');
need($index, '2–4 private Meso references', 'browser multi-reference disclosure');

// Lab/voting code must not promote or select a production voice implicitly.
foreach ([[$api, 'profile.json', 'API'], [$js, 'promote', 'browser'], [$helper, 'votes.jsonl', 'helper']] as [$text,$marker,$label]) {
    forbid($text, $marker, $label);
}

// v2.3 is review-only until an explicit winner is promoted later.
$runtime = need_file(__DIR__ . '/../tools/meso_xtts_client.py');
forbid($runtime, 'MESO_V23_PROFILE', 'production runtime before explicit promotion work');

fwrite(STDOUT, "MESO_VOICE_V23_CONTRACT=PASS\n");
