from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_api_never_creates_profile_json():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "profile.json" not in api
