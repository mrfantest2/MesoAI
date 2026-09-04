from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_design_has_three_identity_lanes():
    spec=(ROOT/"docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").read_text(encoding="utf-8")
    for lane in ("Arabic casual","Arabic warm/emotional","English casual"):
        assert lane in spec
