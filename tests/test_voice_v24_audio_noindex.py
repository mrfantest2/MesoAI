from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_media_endpoint_is_no_store_private():
    audio=(ROOT/"web/api/voice-lab-v24-audio.php").read_text(encoding="utf-8")
    assert "no-store, private" in audio
