from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_v24_vote_supports_reject_and_ae_only():
    api = (ROOT / "web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "REJECT" in api
    for label in "ABCDE":
        assert f"'{label}'" in api
    assert "votes.jsonl" in api
