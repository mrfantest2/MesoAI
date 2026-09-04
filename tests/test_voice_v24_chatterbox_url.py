from tools.meso_chatterbox_v24_client import synthesize


def test_chatterbox_client_module_imports():
    assert callable(synthesize)
