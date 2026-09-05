from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_api_reports_exact_review_version():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "meso-v2.4" in api
