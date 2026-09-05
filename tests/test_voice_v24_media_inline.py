from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_review_media_is_inline_not_attachment_download():
    audio=(ROOT/"web/api/voice-lab-v24-audio.php").read_text(encoding="utf-8")
    assert "Content-Disposition: inline" in audio
