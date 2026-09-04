from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_api_rejects_invalid_json():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "invalid_json" in api
