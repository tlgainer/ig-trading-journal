<?php
require_once __DIR__.'/../src/Domain/FundamentalSchedule.php';
use GainerInteractive\IGTradingJournal\Domain\FundamentalSchedule;
test('Weekly fundamentals use strict future New York slots across DST and weekday boundaries', function () {
 $next=fn($instant,$day)=>gmdate('Y-m-d H:i',FundamentalSchedule::next(new DateTimeImmutable($instant),'weekly',$day));
 equal($next('2026-10-06T12:00:00Z',2),'2026-10-06 23:30');
 equal($next('2026-10-06T23:30:00Z',2),'2026-10-13 23:30');
 equal($next('2026-10-30T23:30:00Z',1),'2026-11-03 00:30');
 equal($next('2026-03-06T12:00:00Z',1),'2026-03-09 23:30');
 equal(FundamentalSchedule::next(new DateTimeImmutable('2026-01-01'),'off',1),null);
 foreach([0,6,7] as $day) rejects(fn()=>FundamentalSchedule::next(new DateTimeImmutable(),'weekly',$day));
 rejects(fn()=>FundamentalSchedule::next(new DateTimeImmutable(),'daily',1));
});
