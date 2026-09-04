from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_anchor_action_is_separate_from_vote_action():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "$action==='anchor'" in api
    assert "$action==='vote'" in api
