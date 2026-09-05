from tools.meso_chatterbox_v24_client import public_result


def test_v24_helper_engine_metadata_has_no_server_url():
    result=public_result("en",1,"D",5000)
    assert "url" not in result
