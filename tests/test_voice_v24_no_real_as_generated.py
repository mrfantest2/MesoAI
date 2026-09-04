from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_ui_contract_requires_real_and_generated_labels():
    js=(ROOT/"web/voice-lab/voice-lab.js").read_text(encoding="utf-8")
    assert "Real Meso reference" in js
    assert "Generated candidate" in js
