from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_review_api_is_same_origin_path():
    spec=(ROOT/"docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").read_text(encoding="utf-8")
    assert "Authenticated same-origin access remains mandatory" in spec
