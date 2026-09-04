from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_error_responses_are_symbolic_not_path_based():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "error'=>'voice_sweep_unavailable'" in api
