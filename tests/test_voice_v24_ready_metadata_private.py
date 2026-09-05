from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_media_sidecars_are_read_from_private_ready():
    audio=(ROOT/"web/api/voice-lab-v24-audio.php").read_text(encoding="utf-8")
    assert "voice-lab-v24\\\\ready" in audio
