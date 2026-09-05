from tools.meso_voice_v24_manifest import MESO_ALIASES


def test_v24_manifest_accepts_only_explicit_meso_aliases():
    assert MESO_ALIASES == {"Maissoun Moussa", "Maissoun", "Meso"}
