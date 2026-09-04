from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_api_contains_no_private_source_id_key():
    assert "source_id" not in (ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
