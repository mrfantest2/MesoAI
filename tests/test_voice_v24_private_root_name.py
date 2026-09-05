from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_api_private_root_name_is_explicit():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "voice-lab-v24" in api
