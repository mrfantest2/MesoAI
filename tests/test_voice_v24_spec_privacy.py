from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_spec_keeps_raw_exports_and_outputs_private():
    spec=(ROOT/"docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").read_text(encoding="utf-8")
    assert "Raw WhatsApp exports" in spec
    assert "remain outside Git and outside the web root" in spec
