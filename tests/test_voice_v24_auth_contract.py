from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_api_requires_existing_chat_json_auth():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "meso_chat_require_json_auth" in api
