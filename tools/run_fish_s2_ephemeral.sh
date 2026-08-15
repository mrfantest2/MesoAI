#!/usr/bin/env bash
set -euo pipefail

# MesoAI Fish Audio S2 ephemeral evaluation runner.
# This script assumes private reference files are already present on the temporary GPU host.
# It does not upload data and it removes the working directory on exit unless KEEP_WORKDIR=1.

FISH_COMMIT="${FISH_COMMIT:-e5e292632cb11e7a27b2b7487f58f612bc101e13}"
WORKDIR="${WORKDIR:-/workspace/meso-fish-s2}"
REFERENCE_AUDIO="${REFERENCE_AUDIO:-/workspace/private/reference.wav}"
REFERENCE_TEXT_FILE="${REFERENCE_TEXT_FILE:-/workspace/private/reference.txt}"
TARGET_TEXT_FILE="${TARGET_TEXT_FILE:-/workspace/private/target.txt}"
OUTPUT_DIR="${OUTPUT_DIR:-/workspace/private/output}"
HF_MODEL="${HF_MODEL:-fishaudio/s2-pro}"

cleanup() {
  if [[ "${KEEP_WORKDIR:-0}" != "1" ]]; then
    rm -rf "$WORKDIR"
  fi
}
trap cleanup EXIT

for f in "$REFERENCE_AUDIO" "$REFERENCE_TEXT_FILE" "$TARGET_TEXT_FILE"; do
  [[ -f "$f" ]] || { echo "Missing required private input: $f" >&2; exit 2; }
done

if ! command -v nvidia-smi >/dev/null 2>&1; then
  echo "nvidia-smi not found; CUDA GPU host required." >&2
  exit 3
fi

VRAM_MIB="$(nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits | head -n1 | tr -d '[:space:]')"
if [[ -z "$VRAM_MIB" || "$VRAM_MIB" -lt 23000 ]]; then
  echo "Fish S2 evaluation requires a ~24 GB GPU; detected ${VRAM_MIB:-unknown} MiB." >&2
  exit 4
fi

REFERENCE_TEXT="$(tr '\n' ' ' < "$REFERENCE_TEXT_FILE" | sed -E 's/[[:space:]]+/ /g; s/^ //; s/ $//')"
TARGET_TEXT="$(tr '\n' ' ' < "$TARGET_TEXT_FILE" | sed -E 's/[[:space:]]+/ /g; s/^ //; s/ $//')"
[[ -n "$REFERENCE_TEXT" ]] || { echo "Reference transcript is empty." >&2; exit 5; }
[[ -n "$TARGET_TEXT" ]] || { echo "Target text is empty." >&2; exit 6; }

mkdir -p "$WORKDIR" "$OUTPUT_DIR"
cd "$WORKDIR"

if [[ ! -d fish-speech/.git ]]; then
  git clone https://github.com/fishaudio/fish-speech.git fish-speech
fi
cd fish-speech
git fetch --depth 1 origin "$FISH_COMMIT"
git checkout --detach "$FISH_COMMIT"

if ! command -v uv >/dev/null 2>&1; then
  python3 -m pip install --upgrade uv
fi

# Official Fish docs use Python 3.12 and CUDA extras such as cu126/cu128/cu129.
UV_EXTRA="${UV_EXTRA:-cu126}"
uv sync --python 3.12 --extra "$UV_EXTRA"

mkdir -p checkpoints/s2-pro
uv run hf download "$HF_MODEL" --local-dir checkpoints/s2-pro

# Current S2 CLI supports direct --prompt-audio plus --prompt-text, so there is no
# need to persist separate VQ prompt tokens for this short-lived evaluation.
run_variant() {
  local label="$1"
  local temperature="$2"
  local top_p="$3"
  local top_k="$4"
  local seed="$5"
  local out="$OUTPUT_DIR/meso-fish-${label}.wav"

  uv run python fish_speech/models/text2semantic/inference.py \
    --checkpoint-path checkpoints/s2-pro \
    --device cuda \
    --text "$TARGET_TEXT" \
    --prompt-text "$REFERENCE_TEXT" \
    --prompt-audio "$REFERENCE_AUDIO" \
    --temperature "$temperature" \
    --top-p "$top_p" \
    --top-k "$top_k" \
    --seed "$seed" \
    --output "$out" \
    --output-dir "$OUTPUT_DIR/codes-${label}" \
    --no-compile

  sha256sum "$out"
}

# Three controlled samples: baseline, slightly restrained, slightly more varied.
run_variant F1 1.00 0.90 30 42
run_variant F2 0.85 0.85 25 43
run_variant F3 1.10 0.92 35 44

python3 - <<'PY'
import hashlib, json, os, wave
from pathlib import Path
out = Path(os.environ.get('OUTPUT_DIR', '/workspace/private/output'))
rows = []
for p in sorted(out.glob('meso-fish-F*.wav')):
    with wave.open(str(p), 'rb') as w:
        dur = w.getnframes() / float(w.getframerate())
    rows.append({
        'file': p.name,
        'bytes': p.stat().st_size,
        'duration_seconds': round(dur, 3),
        'sha256': hashlib.sha256(p.read_bytes()).hexdigest(),
    })
if len(rows) != 3:
    raise SystemExit(f'expected 3 Fish outputs, got {len(rows)}')
report = {
    'engine': 'Fish Audio S2',
    'fish_commit': os.environ.get('FISH_COMMIT', 'e5e292632cb11e7a27b2b7487f58f612bc101e13'),
    'private_inputs_removed_by_provider_cleanup': True,
    'variants': rows,
}
(out / 'report.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
print('MESO_FISH_S2_OUTPUTS_VERIFIED=true')
PY

echo "Fish S2 evaluation complete: $OUTPUT_DIR"
