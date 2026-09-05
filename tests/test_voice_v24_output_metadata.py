from tools.meso_chatterbox_v24_client import public_result


def test_v24_output_metadata_is_browser_safe():
    result=public_result("ar",2,"B",4096)
    assert result == {"ok":True,"engine":"chatterbox","model":"multilingual-v3","language":"ar","references":2,"candidate_id":"B","output_bytes":4096}
