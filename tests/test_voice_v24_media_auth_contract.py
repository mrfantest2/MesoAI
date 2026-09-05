from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_audio_endpoint_requires_chat_auth():
    audio=(ROOT/"web/api/voice-lab-v24-audio.php").read_text(encoding="utf-8")
    assert "meso_chat_require_auth" in audio
    assert "^[a-f0-9]{64}$" in audio
