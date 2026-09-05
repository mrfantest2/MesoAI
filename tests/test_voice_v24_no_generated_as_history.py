from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_spec_never_treats_generated_voice_as_history():
    spec=(ROOT/"docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").read_text(encoding="utf-8").lower()
    assert "generated output is never historical evidence" in spec
