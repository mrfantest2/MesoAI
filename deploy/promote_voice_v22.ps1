param(
  [Parameter(Mandatory=$true)][ValidateRange(0,19)][int]$Batch,
  [Parameter(Mandatory=$true)][ValidateSet('A','B','C','D','E')][string]$Label,
  [string]$SweepMap='C:\MesoAI\private\voice-lab-v22\sweep.json',
  [string]$Container='khalil-xtts'
)
$ErrorActionPreference='Stop'

if(!(Test-Path -LiteralPath $SweepMap -PathType Leaf)){throw 'Private Voice v2.2 sweep map is unavailable'}
$map=Get-Content -LiteralPath $SweepMap -Raw | ConvertFrom-Json
if($null -eq $map.batches -or $Batch -ge @($map.batches).Count){throw 'Invalid Voice v2.2 batch'}
$batchRow=@($map.batches)[$Batch]
if($null -eq $batchRow.profiles){throw 'Voice v2.2 batch has no private profile map'}
$refs=@($batchRow.profiles.$Label)
if($refs.Count -ne 1 -or [string]::IsNullOrWhiteSpace([string]$refs[0])){throw 'Blind selection must resolve to exactly one private reference'}
$source=[string]$refs[0]

$state=(& docker inspect -f '{{.State.Status}}' $Container).Trim()
$health=(& docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' $Container).Trim()
if($LASTEXITCODE -ne 0 -or $state -ne 'running' -or $health -ne 'healthy'){throw "XTTS container unavailable: $state/$health"}

$python=@'
import hashlib,json,os,shutil,sys,time
from pathlib import Path
source=Path(sys.argv[1]).resolve()
batch=int(sys.argv[2]); label=sys.argv[3]
root=Path('/data/voice/profiles/khalil').resolve()
source.relative_to(root)
if not source.is_file() or source.stat().st_size <= 4096:
    raise SystemExit('invalid_selected_reference')
target_dir=root/'meso-v2.2'
refs_dir=target_dir/'refs'
refs_dir.mkdir(parents=True,exist_ok=True)
target=refs_dir/'meso_v22_ref_01.wav'
tmp_ref=refs_dir/f'.meso_v22_ref_01.{os.getpid()}.tmp'
shutil.copy2(source,tmp_ref)
os.chmod(tmp_ref,0o444)
os.replace(tmp_ref,target)
os.chmod(target,0o444)
sha=hashlib.sha256(target.read_bytes()).hexdigest()
profile={
  'profile':'meso-v2.2',
  'synthesis_allowed':True,
  'references':[{'path':str(target),'source':'blind-v2.2-selection'}],
  'selection':{'batch':batch,'label':label},
  'reference_sha256':sha,
  'promoted_at':int(time.time())
}
tmp_profile=target_dir/f'.profile.{os.getpid()}.tmp'
with open(tmp_profile,'w',encoding='utf-8',newline='\n') as fh:
    json.dump(profile,fh,ensure_ascii=False,separators=(',',':'))
    fh.write('\n'); fh.flush(); os.fsync(fh.fileno())
os.chmod(tmp_profile,0o444)
os.replace(tmp_profile,target_dir/'profile.json')
os.chmod(target_dir/'profile.json',0o444)
verify=json.loads((target_dir/'profile.json').read_text(encoding='utf-8'))
assert verify['profile']=='meso-v2.2' and verify['synthesis_allowed'] is True
assert len(verify['references'])==1 and verify['references'][0]['path']==str(target)
assert verify['selection']=={'batch':batch,'label':label}
print(json.dumps({'ok':True,'profile':'meso-v2.2','references':1,'batch':batch,'label':label,'sha256':sha},separators=(',',':')))
'@

$result=& docker exec -u 0 $Container python -c $python $source ([string]$Batch) $Label
if($LASTEXITCODE -ne 0){throw 'Voice v2.2 promotion failed'}
$meta=($result -join "`n") | ConvertFrom-Json
if(-not $meta.ok -or $meta.profile -ne 'meso-v2.2' -or [int]$meta.references -ne 1 -or [int]$meta.batch -ne $Batch -or [string]$meta.label -ne $Label){throw 'Voice v2.2 promotion verification failed'}

Write-Host "MESO_VOICE_V22_PROMOTED=true BATCH=$Batch LABEL=$Label REFERENCES=1 SHA256=$($meta.sha256)"
Write-Host 'Promotion was explicit; no vote file or automatic winner selection was used.'
