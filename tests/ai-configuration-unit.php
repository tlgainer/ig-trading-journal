<?php
/** Synthetic trusted setup evidence; no provider calls or credentials. */
declare(strict_types=1);
require_once __DIR__.'/../src/Infrastructure/AiConfiguration.php';
use GainerInteractive\IGTradingJournal\Infrastructure\AiConfiguration;
use GainerInteractive\IGTradingJournal\Infrastructure\AiConnection;

test('Trusted server AI configuration selects exact model without alias fallback',function() {
 $catalog=ai_catalog_evidence(); $policies=['fixture-text-model'=>count_policy()];
 $result=AiConfiguration::verified($catalog,$policies,str_repeat('a',64),'fixture-text-model',ai_now());
 equal($result,['catalog'=>$catalog,'count_policy'=>count_policy(),'pricing'=>ai_pricing()]);
 foreach(['','unknown-model','fixture-text-model-latest'] as $model) provider_setup_rejects(fn()=>AiConfiguration::verified($catalog,$policies,str_repeat('a',64),$model,ai_now()));
 $catalog['expired-unselected-model']=[]; $policies['expired-unselected-model']=[];
 equal(AiConfiguration::verified($catalog,$policies,str_repeat('a',64),'fixture-text-model',ai_now()),$result);
});

function provider_setup_rejects(callable $operation): void {
 try { $operation(); } catch(UnexpectedValueException $error) { return; }
 throw new RuntimeException('Expected unavailable server evidence.');
}

test('Trusted setup rejects rotated credentials, expired prices and counting evidence',function() {
 $catalog=ai_catalog_evidence(); $policies=['fixture-text-model'=>count_policy()];
 rejects(fn()=>AiConfiguration::verified($catalog,$policies,str_repeat('b',64),'fixture-text-model',ai_now()));
 $expired=ai_catalog_evidence(['pricing'=>ai_pricing(['valid_until'=>'2026-10-06 12:00:00'])]);
 rejects(fn()=>AiConfiguration::verified($expired,$policies,str_repeat('a',64),'fixture-text-model',ai_now()));
 foreach(['maximum_charge'=>'0.01','access_confirmed'=>false,'model'=>'different-model','valid_until'=>'2026-10-06 12:00:00'] as $field=>$value) {
  $changed=['fixture-text-model'=>array_replace(count_policy(),[$field=>$value])];
  rejects(fn()=>AiConfiguration::verified($catalog,$changed,str_repeat('a',64),'fixture-text-model',ai_now()));
 }
 provider_setup_rejects(fn()=>AiConfiguration::verified($catalog,[],str_repeat('a',64),'fixture-text-model',ai_now()));
});

test('Missing server configuration enables no AI model or network operation',function() {
 provider_setup_rejects(fn()=>AiConfiguration::current('fixture-text-model'));
 equal(AiConnection::status()['processing_available'],false);
 equal(AiConfiguration::readiness('fixture-text-model'),['credential_configured'=>false,'model_evidence_current'=>false,'count_evidence_current'=>false,'server_enabled'=>false,'workflow_available'=>true]);
});

test('Server constants require dated evidence for the actual current synthetic key',function() {
 $now=new DateTimeImmutable('now',new DateTimeZone('UTC')); $dates=['verified_at'=>$now->format('Y-m-d H:i:s'),'valid_until'=>$now->modify('+1 day')->format('Y-m-d H:i:s')];
 $credential=hash('sha256','sk-fixture-only-key');
 $catalog=ai_catalog_evidence(['credential_fingerprint'=>$credential,'access_verified_at'=>$dates['verified_at'],'pricing'=>ai_pricing($dates)]);
 $policies=['fixture-text-model'=>array_replace(count_policy($credential),$dates)];
 foreach([['sk-fixture-only-key',$catalog,$policies,'fixture-text-model'],['sk-rotated-fixture-key',$catalog,$policies,'unavailable'],['sk-fixture-only-key',null,$policies,'unavailable'],['sk-fixture-only-key',$catalog,[], 'unavailable']] as [$key,$models,$counts,$expected]) {
  $code=''; foreach(['Domain/Decimal','Domain/AiBudget','Domain/AiModelCatalog','Domain/AiCountPolicy','Infrastructure/AiConnection','Infrastructure/AiConfiguration'] as $file) $code.='require '.var_export(realpath(__DIR__.'/../src/'.$file.'.php'),true).';';
  $code.='define("TGIT_OPENAI_API_KEY",'.var_export($key,true).');define("TGIT_OPENAI_MODEL_EVIDENCE",'.var_export($models,true).');define("TGIT_OPENAI_COUNT_EVIDENCE",'.var_export($counts,true).');try { $result='.AiConfiguration::class.'::current("fixture-text-model");$state=$result["pricing"]["model"]; } catch(Throwable $error) { $state="unavailable"; } echo json_encode(["state"=>$state,"readiness"=>'.AiConfiguration::class.'::readiness("fixture-text-model")]);';
  $process=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); if(!is_resource($process)) throw new RuntimeException('Setup fixture failed');
  fclose($pipes[0]); $output=stream_get_contents($pipes[1]); fclose($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[2]); equal(proc_close($process),0); equal($errors,''); $decoded=json_decode($output,true); equal($decoded['state'],$expected);
  equal($decoded['readiness'],['credential_configured'=>true,'model_evidence_current'=>$key==='sk-fixture-only-key' && is_array($models),'count_evidence_current'=>$key==='sk-fixture-only-key' && count($counts)>0,'server_enabled'=>false,'workflow_available'=>true]); equal(str_contains($output,$credential),false); equal(str_contains($output,$key),false);
 }
});
