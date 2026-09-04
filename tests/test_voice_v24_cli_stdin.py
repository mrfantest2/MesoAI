from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_chatterbox_client_reads_json_from_stdin():
    client=(ROOT/"tools/meso_chatterbox_v24_client.py").read_text(encoding="utf-8")
    assert "sys.stdin.read()" in client
