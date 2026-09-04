from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def test_v24_spec_and_plan_are_committed():
    assert (ROOT/"docs/superpowers/specs/2026-09-05-meso-voice-v24-chatterbox-identity-design.md").is_file()
    assert (ROOT/"docs/superpowers/plans/2026-09-05-meso-voice-v24-chatterbox-identity.md").is_file()
