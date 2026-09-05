from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_candidate_labels_are_anonymous_ae():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "['A','B','C','D','E']" in api
