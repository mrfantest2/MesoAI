from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_api_uses_generic_review_unavailable_error():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "voice_sweep_unavailable" in api
