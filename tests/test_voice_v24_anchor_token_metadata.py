from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_audio_endpoint_uses_metadata_sidecar():
    audio=(ROOT/"web/api/voice-lab-v24-audio.php").read_text(encoding="utf-8")
    assert ".json" in audio
    assert "created_at" in audio
