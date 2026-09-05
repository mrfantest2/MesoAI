from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_audio_tokens_are_256_bit_hex():
    audio=(ROOT/"web/api/voice-lab-v24-audio.php").read_text(encoding="utf-8")
    assert "{64}" in audio
