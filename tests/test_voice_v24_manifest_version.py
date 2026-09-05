from tools.meso_voice_v24_manifest import build_manifest


def test_manifest_version_is_meso_v24():
    rows=[]
    for i in range(6): rows.append({"speaker":"Meso","path":rf"C:\p\ar{i}.wav","lang":"ar","quality":0.9})
    for i in range(2): rows.append({"speaker":"Meso","path":rf"C:\p\en{i}.wav","lang":"en","quality":0.9})
    assert build_manifest(rows)["version"] == "meso-v2.4"
