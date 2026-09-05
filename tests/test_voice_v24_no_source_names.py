from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_http_contract_does_not_name_mira_or_children():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8").lower()
    assert "mira" not in api
    assert "child" not in api
