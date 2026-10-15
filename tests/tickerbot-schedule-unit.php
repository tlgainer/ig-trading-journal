<?php
/** Pure elapsed UTC scheduling policy fixtures. */
declare(strict_types=1);
require_once __DIR__.'/../src/Domain/TickerbotSchedule.php';
use GainerInteractive\IGTradingJournal\Domain\TickerbotSchedule;

test('Tickerbot UTC cadence crosses DST without a skipped or doubled elapsed interval',function(){
 equal(TickerbotSchedule::next(new DateTimeImmutable('2026-11-01T01:30:00-04:00'),7200),'2026-11-01 07:30:00');
 equal(TickerbotSchedule::next(new DateTimeImmutable('2026-03-08T01:30:00-05:00'),3600),'2026-03-08 07:30:00');
 rejects(fn()=>TickerbotSchedule::next(new DateTimeImmutable(),60));
});
test('Tickerbot projects deduplicated batches and rejects unsupported policy',function(){
 equal(TickerbotSchedule::projected(50),372);equal(TickerbotSchedule::projected(51),744);equal(TickerbotSchedule::projected(500),3720);equal(TickerbotSchedule::projected(500,3600),7440);equal(TickerbotSchedule::projected(0),0);
 rejects(fn()=>TickerbotSchedule::projected(-1));rejects(fn()=>TickerbotSchedule::projected(10001));
});
