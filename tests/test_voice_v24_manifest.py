import json
from tools.meso_voice_v24_manifest import build_manifest


def test_manifest_rejects_non_meso_and_builds_bilingual_lanes():
    rows = []
    for i in range(8):
        rows.append({"speaker": "Maissoun Moussa", "path": rf"C:\MesoAI\private\x\ar{i}.wav", "lang": "ar", "quality": 0.95 - i * 0.01})
    for i in range(3):
        rows.append({"speaker": "Maissoun Moussa", "path": rf"C:\MesoAI\private\x\en{i}.wav", "lang": "en", "quality": 0.94 - i * 0.01})
    rows.append({"speaker": "Mira", "path": r"C:\MesoAI\private\x\mira.wav", "lang": "ar", "quality": 0.99})
    manifest = build_manifest(rows)
    text = json.dumps(manifest, ensure_ascii=False)
    assert "mira.wav" not in text
    assert manifest["version"] == "meso-v2.4"
    assert [lane["id"] for lane in manifest["lanes"]] == ["ar-casual", "ar-warm", "en-casual"]
    assert all(set(lane["profiles"]) == set("ABCDE") for lane in manifest["lanes"])
