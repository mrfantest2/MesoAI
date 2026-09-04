from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_votes_are_written_under_private_root():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "meso_private_root()" in api
    assert "votes.jsonl" in api
