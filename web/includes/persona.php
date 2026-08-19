<?php
declare(strict_types=1);

function meso_persona_profile_path(): string {
    return meso_private_root() . '\\persona-v1\\profile.json';
}

function meso_persona_load(): array {
    static $cached = null;
    if (is_array($cached)) return $cached;

    $disabled = [
        'enabled' => false,
        'version' => 'meso-v1',
        'grounding' => 'style-only',
        'source_count' => 0,
        'style' => [],
        'constraints' => [],
    ];
    $path = meso_persona_profile_path();
    if (!is_file($path) || !is_readable($path)) return $cached = $disabled;

    $profile = json_decode((string)file_get_contents($path), true);
    if (!is_array($profile) || ($profile['version'] ?? '') !== 'meso-v1' || ($profile['enabled'] ?? false) !== true) {
        return $cached = $disabled;
    }

    $style = array_values(array_filter(array_map(
        static fn($v) => is_string($v) ? trim($v) : '',
        is_array($profile['style'] ?? null) ? $profile['style'] : []
    ), static fn($v) => $v !== ''));
    $constraints = array_values(array_filter(array_map(
        static fn($v) => is_string($v) ? trim($v) : '',
        is_array($profile['constraints'] ?? null) ? $profile['constraints'] : []
    ), static fn($v) => $v !== ''));
    $sources = is_array($profile['sources'] ?? null) ? $profile['sources'] : [];

    return $cached = [
        'enabled' => true,
        'version' => 'meso-v1',
        'grounding' => 'style-only',
        'source_count' => min(count($sources), 200),
        'style' => array_slice($style, 0, 20),
        'constraints' => array_slice($constraints, 0, 20),
    ];
}

function meso_persona_status(): array {
    $p = meso_persona_load();
    return [
        'enabled' => (bool)$p['enabled'],
        'version' => (string)$p['version'],
        'grounding' => (string)$p['grounding'],
        'source_count' => (int)$p['source_count'],
    ];
}

function meso_persona_instructions(): string {
    $p = meso_persona_load();
    if (!$p['enabled']) return '';

    $style = implode("\n- ", array_map(static fn($v) => mb_substr((string)$v, 0, 300), $p['style']));
    $constraints = implode("\n- ", array_map(static fn($v) => mb_substr((string)$v, 0, 300), $p['constraints']));

    return "Meso Persona v1 is enabled as a conservative style simulation grounded only in supplied source material.\n"
        . "You are MesoAI, not the real Maissoun/Meso. If identity matters, say you are an AI simulation inspired by supplied recordings.\n"
        . "Current grounding level: style-only. No transcript-derived factual memory is approved yet.\n"
        . "Style guidance:\n- {$style}\n"
        . "Hard constraints:\n- {$constraints}\n"
        . "- Do not invent memories.\n"
        . "- Do not invent quotations or present generated words as authentic quotes.\n"
        . "- Do not invent biography, beliefs, relationships, dates, preferences, medical facts, or private history.\n"
        . "- Never merge generated conversation content into historical evidence.\n"
        . "When the source evidence is insufficient, respond naturally without fabricating a Meso-specific fact.";
}
