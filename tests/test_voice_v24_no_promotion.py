from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_v24_review_code_has_no_promotion_path():
    paths = [
        ROOT / "web/api/voice-lab-v24.php",
        ROOT / "web/api/voice-lab-v24-audio.php",
        ROOT / "tools/meso_chatterbox_v24_client.py",
    ]
    text = "\n".join(p.read_text(encoding="utf-8") for p in paths)
    assert "promote_voice_v24" not in text
    assert "meso-v2.4/profile.json" not in text
