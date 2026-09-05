from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_status_does_not_list_media_files():
    api=(ROOT/"web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    status=api.split("if($action==='status')",1)[1].split("if($action==='vote')",1)[0]
    assert "glob(" not in status
