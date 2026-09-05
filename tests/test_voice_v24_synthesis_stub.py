from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_api_current_synthesis_is_fail_closed_until_runtime_wired():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "voice_sweep_unavailable" in api
