from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_api_uses_private_ready_directory():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "voice-lab-v24" in api
