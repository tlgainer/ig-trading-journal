<?php
/** Server configuration only; never open a connection or use real credentials. */
require_once __DIR__.'/../src/Infrastructure/AiConnection.php';
use GainerInteractive\IGTradingJournal\Infrastructure\AiConnection;
require_once __DIR__.'/../src/Infrastructure/AiTransport.php';
test('AI sender is disabled by default without server enablement',function() {
 equal(GainerInteractive\IGTradingJournal\Infrastructure\AiTransport::enabled(),false);
});
test('OpenAI credential preparation defaults to unavailable with no key or digest exposure',function() {
 equal(AiConnection::status(),['credential_configured'=>false,'access_verified'=>false,'processing_available'=>false]);
});
test('OpenAI configuration isolates synthetic keys and rejects placeholders and header injection',function() {
 foreach([['sk-fixture-only-key',true],['YOUR_OPENAI_KEY',false],['',false],['short',false],["sk-fixture\r\nX-Other: value",false],[' sk-fixture-only-key',false],[str_repeat('a',513),false],[true,false],[null,false]] as [$key,$valid]) {
  $code='define("TGIT_OPENAI_API_KEY",'.var_export($key,true).'); require '.var_export(realpath(__DIR__.'/../src/Infrastructure/AiConnection.php'),true).'; echo json_encode('.AiConnection::class.'::status());';
  $process=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); if(!is_resource($process)) throw new RuntimeException('Configuration fixture failed');
  fclose($pipes[0]); $output=stream_get_contents($pipes[1]); fclose($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[2]); equal(proc_close($process),0); equal($errors,'');
  equal(json_decode($output,true),['credential_configured'=>$valid,'access_verified'=>false,'processing_available'=>false]);
 }
});
