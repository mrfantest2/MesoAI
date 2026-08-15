<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$statePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'state.local.json';
$default = [
  'ok' => true,
  'project' => 'MesoAI',
  'phase' => 'voice_fidelity',
  'status' => 'preparing_voice_dataset',
  'target_audio' => 156,
  'negative_audio' => 36,
  'memory_enabled' => false,
  'persona_enabled' => false,
  'public_audio' => false,
];
if (is_file($statePath)) {
  $raw = file_get_contents($statePath);
  $custom = json_decode((string)$raw, true);
  if (is_array($custom)) $default = array_replace($default, $custom);
}
echo json_encode($default, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
