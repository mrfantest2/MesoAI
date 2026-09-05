from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_voice_lab_ui_mentions_real_anchor_and_generated_candidate():
    js = (ROOT / "web/voice-lab/voice-lab.js").read_text(encoding="utf-8")
    assert "Real Meso reference" in js
    assert "Generated candidate" in js
    assert "voice-lab-v24.php" in js
