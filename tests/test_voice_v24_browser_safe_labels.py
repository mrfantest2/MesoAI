from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_status_exposes_only_anonymous_labels():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "labels'=>['A','B','C','D','E']" in api
