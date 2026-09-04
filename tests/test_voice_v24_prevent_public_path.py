from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_helper_private_output_guard_mentions_ready_root():
    client=(ROOT/"tools/meso_chatterbox_v24_client.py").read_text(encoding="utf-8")
    assert "READY_ROOT" in client
