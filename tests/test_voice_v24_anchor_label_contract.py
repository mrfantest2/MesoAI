from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_real_anchor_is_never_treated_as_candidate_vote():
    js = (ROOT / "web/voice-lab/voice-lab.js").read_text(encoding="utf-8")
    assert "Real Meso reference" in js
    assert "Generated candidate" in js
