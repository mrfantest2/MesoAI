<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$statePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'state.local.json';
$default = [
  'ok' => true,
  'project' => 'MesoAI',
  'phase' => 'voice_fidelity',
  'status' => 'next_engine_evaluation_pending',
  'mode' => 'apply',
  'target_audio' => 156,
  'target_duration_minutes' => 55.58,
  'deep_analyzed' => 156,
  'deep_quality_usable' => 130,
  'quality_passed' => 130,
  'selected_references' => 20,
  'normalization' => 'prepared_private',
  'profile_builder' => 'prepared_private',
  'synthesis' => 'authorized_local_only',
  'next_engine' => 'Fish Audio S2',
  'next_engine_status' => 'evaluation_pending',
  'consent_recorded' => true,
  'memory_enabled' => false,
  'persona_enabled' => false,
  'public_audio' => false,
];
if (is_file($statePath)) {
  $raw = file_get_contents($statePath);
  $custom = json_decode((string)$raw, true);
  if (is_array($custom)) $default = array_replace($default, $custom);
}
unset($default['negative_audio'], $default['negative_duration_minutes']);
echo json_encode($default, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
