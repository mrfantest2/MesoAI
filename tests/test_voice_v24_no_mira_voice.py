from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_design_excludes_children_and_third_party_voices():
    spec=(ROOT/"docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").read_text(encoding="utf-8")
    assert "Mira/children/third-party voices are excluded" in spec
