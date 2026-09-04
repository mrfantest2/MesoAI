from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_audio_endpoint_rejects_missing_or_empty_media():
    audio=(ROOT/"web/api/voice-lab-v24-audio.php").read_text(encoding="utf-8")
    assert "$size===false||$size<1" in audio
