from tools.meso_voice_v24_manifest import LANES


def test_v24_lane_ids_are_fixed():
    assert LANES == ("ar-casual", "ar-warm", "en-casual")
