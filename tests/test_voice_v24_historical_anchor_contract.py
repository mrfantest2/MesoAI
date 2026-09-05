from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_v24_design_marks_anchor_as_historical_not_generated():
    spec = (ROOT / "docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").read_text(encoding="utf-8")
    assert "Real Meso reference" in spec
    assert "historical" in spec.lower()
    assert "anchor is not synthesized" in spec
