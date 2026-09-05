from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_audio_mimes_are_explicit():
    text=(ROOT/"web/api/voice-lab-v24-audio.php").read_text(encoding="utf-8")
    assert "audio/mpeg" in text and "audio/wav" in text
