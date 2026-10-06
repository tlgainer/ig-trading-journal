<?php
/** Deterministic external-data boundary fixtures; no provider calls. */
declare(strict_types=1);
require_once __DIR__ . '/../src/Infrastructure/ProviderJson.php';
require_once __DIR__ . '/../src/Infrastructure/AlphaVantageQuote.php';
require_once __DIR__ . '/../src/Infrastructure/FmpEodQuote.php';
require_once __DIR__ . '/fundamentals-unit.php';
require_once __DIR__ . '/fundamental-metrics-unit.php';
require_once __DIR__ . '/ai-budget-unit.php';
use GainerInteractive\IGTradingJournal\Infrastructure\FmpEodQuote;
use GainerInteractive\IGTradingJournal\Infrastructure\ProviderJson;
use GainerInteractive\IGTradingJournal\Infrastructure\AlphaVantageQuote;
use GainerInteractive\IGTradingJournal\Domain\Valuation;

test('FMP daily history preserves numeric precision and selects distinct sessions', function () {
 $body = '[{"symbol":"FIXTURE","date":"2026-10-01","price":315},{"symbol":"FIXTURE","date":"2026-10-02","price":320.123456789123456789},{"symbol":"FIXTURE","date":"2026-10-02","price":"320.123456789123456789"}]';
 $quote = FmpEodQuote::parse($body, 'FIXTURE'); equal($quote['price'],'320.123456789123456789'); equal($quote['previous_close'],'315'); equal($quote['session_date'],'2026-10-02'); equal($quote['currency'],null); equal($quote['exchange'],null);
 rejects(fn() => FmpEodQuote::parse($body,'OTHER'));
});

test('FMP rejects errors, absent prior sessions and conflicting daily evidence', function () {
 foreach (['{"Error Message":"sensitive-request-value"}', '[]', '[{"symbol":"FIXTURE","date":"2026-10-02","price":320}]'] as $body) rejects(fn() => FmpEodQuote::parse($body,'FIXTURE'));
 $row = ['symbol'=>'FIXTURE','date'=>'2026-10-02','price'=>'320'];
 foreach ([['date'=>'2026-02-30'],['symbol'=>'OTHER'],['price'=>'0'],['price'=>'None'],['price'=>'321']] as $change) rejects(fn() => FmpEodQuote::parse(json_encode([$row,array_replace($row,$change)],JSON_THROW_ON_ERROR),'FIXTURE'));
 rejects(fn() => FmpEodQuote::parse(json_encode(array_fill(0,501,$row),JSON_THROW_ON_ERROR),'FIXTURE'));
});

test('Provider JSON preserves exact tokens, exponents, strings and nested data', function () {
 $data = ProviderJson::decode('{"price":0.123456789123456789,"large":123456789123456789123,"small":1.234e-8,"signed":-2E+3,"text":"price 1e3 \\"quoted\\"","rows":[0,true,null]}');
 equal($data['price'], '0.123456789123456789'); equal($data['large'], '123456789123456789123'); equal($data['small'], '0.00000001234'); equal($data['signed'], '-2000'); equal($data['text'], 'price 1e3 "quoted"'); equal($data['rows'], ['0', true, null]);
 equal(ProviderJson::number('0.001e2'), '0.1');
});

test('Provider JSON rejects malformed and unbounded data without leaking payloads', function () {
 foreach (['{1:2}', '{"n":01}', '{"n":1.}', '{"n":1e}', '{"n":NaN}', '{"n":1e9999}', 'null', '[1,', str_repeat(' ',2097153)] as $body) rejects(fn() => ProviderJson::decode($body));
 rejects(fn() => ProviderJson::number('INF')); rejects(fn() => ProviderJson::number('1e-257'));
});

function quote_fixture(array $replace = []): string {
 return json_encode(['Global Quote' => array_replace(['01. symbol'=>'FIXTURE','05. price'=>'320.123456789123456789','07. latest trading day'=>'2026-10-02','08. previous close'=>'315.0000'], $replace)], JSON_THROW_ON_ERROR);
}

test('Alpha Vantage quote preserves exact prices and does not invent identity metadata', function () {
 $quote = AlphaVantageQuote::parse(quote_fixture(), 'FIXTURE'); equal($quote['price'], '320.123456789123456789'); equal($quote['previous_close'], '315.0000'); equal($quote['freshness'], 'end_of_day'); equal($quote['currency'], null); equal($quote['exchange'], null);
 rejects(fn() => AlphaVantageQuote::parse(quote_fixture(), 'OTHER'));
 foreach (['05. price'=>'0','08. previous close'=>'None','07. latest trading day'=>'2026-02-30'] as $key=>$value) rejects(fn() => AlphaVantageQuote::parse(quote_fixture([$key=>$value]),'FIXTURE'));
});

test('Alpha Vantage limit and entitlement payloads cannot masquerade as quotes', function () {
 foreach (['Note','Information','Error Message'] as $key) {
  try { AlphaVantageQuote::parse(json_encode([$key=>'sensitive-request-value'], JSON_THROW_ON_ERROR), 'FIXTURE'); throw new RuntimeException('Expected error'); }
  catch (InvalidArgumentException $error) { equal(str_contains($error->getMessage(),'sensitive-request-value'), false); }
 }
 rejects(fn() => AlphaVantageQuote::parse('{"Global Quote":{}}','FIXTURE'));
});

