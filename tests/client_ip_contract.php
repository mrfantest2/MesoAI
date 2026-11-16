<?php
declare(strict_types=1);
// Contract: rate-limit client identity must be the real visitor behind the loopback Cloudflare tunnel, and must not be spoofable by direct peers.
require dirname(__DIR__) . '/web/includes/chat_auth.php';
$fail=0; function t(bool $c,string $l){global $fail;echo($c?'PASS ':'FAIL ').$l,PHP_EOL;if(!$c)$fail++;}
t(meso_chat_client_ip(['REMOTE_ADDR'=>'127.0.0.1','HTTP_CF_CONNECTING_IP'=>'203.0.113.9'])==='203.0.113.9','loopback peer: uses CF-Connecting-IP');
t(meso_chat_client_ip(['REMOTE_ADDR'=>'::1','HTTP_CF_CONNECTING_IP'=>'2001:db8::7'])==='2001:db8::7','IPv6 loopback peer: uses CF-Connecting-IP');
t(meso_chat_client_ip(['REMOTE_ADDR'=>'198.51.100.4','HTTP_CF_CONNECTING_IP'=>'203.0.113.9'])==='198.51.100.4','non-loopback peer cannot spoof header');
t(meso_chat_client_ip(['REMOTE_ADDR'=>'127.0.0.1','HTTP_CF_CONNECTING_IP'=>'not-an-ip'])==='127.0.0.1','invalid header ignored');
t(meso_chat_client_ip(['REMOTE_ADDR'=>'127.0.0.1'])==='127.0.0.1','no header: falls back to peer');
t(meso_chat_client_ip([])==='unknown','empty server: unknown');
echo 'MESO_CLIENT_IP_CONTRACT=',$fail?'FAIL':'PASS',PHP_EOL; exit($fail?1:0);
