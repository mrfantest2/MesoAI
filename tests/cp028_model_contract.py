"""Isolated model-selection and audience-guard contract; no provider calls."""
import os
from pathlib import Path
import shutil
import subprocess

root = Path(__file__).resolve().parents[1]
runtime = root / "web/includes/chat_runtime.php"
source = runtime.read_text(encoding="utf-8")
assert "meso_chat_require_conversation_audience($conversationId);" in source
assert "meso_chat_session_can_access($audience)" in source
assert "function meso_chat_requested_model(" in source
print("PASS audience checks preserved in runtime")
php = os.environ.get("PHP_BIN") or shutil.which("php")
if not php and os.name == "nt":
    candidates = list(Path("C:/laragon/bin/php").glob("*/php.exe"))
    php = str(candidates[0]) if candidates else None
if not php:
    raise SystemExit("BLOCKED: PHP executable not available")
code = """
require_once $argv[1];
$cases = [
    [[], 'ollama', 'qwen2.5:7b', 'qwen2.5:7b'],
    [['model'=>'qwen2.5:3b'], 'ollama', 'qwen2.5:7b', 'qwen2.5:3b'],
    [['model'=>'qwen2.5:1.5b'], 'ollama', 'qwen2.5:7b', 'qwen2.5:1.5b'],
];
foreach ($cases as [$body,$provider,$default,$expected]) {
    if (meso_chat_requested_model($body,$provider,$default)!==$expected) exit(11);
}
foreach ([['model'=>'unauthorized'],['model'=>'http://external.invalid']] as $body) {
    try { meso_chat_requested_model($body,'ollama','qwen2.5:7b'); exit(12); }
    catch (InvalidArgumentException $e) {}
}
try { meso_chat_requested_model(['model'=>'qwen2.5:3b'],'openai','gpt-4o-mini'); exit(13); }
catch (InvalidArgumentException $e) {}
if (meso_chat_num_predict('qwen2.5:1.5b')!==360) exit(14);
if (meso_chat_num_predict('qwen2.5:3b')!==600) exit(15);
echo 'CP028_MODEL_CASES_PASS=8';
"""
result = subprocess.run([php, "-r", code, str(runtime)], capture_output=True, text=True, timeout=15)
print(result.stdout.strip())
if result.returncode:
    print(result.stderr.strip())
    raise SystemExit(result.returncode)
