from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_review_code_does_not_touch_conversation_memory():
    text="\n".join((ROOT/rel).read_text(encoding="utf-8").lower() for rel in ("web/api/voice-lab-v24.php","web/api/voice-lab-v24-audio.php","tools/meso_chatterbox_v24_client.py"))
    assert "memory-v1" not in text
