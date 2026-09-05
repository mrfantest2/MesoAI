from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_repository_web_tree_does_not_include_private_v24_manifest():
    assert not (ROOT / "web/voice-lab-v24/manifest.json").exists()
