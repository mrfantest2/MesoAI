from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_v24_status_exposes_only_safe_review_metadata():
    api = (ROOT / "web/api/voice-lab-v24.php").read_text(encoding="utf-8")
    assert "version'=>'meso-v2.4'" in api
    assert "labels'=>['A','B','C','D','E']" in api
    status = api.split("if($action==='status')", 1)[1].split("if($action==='vote')", 1)[0]
    for forbidden in ("anchor_path", "profiles", "reference_paths", "transcript", "source_id"):
        assert forbidden not in status
