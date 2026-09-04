from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_design_keeps_language_lanes_separate_not_averaged():
    spec=(ROOT/"docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").read_text(encoding="utf-8")
    assert "do not average the languages into one score" in spec
