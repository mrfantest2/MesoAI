import os, pathlib, shutil, tempfile, json, hashlib, secrets, subprocess, time, urllib.request, urllib.error, urllib.parse, socket, sys
SRC=pathlib.Path(__file__).resolve().parents[1]/"web"
PHP=os.environ.get('PHP_BIN') or shutil.which('php') or (r'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe' if os.name=='nt' else 'php')
root=pathlib.Path(tempfile.mkdtemp(prefix="meso-qa-isolated-"))
proc=None
passes=0
def check(cond,label):
 global passes
 if not cond:raise AssertionError(label)
 passes+=1
 print("PASS",label)
def open_no_redirect(req):
 class NoRedirect(urllib.request.HTTPRedirectHandler):
  def redirect_request(self,req,fp,code,msg,headers,newurl):return None
 opener=urllib.request.build_opener(NoRedirect)
 try:r=opener.open(req,timeout=5)
 except urllib.error.HTTPError as exc:r=exc
 with r:return r.status,dict(r.headers),r.read(100000)
def request(port,path,body=None,cookie=None,method=None,form=False):
 headers={"User-Agent":"Meso-Isolated-QA/1.0"}
 if cookie:headers["Cookie"]="meso_chat_auth="+cookie
 if body is not None:
  headers["Content-Type"]="application/x-www-form-urlencoded" if form else "application/json"
  headers["Origin"]=f"http://127.0.0.1:{port}"
  headers["Sec-Fetch-Site"]="same-origin"
  raw=urllib.parse.urlencode(body).encode() if form else json.dumps(body).encode()
 else:raw=None
 req=urllib.request.Request(f"http://127.0.0.1:{port}/meso"+path,data=raw,headers=headers,method=method)
 return open_no_redirect(req)
