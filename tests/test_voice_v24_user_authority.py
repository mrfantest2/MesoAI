from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_design_makes_human_identity_judgment_authoritative():
    spec=(ROOT/"docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").read_text(encoding="utf-8")
    assert "Human identity acceptance remains authoritative" in spec
