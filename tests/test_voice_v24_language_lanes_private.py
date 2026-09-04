from tools.meso_voice_v24_manifest import build_manifest


def test_manifest_assigns_arabic_and_english_lanes():
    rows=[]
    for i in range(7): rows.append({"speaker":"Meso","path":rf"C:\p\ar{i}.wav","lang":"ar","quality":0.9})
    for i in range(3): rows.append({"speaker":"Meso","path":rf"C:\p\en{i}.wav","lang":"en","quality":0.9})
    m=build_manifest(rows)
    assert [x["language"] for x in m["lanes"]]==["ar","ar","en"]
