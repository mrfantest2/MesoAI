from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_client_is_chatterbox_not_xtts():
    client=(ROOT/"tools/meso_chatterbox_v24_client.py").read_text(encoding="utf-8").lower()
    assert "chatterbox" in client
    assert "xtts" not in client
