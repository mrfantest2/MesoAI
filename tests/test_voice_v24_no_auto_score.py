from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_design_forbids_machine_auto_promotion():
    spec=(ROOT/"docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").read_text(encoding="utf-8").lower()
    assert "no automatic candidate promotion" in spec
