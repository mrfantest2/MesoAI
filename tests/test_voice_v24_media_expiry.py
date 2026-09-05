from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_media_tokens_expire_after_one_hour():
    audio=(ROOT/"web/api/voice-lab-v24-audio.php").read_text(encoding="utf-8")
    assert "3600" in audio
