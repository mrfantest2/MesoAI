import pytest
from tools.meso_voice_v24_manifest import build_manifest


def test_manifest_refuses_insufficient_bilingual_pool():
    rows=[{"speaker":"Maissoun Moussa","path":r"C:\MesoAI\private\a.wav","lang":"ar","quality":0.99}]
    with pytest.raises(ValueError):
        build_manifest(rows)
