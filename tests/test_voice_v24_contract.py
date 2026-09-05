from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_voice_v24_is_review_only_and_private():
    api = (ROOT / "web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    js = (ROOT / "web/voice-lab/voice-lab.js").read_text(encoding="utf-8")
    assert "meso-v2.4" in api
    assert "voice-lab-v24" in api
    assert "Real Meso reference" in js
    assert "generated candidate" in js.lower()
    assert "promote" not in api.lower()
    assert "profile.json" not in api
    assert "source_id" not in api
    assert "transcript" not in api
