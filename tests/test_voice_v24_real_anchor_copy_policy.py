from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_design_uses_real_anchor_for_comparison_only():
    spec=(ROOT/"docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").read_text(encoding="utf-8").lower()
    assert "anchor is not synthesized" in spec
    assert "not part of voting" in spec
