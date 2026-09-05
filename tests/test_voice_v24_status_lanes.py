from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_status_returns_lane_ids_not_private_lane_objects():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "'lanes'=>$laneIds" in api