test('Fixed holding daily movement is distinct from unrealized gain and preserves missing inputs', function () {
 $movement = Valuation::price_movement('30', '320', '315'); decimal($movement['per_unit'], '5'); decimal($movement['amount'], '150'); decimal(Valuation::position('30','320','9000')['unrealized_gain'], '600');
 decimal(Valuation::price_movement('0.123456789123456789','0.3','0.1')['amount'],'0.0246913578246913578');
 decimal(Valuation::price_movement('10','90','100')['percent'],'-10');
 equal(Valuation::price_movement('30',null,'315')['amount'],null); equal(Valuation::price_movement('30','320',null)['percent'],null); equal(Valuation::price_movement('30','320','0')['percent'],null);
 rejects(fn() => Valuation::price_movement('-1','320','315'));
});

require_once __DIR__.'/../src/Domain/QuoteSchedule.php';
use GainerInteractive\IGTradingJournal\Domain\QuoteSchedule;

test('Weekday quote slots preserve New York DST and skip weekends', function () {
 equal(QuoteSchedule::next(new DateTimeImmutable('2026-03-06T23:30:00Z'),'once'), (new DateTimeImmutable('2026-03-09T22:30:00Z'))->getTimestamp());
 equal(QuoteSchedule::next(new DateTimeImmutable('2026-10-30T22:30:00Z'),'once'), (new DateTimeImmutable('2026-11-02T23:30:00Z'))->getTimestamp());
 equal(QuoteSchedule::next(new DateTimeImmutable('2026-10-06T22:30:00Z'),'twice'), (new DateTimeImmutable('2026-10-07T02:30:00Z'))->getTimestamp());
 equal(QuoteSchedule::next(new DateTimeImmutable('2026-10-06T22:29:59Z'),'once'), (new DateTimeImmutable('2026-10-06T22:30:00Z'))->getTimestamp());
});

test('Weekday schedule disables explicitly and rejects unrecognized frequency', function () {
 equal(QuoteSchedule::next(new DateTimeImmutable('2026-10-06T00:00:00Z'),'off'),null); rejects(fn()=>QuoteSchedule::next(new DateTimeImmutable('2026-10-06T00:00:00Z'),'hourly'));
});

require_once __DIR__.'/../src/Domain/StockTotals.php';
use GainerInteractive\IGTradingJournal\Domain\StockTotals;

test('Stock totals separate currencies and retain exact coverage subtotals', function () {
 $row=['asset_class'=>'stock','quantity'=>'1','currency'=>'USD','price_status'=>'complete','market_value'=>'0.123456789123456789','unrealized_gain'=>'-0.1'];
 $rows=StockTotals::calculate([$row,array_replace($row,['market_value'=>null,'unrealized_gain'=>null,'price_status'=>'missing']),array_replace($row,['currency'=>'EUR','market_value'=>'20','unrealized_gain'=>'5']),array_replace($row,['asset_class'=>'crypto']),array_replace($row,['quantity'=>'0'])]);
 equal(count($rows),2); equal($rows[0]['currency'],'EUR'); decimal($rows[0]['market_value'],'20'); equal($rows[1]['market_value'],null); equal($rows[1]['unrealized_gain'],null); decimal($rows[1]['covered_market_value'],'0.123456789123456789'); decimal($rows[1]['covered_unrealized_gain'],'-0.1'); equal($rows[1]['priced_positions'],1); equal($rows[1]['positions'],2);
});

test('Stock totals exclude stale values and preserve unknown basis and documented zero', function () {
 $row=['asset_class'=>'stock','quantity'=>'1','currency'=>'USD','price_status'=>'stale','market_value'=>'100','unrealized_gain'=>'50'];
 $group=StockTotals::calculate([$row])[0]; equal($group['covered_market_value'],null); equal($group['covered_unrealized_gain'],null);
 $group=StockTotals::calculate([array_replace($row,['price_status'=>'complete','unrealized_gain'=>null])])[0]; decimal($group['market_value'],'100'); equal($group['unrealized_gain'],null);
 $group=StockTotals::calculate([array_replace($row,['price_status'=>'complete','market_value'=>'0','unrealized_gain'=>'-50'])])[0]; decimal($group['market_value'],'0'); decimal($group['unrealized_gain'],'-50'); equal($group['status'],'complete');
});

test('Mixed price sessions cannot present a complete combined stock total', function () {
 $row=['asset_class'=>'stock','quantity'=>'1','currency'=>'USD','price_status'=>'complete','market_value'=>'100','unrealized_gain'=>'20','price_observation'=>['effective_date'=>'2026-10-05']];
 $group=StockTotals::calculate([$row,array_replace($row,['price_observation'=>['effective_date'=>'2026-10-06']])])[0]; equal($group['market_value'],null); equal($group['status'],'partial'); decimal($group['covered_market_value'],'200'); equal($group['session_dates'],['2026-10-05','2026-10-06']);
});
