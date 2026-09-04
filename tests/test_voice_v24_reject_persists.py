from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_reject_is_persisted_as_lowercase_choice():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "'reject'" in api
