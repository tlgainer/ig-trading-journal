<?php
/** Immediate selection regression fixtures; no live provider access. */
declare(strict_types=1);
use GainerInteractive\IGTradingJournal\Infrastructure\TickerbotManual;

function tbs_selected($s,int $w,array $assets,string $key,?callable $http=null):array{
 $c=$s->controls($w);return TickerbotManual::selected($s,$w,$key,$c['settings_revision'],$c['selection']['revision'],$assets,$http);
}

test('Immediate single asset outside saved scope saves its mark without worker scheduling or ledger changes',function()use($db,$tracker){
 [$s,$w,$a,$account]=tbf_context();$extra=tbi_asset($w,'MSFT');$s->save_mapping($w,$extra,'MSFT',tb_row('MSFT'));$before=$tracker->list_objects($w,'transactions');$settings=$db->row('SELECT next_due_at FROM '.$db->table('tickerbot_settings').' WHERE workspace_id=%d',[$w]);$scope=$s->scope_selection($w);$count=$s->usage($w)['attempts'];$sent=[];$key=wp_generate_uuid4();$http=function($url)use(&$sent){$sent[]=$url;return tbw_response($url);};$controls=$s->controls($w);equal(TickerbotManual::selected($s,$w,$key,$controls['settings_revision'],$controls['selection']['revision'],[$extra],$http,true)['state'],'not_started');equal(count($sent),0);$r=tbs_selected($s,$w,[$extra],$key,$http);equal($r['state'],'completed');equal(count($sent),1);equal(str_ends_with($sent[0],'/tickers/MSFT'),true);equal($s->usage($w)['attempts'],$count+1);equal(isset($r['lease_token']),false);equal(wp_next_scheduled('tgit_tickerbot_batch',[$w,$r['run_id']]),false);equal($tracker->list_objects($w,'transactions'),$before);equal($s->scope_selection($w),$scope);equal($db->row('SELECT next_due_at FROM '.$db->table('tickerbot_settings').' WHERE workspace_id=%d',[$w]),$settings);equal(tbs_selected($s,$w,[$extra],$key,$http)['run_id'],$r['run_id']);equal(count($sent),1);equal(TickerbotManual::selected($s,$w,$key,$controls['settings_revision'],$controls['selection']['revision'],[$extra],$http,true)['state'],'completed');equal(count($sent),1);$diag=$s->diagnostics($w);equal($diag['run']['cause'],'selected');equal(in_array($extra,array_column($diag['marks'],'asset_id'),true),true);
});

test('Immediate group batches only selected symbols and binds UUID to the original selection',function()use($db){
 [$s,$w,$a]=tbf_context();$b=tbi_asset($w,'MSFT');$s->save_mapping($w,$b,'MSFT',tb_row('MSFT'));$c=tbi_asset($w,'NVDA');$s->save_mapping($w,$c,'NVDA',tb_row('NVDA'));$sent=[];$key=wp_generate_uuid4();$http=function($url)use(&$sent){$sent[]=$url;return tbw_response($url);};$r=tbs_selected($s,$w,[$b,$a],$key,$http);equal($r['state'],'completed');equal(count($sent),1);equal(str_ends_with($sent[0],'/tickers/AAPL,MSFT'),true);equal(tbs_selected($s,$w,[$a,$b],$key,$http)['run_id'],$r['run_id']);tbs_denied(fn()=>tbs_selected($s,$w,[$c],$key,$http));equal(count($sent),1);equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_quotes').' WHERE workspace_id=%d',[$w])),2);
});

