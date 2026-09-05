from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_public_api_never_returns_private_mapping_keys():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    for forbidden in ("source_id", "transcript"):
        assert forbidden not in api
