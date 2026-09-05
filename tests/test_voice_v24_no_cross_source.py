from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_manifest_builder_contains_no_child_source_names():
    text=(ROOT/"tools/meso_voice_v24_manifest.py").read_text(encoding="utf-8").lower()
    assert "mira" not in text
