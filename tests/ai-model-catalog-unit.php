<?php
/** Synthetic credential-bound catalog evidence; no external requests. */
declare(strict_types=1);
require_once __DIR__.'/../src/Domain/AiModelCatalog.php';
use GainerInteractive\IGTradingJournal\Domain\AiModelCatalog;

function ai_catalog_evidence(array $changes=[]): array {
 return ['fixture-text-model'=>array_replace(['pricing'=>ai_pricing(),'credential_fingerprint'=>str_repeat('a',64),'access_verified_at'=>'2026-10-01 00:00:00'],$changes)];
}

test('AI catalog binds exact verified model prices to the current credential',function() {
 equal(AiModelCatalog::verified(ai_catalog_evidence(),str_repeat('a',64),ai_now()),['fixture-text-model'=>ai_pricing()]);
 equal(AiModelCatalog::verified([],str_repeat('a',64),ai_now()),[]);
 rejects(fn()=>AiModelCatalog::verified(ai_catalog_evidence(),str_repeat('b',64),ai_now()));
 rejects(fn()=>AiModelCatalog::verified(ai_catalog_evidence(),'not-a-digest',ai_now()));
 rejects(fn()=>AiModelCatalog::verified(['other-model'=>ai_catalog_evidence()['fixture-text-model']],str_repeat('a',64),ai_now()));
});

test('AI catalog rejects missing, expired, future and malformed access evidence',function() {
 foreach(['2026-09-06 12:00:00','2026-10-07 00:00:00','2026-02-30 00:00:00','invalid',null] as $date) rejects(fn()=>AiModelCatalog::verified(ai_catalog_evidence(['access_verified_at'=>$date]),str_repeat('a',64),ai_now()));
 $entry=ai_catalog_evidence(); unset($entry['fixture-text-model']['access_verified_at']); rejects(fn()=>AiModelCatalog::verified($entry,str_repeat('a',64),ai_now()));
 rejects(fn()=>AiModelCatalog::verified(ai_catalog_evidence(['untrusted'=>'extra']),str_repeat('a',64),ai_now()));
 rejects(fn()=>AiModelCatalog::verified(ai_catalog_evidence(['pricing'=>ai_pricing(['access_confirmed'=>false])]),str_repeat('a',64),ai_now()));
 rejects(fn()=>AiModelCatalog::verified(ai_catalog_evidence(['pricing'=>ai_pricing(['valid_until'=>'2026-10-06 12:00:00'])]),str_repeat('a',64),ai_now()));
});
