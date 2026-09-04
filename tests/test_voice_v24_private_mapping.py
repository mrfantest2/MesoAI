from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_spec_keeps_ae_mapping_private():
    spec=(ROOT/"docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").read_text(encoding="utf-8")
    assert "private mapping from A-E" in spec
