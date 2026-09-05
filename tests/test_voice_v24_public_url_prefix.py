from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_audio_endpoint_is_under_meso_api_prefix():
    spec=(ROOT/"docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").read_text(encoding="utf-8")
    assert "/meso/api/voice-lab-v24-audio.php" in spec
