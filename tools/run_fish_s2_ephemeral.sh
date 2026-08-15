#!/usr/bin/env bash
set -euo pipefail

# MesoAI Fish Audio S2 ephemeral evaluation runner.
# Private inputs must already exist on the temporary Linux GPU host.
# This script never uploads data. It fails closed until Fish Audio Research
# License acceptance has been recorded by the local control plane.

FISH_COMMIT="${FISH_COMMIT:-e5e292632cb11e7a27b2b7487f58f612bc101e13}"
PROJECT_SLUG="${PROJECT_SLUG:-meso}"
WORKDIR="${WORKDIR:-/workspace/fish-s2-shared}"
PRIVATE_ROOT="${PRIVATE_ROOT:-/workspace/private}"
REFERENCE_AUDIO="${REFERENCE_AUDIO:-$PRIVATE_ROOT/reference.wav}"
REFERENCE_TEXT_FILE="${REFERENCE_TEXT_FILE:-$PRIVATE_ROOT/reference.txt}"
TARGET_TEXT_FILE="${TARGET_TEXT_FILE:-$PRIVATE_ROOT/target.txt}"
OUTPUT_DIR="${OUTPUT_DIR:-$PRIVATE_ROOT/output}"
HF_MODEL="${HF_MODEL:-fishaudio/s2-pro}"
UV_EXTRA="${UV_EXTRA:-cu126}"
MIN_VRAM_MIB="${MIN_VRAM_MIB:-23000}"
MIN_FREE_GIB="${MIN_FREE_GIB:-30}"
PURGE_PRIVATE_INPUTS="${PURGE_PRIVATE_INPUTS:-1}"
KEEP_WORKDIR="${KEEP_WORKDIR:-1}"

export FISH_COMMIT OUTPUT_DIR PROJECT_SLUG

cleanup() {
  local rc=$?
  trap - EXIT
  if [[ "$PURGE_PRIVATE_INPUTS" == "1" ]]; then
    rm -f -- "$REFERENCE_AUDIO" "$REFERENCE_TEXT_FILE" "$TARGET_TEXT_FILE" 2>/dev/null || true
  fi
  if [[ "$KEEP_WORKDIR" != "1" ]]; then
    rm -rf -- "$WORKDIR" 2>/dev/null || true
  fi
  exit "$rc"
}
trap cleanup EXIT

if [[ ! "$PROJECT_SLUG" =~ ^[a-z0-9][a-z0-9_-]{1,31}$ ]]; then
  echo "PROJECT_SLUG must be a short shell-safe identifier." >&2
  exit 9
fi

# Fish Audio Research License acceptance is deliberately separate from the
# subject's voice-cloning authorization. Never infer or silently set this.
if [[ "${MESO_FISH_LICENSE_ACCEPTED:-0}" != "1" ]]; then
  echo "Fish Audio Research License acceptance is required before model/code access." >&2
  echo "Set MESO_FISH_LICENSE_ACCEPTED=1 only after a local acceptance record exists." >&2
  exit 10
fi

if [[ "$(uname -s 2>/dev/null || true)" != "Linux" ]]; then
  echo "Fish S2 remote evaluation requires Linux/WSL." >&2
  exit 11
fi

for cmd in git python3 nvidia-smi sha256sum df; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "Missing required command: $cmd" >&2; exit 12; }
done

for f in "$REFERENCE_AUDIO" "$REFERENCE_TEXT_FILE" "$TARGET_TEXT_FILE"; do
  [[ -f "$f" ]] || { echo "Missing required private input: $f" >&2; exit 13; }
done