try:
 staging=root/"www"/"meso"
 shutil.copytree(SRC,staging,ignore=shutil.ignore_patterns(".git","*.sqlite","*.db","private"))
 private=root/"private"
 private.mkdir()
 secret=secrets.token_urlsafe(48)
 tokens={role:secrets.token_urlsafe(48) for role in ("majid","mira")}
 (private/"chat-cookie-secret.txt").write_text(secret)
 (private/"chat-accounts.json").write_text(json.dumps({"accounts":{r:hashlib.sha256(t.encode()).hexdigest() for r,t in tokens.items()}}))
 # The throwaway copy is configured to use *only* disposable test data.
 auth=staging/"includes"/"chat_auth.php"
 t=auth.read_text(encoding="utf-8")
 old="return $test !== '' ? $test : 'C:\\\\MesoAI\\\\private';"
 new="return "+json.dumps(str(private).replace("\\","/"))+";"
 assert t.count(old)==1
 auth.write_text(t.replace(old,new,1),encoding="utf-8")
 memory=staging/"includes"/"memory.php"
 t=memory.read_text(encoding="utf-8")
 old="    return $override !== '' ? $override : meso_private_root() . DIRECTORY_SEPARATOR . 'memory-v1';"
 new="    return meso_private_root() . DIRECTORY_SEPARATOR . 'memory-v1';"
 assert t.count(old)==1
 memory.write_text(t.replace(old,new,1),encoding="utf-8")
 router=root/"router.php"
 router.write_text("<?php\n$p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);\nif (!str_starts_with($p,'/meso/')) {http_response_code(404);return true;}\n$root="+json.dumps(str(staging).replace("\\","/"))+";\n$file=realpath($root.substr($p,5));\nif ($file && is_dir($file)) $file=realpath($file.DIRECTORY_SEPARATOR.'index.php');\nif (!$file || !str_starts_with($file,realpath($root).DIRECTORY_SEPARATOR) || !is_file($file)) {http_response_code(404);return true;}\nrequire $file;\nreturn true;\n",encoding="utf-8")
 s=socket.socket();s.bind(("127.0.0.1",0));port=s.getsockname()[1];s.close()
 proc=subprocess.Popen([PHP,"-S",f"127.0.0.1:{port}","-t",str(root/"www"),str(router)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW if os.name=="nt" else 0)
 for i in range(35):
  if proc.poll() is not None:raise RuntimeError("PHP stage server exited")
  try:request(port,"/chat/");break
  except (OSError,ConnectionError):time.sleep(0.12)
 status,headers,_=request(port,"/chat/")
 check(status==403 and not any(k.lower()=="set-cookie" for k in headers),"Anonymous chat denied; no auto cookie")
 check(request(port,"/api/conversations.php")[0]==403,"Anonymous conversation API denied")
 check(request(port,"/chat/",{"action":"authorize","audience":"majid","token":"invalid"*8},form=True)[0]==403,"Invalid role invitation denied")
 cookies={}
 for role in ("majid","mira"):
  code,headers,_=request(port,"/chat/",{"action":"authorize","audience":role,"token":tokens[role]},form=True)
  cookie=headers.get("Set-Cookie","").split(";",1)[0].split("=",1)[-1]
  check(code==303 and cookie.startswith("v2."+role+"."),"Role-bound login "+role)
  cookies[role]=cookie
  check(request(port,"/chat/",cookie=cookie)[0]==200,"Authenticated chat "+role)
 checks={}
 for role in ("majid","mira"):
  code,headers,body=request(port,"/api/conversations.php",{"title":"Synthetic "+role,"audience":role},cookie=cookies[role])
  out=json.loads(body)
  check(code==201 and out.get("conversation",{}).get("audience")==role,"Server-owned conversation "+role)
  checks[role]=out["conversation"]["id"]
 code,_,body=request(port,"/api/conversations.php",{"title":"Should reject","audience":"mira"},cookie=cookies["majid"])
 check(code==403,"Majid cannot forge Mira conversation")
 for role,other in (("majid","mira"),("mira","majid")):
  code,_,body=request(port,"/api/conversations.php?limit=100",cookie=cookies[role])
  visible=json.loads(body)["items"]
  check(code==200 and len(visible)==1 and visible[0]["id"]==checks[role],"Conversation list isolation "+role)
  check(request(port,"/api/messages.php?conversation_id="+checks[other],cookie=cookies[role])[0]==403,"Cross-user messages denied "+role)
  check(request(port,"/api/memory.php?conversation_id="+checks[other],cookie=cookies[role])[0]==403,"Cross-user memory denied "+role)
  check(request(port,"/api/conversations.php",{"conversation_id":checks[other],"archived":True},cookie=cookies[role],method="PATCH")[0]==403,"Cross-user archive denied "+role)
  check(request(port,"/api/chat-stream.php",{"conversation_id":checks[other],"message":"synthetic probe"},cookie=cookies[role])[0]==403,"Cross-user stream blocked "+role)
 code,_,body=request(port,"/api/chat-stream.php",{"conversation_id":checks["majid"],"message":"test synthetic only"},cookie=cookies["majid"])
 check(code==503 and b"provider_not_configured" in body,"No unintended model call with provider unset")
 code,_,body=request(port,"/api/messages.php?conversation_id="+checks["majid"],cookie=cookies["majid"])
 check(code==200 and len(json.loads(body)["items"])<=1,"Own synthetic transcript accessible")
 code,headers,_=request(port,"/chat/",{"action":"logout"},cookie=cookies["majid"],form=True)
 check(code==303 and "Set-Cookie" in headers,"Logout invalidates cookie")
 print("STAGED_HTTP_GATE=PASS",passes)
except Exception as e:
 print("STAGED_HTTP_GATE=FAIL",type(e).__name__,str(e)[:190])
 sys.exit(1)
finally:
 if proc is not None:
  proc.terminate()
  try:proc.wait(timeout=3)
  except subprocess.TimeoutExpired:proc.kill()
 shutil.rmtree(root,ignore_errors=True)