test('Immediate selection rejects malformed IDs foreign assets unmapped identities and stale review before HTTP',function()use($db){
 [$s,$w,$a]=tbf_context();[$other,$foreign,$foreignasset]=tbf_context();$missing=tbi_asset($w,'UNMAPPED');$sent=0;$http=function()use(&$sent){++$sent;throw new RuntimeException('Unexpected HTTP');};$usage=$s->usage($w)['attempts'];foreach([[],range(1,51),[$a,$a],[(string)$a],[-1],[$foreignasset],[$missing]] as $ids){tbs_denied(fn()=>tbs_selected($s,$w,$ids,wp_generate_uuid4(),$http));} $c=$s->controls($w);$stale=false;try{TickerbotManual::selected($s,$w,wp_generate_uuid4(),$c['settings_revision']+1,$c['selection']['revision'],[$a],$http);}catch(UnexpectedValueException $e){$stale=true;}equal($stale,true);equal($sent,0);equal($s->usage($w)['attempts'],$usage);equal($db->rows('SELECT id FROM '.$db->table('tickerbot_runs').' WHERE workspace_id=%d',[$w]),[]);
});

test('Immediate refresh joins an active account operation without adding selected assets or sending',function(){
 [$s,$w,$a]=tbf_context();$run=$s->start_run($w,wp_generate_uuid4());$sent=0;$r=tbs_selected($s,$w,[$a],wp_generate_uuid4(),function()use(&$sent){++$sent;});equal($r['state'],'joined');equal($r['run_id'],$run['run_id']);equal($sent,0);$s->finish_run($w,$run['run_id'],$run['lease_token'],'failed');
});

test('Immediate transport failure counts once queues no retry and same-key outcome never resends',function(){
 [$s,$w,$a]=tbf_context();$before=$s->usage($w)['attempts'];$sent=0;$key=wp_generate_uuid4();$http=function()use(&$sent){++$sent;return ['response'=>['code'=>500],'headers'=>[],'body'=>'synthetic transient'];};$r=tbs_selected($s,$w,[$a],$key,$http);equal($r['state'],'failed');equal($sent,1);equal($s->usage($w)['attempts'],$before+1);equal(wp_next_scheduled('tgit_tickerbot_batch',[$w,$r['run_id']]),false);equal(tbs_selected($s,$w,[$a],$key,$http)['state'],'failed');equal($sent,1);equal(tbs_selected($s,$w,[$a],wp_generate_uuid4(),$http)['state'],'cooldown');equal($sent,1);
});

test('Immediate manual REST requires owner nonce reviewed IDs and preserves shared budget controls',function()use($db,$owner,$viewer){
 [$s,$w,$a]=tbf_context();$body=['settings_revision'=>1,'scope_revision'=>0,'asset_ids'=>[$a]];$r=new WP_REST_Request('POST','/tgit/v1/workspaces/'.$w.'/tickerbot/refresh-selected');$r->set_header('Content-Type','application/json');$r->set_header('Idempotency-Key',wp_generate_uuid4());$r->set_body(wp_json_encode($body));wp_set_current_user($viewer);$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));equal(rest_get_server()->dispatch($r)->get_status(),403);wp_set_current_user($owner);$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$body['asset_ids']=[];$r->set_body(wp_json_encode($body));equal(rest_get_server()->dispatch($r)->get_status(),400);$db->query('UPDATE '.$db->table('tickerbot_accounts').' SET paused_until=%s WHERE scope_digest=%s',[gmdate('Y-m-d H:i:s',time()+600),hash('sha256','tickerbot/account/'.TGIT_TICKERBOT_ACCOUNT_SCOPE)]);$sent=0;$r=tbs_selected($s,$w,[$a],wp_generate_uuid4(),function()use(&$sent){++$sent;});equal($r['state'],'paused');equal($sent,0);
});

test('Immediate refresh works with worker and scheduler explicitly disabled in a separate server process',function()use($owner){
 [$s,$w,$a]=tbf_context();$cmd=escapeshellarg(PHP_BINARY).' -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=mysqli '.escapeshellarg(__DIR__.'/tickerbot-selected-worker-off.php').' '.escapeshellarg(ABSPATH).' '.(int)$owner.' '.$w.' '.$a.' '.escapeshellarg(TGIT_TICKERBOT_ACCOUNT_SCOPE);exec($cmd,$out,$exit);equal($exit,0);equal(implode("\n",$out),'PASS Immediate refresh with worker and scheduler off.');
});
