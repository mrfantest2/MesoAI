from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_api_is_post_only():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "method_not_allowed" in api
    assert "Allow: POST" in api
