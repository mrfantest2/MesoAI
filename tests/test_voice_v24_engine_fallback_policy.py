from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_design_forbids_more_xtts_after_chatterbox_rejection():
    spec = (ROOT / "docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").read_text(encoding="utf-8").lower()
    assert "fish s2" in spec
    assert "do not fall back to another xtts sweep" in spec
