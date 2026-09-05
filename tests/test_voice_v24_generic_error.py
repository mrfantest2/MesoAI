from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_api_does_not_expose_exception_messages():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "getMessage" not in api
