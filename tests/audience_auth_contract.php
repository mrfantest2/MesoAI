<?php
declare(strict_types=1);
$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'meso-audience-auth-'.bin2hex(random_bytes(6));
@mkdir($root,0700,true);
putenv('MESO_TEST_PRIVATE_ROOT='.$root);
putenv('MESO_MEMORY_ROOT='.$root.DIRECTORY_SEPARATOR.'memory');
require dirname(__DIR__).'/web/includes/chat_auth.php';
require dirname(__DIR__).'/web/includes/memory.php';

function meso_expect(bool $ok,string $label): void {
    if (!$ok) {fwrite(STDERR,"FAIL: {$label}\n");exit(1);}
}
try {
    file_put_contents($root.DIRECTORY_SEPARATOR.'chat-cookie-secret.txt',str_repeat('S',48));
    $majidToken=str_repeat('M',44);
    $miraToken=str_repeat('R',44);
    file_put_contents($root.DIRECTORY_SEPARATOR.'chat-accounts.json',json_encode([
        'accounts'=>['majid'=>hash('sha256',$majidToken),'mira'=>hash('sha256',$miraToken)],
    ]));
    $_COOKIE=[];
    $_SERVER['REQUEST_METHOD']='GET';
    $_SERVER['REQUEST_URI']='/meso/chat/';
    meso_expect(!meso_chat_is_authorized(),'Anonymous GET must not authenticate');
    meso_expect(meso_chat_current_audience()===null,'No identity without cookie');
    meso_expect(meso_chat_verify_invite('majid',$majidToken),'Majid invite');
    meso_expect(meso_chat_verify_invite('mira',$miraToken),'Mira invite');
    meso_expect(!meso_chat_verify_invite('mira',$majidToken),'Cross-role invite denied');
    meso_expect(!meso_chat_verify_invite('majid','123'),'Weak invite denied');

    $future=time()+3600;
    $majidCookie=meso_chat_cookie_value('majid',$future);
    $miraCookie=meso_chat_cookie_value('mira',$future);
    meso_expect(is_string($majidCookie) && is_string($miraCookie),'Signed role cookies generated');
    $_COOKIE[MESO_CHAT_COOKIE]=$majidCookie;
    meso_expect(meso_chat_current_audience()==='majid','Correct signed audience');
    $_COOKIE[MESO_CHAT_COOKIE]=$miraCookie;
    meso_expect(meso_chat_current_audience()==='mira','Other signed audience');
    $cfgPath=$root.DIRECTORY_SEPARATOR.'chat-accounts.json';
    $accountConfig=json_decode((string)file_get_contents($cfgPath),true);
    $accountConfig['accounts']['majid']=hash('sha256',str_repeat('Z',44));
    file_put_contents($cfgPath,json_encode($accountConfig));
    $_COOKIE[MESO_CHAT_COOKIE]=$majidCookie;
    meso_expect(meso_chat_current_audience()===null,'Account rotation revokes old cookies');
    $accountConfig['accounts']['majid']=hash('sha256',$majidToken);
    file_put_contents($cfgPath,json_encode($accountConfig));
    $_COOKIE[MESO_CHAT_COOKIE]=str_replace('mira','majid',$miraCookie);
    meso_expect(meso_chat_current_audience()===null,'Modified role rejected');
    $_COOKIE[MESO_CHAT_COOKIE]=meso_chat_cookie_value('majid',time()-5);
    meso_expect(meso_chat_current_audience()===null,'Expired cookie rejected');
    $_COOKIE[MESO_CHAT_COOKIE]='9999999999.fakelegacy';
    meso_expect(!meso_chat_is_authorized(),'Old unbound cookie rejected');

    $a=meso_memory_create_conversation('Synthetic Majid','majid');
    $b=meso_memory_create_conversation('Synthetic Mira','mira');
    $legacy=meso_memory_create_conversation('Preserved legacy',null);
    $_COOKIE[MESO_CHAT_COOKIE]=$majidCookie;
    meso_chat_require_conversation_audience($a['id']);
    foreach([$b['id'],$legacy['id']] as $id) {
        $denied=false;
        try {meso_chat_require_conversation_audience($id);}
        catch(InvalidArgumentException $e){$denied=$e->getMessage()==='conversation_access_denied';}
        meso_expect($denied,'Cross-role or legacy access denied');
    }
    meso_expect(count(meso_memory_list_conversations(false,100,'majid'))===1,'Majid list filtered');
    meso_expect(count(meso_memory_list_conversations(false,100,'mira'))===1,'Mira list filtered');
    meso_expect(meso_memory_get_conversation($legacy['id'])['audience']===null,'Legacy audience preserved');
    $user=meso_memory_add_message($a['id'],'user','Remember isolationmarker green');
    meso_memory_create_item($a['id'],$user['id'],'fact','isolationmarker green','verified','user-explicit-chat');
    meso_expect(meso_memory_context($b['id'],'isolationmarker',6)['items_used']===0,'Private recall isolation');
    meso_expect(meso_memory_context($a['id'],'isolationmarker',6)['items_used']===1,'Same-user recall');
    meso_expect(meso_chat_invite_rate_ok('192.0.2.1'),'Rate accepts first');
    for($i=0;$i<7;$i++)meso_chat_invite_rate_ok('192.0.2.1');
    meso_expect(!meso_chat_invite_rate_ok('192.0.2.1'),'Rate rejects ninth');
    echo "MESO_AUDIENCE_AUTH_CONTRACT=PASS\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}
    @rmdir($root);
}
