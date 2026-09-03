<?php
declare(strict_types=1);

require dirname(__DIR__) . '/web/includes/chat_auth.php';
require dirname(__DIR__) . '/web/includes/persona.php';

function expect_true(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$tokens = meso_persona_tokens('Write a detailed 300 word explanation of how streaming cancellation works. 300');
expect_true($tokens !== [], 'Tokenizer returned no tokens');
expect_true(in_array('300', $tokens, true), 'Numeric token 300 was not preserved');
expect_true(count(array_filter($tokens, static fn($token): bool => $token === '300')) === 1, 'Numeric token was not deduplicated');
foreach ($tokens as $token) {
    expect_true(is_string($token), 'Persona tokenizer returned a non-string token');
    preg_quote($token, '/');
}

echo "MESO_PERSONA_NUMERIC_TOKEN=PASS\n";
