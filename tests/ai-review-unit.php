<?php
/** Synthetic structured AI output; no transport or production data. */
declare(strict_types=1);
require_once __DIR__.'/../src/Domain/AiReview.php';
use GainerInteractive\IGTradingJournal\Domain\AiReview;
function review_response(array $ids=[1,2]): array {
 return ['response_id'=>'fixture_response_1','model'=>'fixture-text-model','summary'=>'Synthetic saved metrics need interpretation.','findings'=>[['text'=>'Coverage is limited to selected saved statements.','source_ids'=>$ids]]];
}
function review_bundle(): array { return GainerInteractive\IGTradingJournal\Domain\AiEvidence::build(ai_evidence_asset(),ai_evidence_metrics())['bundle']; }
test('AI reviews canonicalize citation order and preserve untrusted UTF-8 text without financial arithmetic',function() {
 $bundle=review_bundle(); $first=AiReview::build(review_response(),$bundle,'fixture-text-model'); equal(AiReview::build(review_response([2,1]),$bundle,'fixture-text-model'),$first);
 equal($first['output']['version'],'ai-review-1'); equal(in_array('ai_generated_not_verified_facts',$first['output']['limitations'],true),true);
 $response=review_response(); $response['summary']='Untrusted <script>text</script> – retained for escaped rendering'; equal(AiReview::build($response,$bundle,'fixture-text-model')['output']['summary'],$response['summary']);
 equal($first['fingerprint'],hash('sha256',json_encode($first['output'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)));
});
test('AI reviews reject missing fields, wrong models, unknown citations and unsupported findings',function() {
 $bundle=review_bundle(); $response=review_response();
 foreach([['model'=>'wrong-model'],['response_id'=>'bad id'],['response_id'=>null],['summary'=>1],['summary'=>' '],['summary'=>"bad\0text"],['summary'=>"\xff"],['findings'=>[]],['unknown'=>'extra']] as $change) rejects(fn()=>AiReview::build(array_replace($response,$change),$bundle,'fixture-text-model'));
 foreach([[999],[1,1],['1'],[],[1.0]] as $ids) rejects(fn()=>AiReview::build(review_response($ids),$bundle,'fixture-text-model'));
 $response['findings'][0]['url']='https://example.invalid'; rejects(fn()=>AiReview::build($response,$bundle,'fixture-text-model'));
 $response=review_response(); unset($response['summary']); rejects(fn()=>AiReview::build($response,$bundle,'fixture-text-model'));
});
test('AI review text and finding bounds reject oversized output',function() {
 $bundle=review_bundle(); $response=review_response(); $response['summary']=str_repeat('é',2001); rejects(fn()=>AiReview::build($response,$bundle,'fixture-text-model'));
 $response=review_response(); $response['findings'][0]['text']=str_repeat('x',2001); rejects(fn()=>AiReview::build($response,$bundle,'fixture-text-model'));
 $response=review_response(); $response['findings']=array_fill(0,13,$response['findings'][0]); rejects(fn()=>AiReview::build($response,$bundle,'fixture-text-model'));
 $response=review_response(); $response['findings']=array_fill(0,12,['text'=>str_repeat('"',2000),'source_ids'=>[1]]); rejects(fn()=>AiReview::build($response,$bundle,'fixture-text-model'));
});