private_real="$(realpath "$PRIVATE_ROOT")"
for f in "$REFERENCE_AUDIO" "$REFERENCE_TEXT_FILE" "$TARGET_TEXT_FILE"; do
  file_real="$(realpath "$f")"
  case "$file_real" in
    "$private_real"/*) ;;
    *) echo "Private input escaped PRIVATE_ROOT: $file_real" >&2; exit 14 ;;
  esac
done

VRAM_MIB="$(nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits | head -n1 | tr -d '[:space:]')"
GPU_NAME="$(nvidia-smi --query-gpu=name --format=csv,noheader | head -n1 | sed -E 's/^[[:space:]]+|[[:space:]]+$//g')"
if [[ -z "$VRAM_MIB" || ! "$VRAM_MIB" =~ ^[0-9]+$ || "$VRAM_MIB" -lt "$MIN_VRAM_MIB" ]]; then
  echo "Fish S2 evaluation requires approximately 24 GB VRAM; detected ${VRAM_MIB:-unknown} MiB." >&2
  exit 15
fi

mkdir -p "$(dirname "$WORKDIR")" "$OUTPUT_DIR"
FREE_KIB="$(df -Pk "$(dirname "$WORKDIR")" | awk 'NR==2 {print $4}')"
MIN_FREE_KIB="$((MIN_FREE_GIB * 1024 * 1024))"
if [[ -z "$FREE_KIB" || ! "$FREE_KIB" =~ ^[0-9]+$ || "$FREE_KIB" -lt "$MIN_FREE_KIB" ]]; then
  echo "At least ${MIN_FREE_GIB} GiB free disk is required for model/runtime staging." >&2
  exit 16
fi

REFERENCE_TEXT="$(tr '\n' ' ' < "$REFERENCE_TEXT_FILE" | sed -E 's/[[:space:]]+/ /g; s/^ //; s/ $//')"
TARGET_TEXT="$(tr '\n' ' ' < "$TARGET_TEXT_FILE" | sed -E 's/[[:space:]]+/ /g; s/^ //; s/ $//')"
[[ -n "$REFERENCE_TEXT" ]] || { echo "Reference transcript is empty." >&2; exit 17; }
[[ -n "$TARGET_TEXT" ]] || { echo "Target text is empty." >&2; exit 18; }
[[ ${#REFERENCE_TEXT} -le 4000 ]] || { echo "Reference transcript is unexpectedly large." >&2; exit 19; }
[[ ${#TARGET_TEXT} -le 8000 ]] || { echo "Target text is unexpectedly large." >&2; exit 20; }

missing_packages=()
command -v ffmpeg >/dev/null 2>&1 || missing_packages+=(ffmpeg)
if (( ${#missing_packages[@]} > 0 )); then
  if [[ "${INSTALL_SYSTEM_DEPS:-0}" == "1" && "$(id -u)" == "0" && -x "$(command -v apt-get || true)" ]]; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends ffmpeg portaudio19-dev libsox-dev
  else
    echo "Missing Fish system dependency: ${missing_packages[*]}." >&2
    echo "Use a prepared Fish image or set INSTALL_SYSTEM_DEPS=1 on a disposable root-owned Ubuntu host." >&2
    exit 21
  fi
fi

mkdir -p "$WORKDIR"
cd "$WORKDIR"

if [[ ! -d fish-speech/.git ]]; then
  git clone --filter=blob:none https://github.com/fishaudio/fish-speech.git fish-speech
fi
cd fish-speech
git fetch --depth 1 origin "$FISH_COMMIT"
git checkout --detach "$FISH_COMMIT"
ACTUAL_COMMIT="$(git rev-parse HEAD)"
[[ "$ACTUAL_COMMIT" == "$FISH_COMMIT" ]] || { echo "Fish source pin mismatch." >&2; exit 22; }

if ! command -v uv >/dev/null 2>&1; then
  python3 -m pip install --upgrade uv
fi

uv sync --python 3.12 --extra "$UV_EXTRA"
mkdir -p checkpoints/s2-pro
uv run hf download "$HF_MODEL" --local-dir checkpoints/s2-pro

run_variant() {
  local label="$1"
  local temperature="$2"
  local top_p="$3"
  local top_k="$4"
  local seed="$5"
  local out="$OUTPUT_DIR/${PROJECT_SLUG}-fish-${label}.wav"

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

  [[ -s "$out" ]] || { echo "Fish output missing/empty: $out" >&2; exit 23; }
  sha256sum "$out"
}

run_variant F1 1.00 0.90 30 42
run_variant F2 0.85 0.85 25 43
run_variant F3 1.10 0.92 35 44

GPU_NAME="$GPU_NAME" VRAM_MIB="$VRAM_MIB" python3 - <<'PY'
import hashlib, json, os, wave
from pathlib import Path

out = Path(os.environ['OUTPUT_DIR'])
slug = os.environ['PROJECT_SLUG']
rows = []
for p in sorted(out.glob(f'{slug}-fish-F*.wav')):
    with wave.open(str(p), 'rb') as w:
        dur = w.getnframes() / float(w.getframerate())
        sample_rate = w.getframerate()
        channels = w.getnchannels()
    rows.append({
        'file': p.name,
        'bytes': p.stat().st_size,
        'duration_seconds': round(dur, 3),
        'sample_rate_hz': sample_rate,
        'channels': channels,
        'sha256': hashlib.sha256(p.read_bytes()).hexdigest(),
    })
if len(rows) != 3:
    raise SystemExit(f'expected 3 Fish outputs, got {len(rows)}')
report = {
    'engine': 'Fish Audio S2 Pro',
    'project_slug': slug,
    'fish_commit': os.environ['FISH_COMMIT'],
    'license_acceptance_verified_before_run': True,
    'gpu': os.environ.get('GPU_NAME', ''),
    'vram_mib': int(os.environ.get('VRAM_MIB', '0') or 0),
    'private_inputs_in_report': False,
    'variants': rows,
}
(out / 'report.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
print('MESO_FISH_S2_OUTPUTS_VERIFIED=true')
PY

echo "Fish S2 evaluation complete for $PROJECT_SLUG: $OUTPUT_DIR"
