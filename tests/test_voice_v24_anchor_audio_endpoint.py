from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_audio_endpoint_handles_wav_and_mp3():
    text=(ROOT/"web/api/voice-lab-v24-audio.php").read_text(encoding="utf-8")
    assert "audio/mpeg" in text
    assert "audio/wav" in text
