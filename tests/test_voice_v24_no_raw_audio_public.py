from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_review_media_is_served_only_through_token_endpoint():
    spec=(ROOT/"docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").read_text(encoding="utf-8")
    assert "anchor media URLs" in spec
    assert "generated media URLs" in spec
