<?php
/** Deterministic external-data boundary fixtures; no provider calls. */
declare(strict_types=1);
require_once __DIR__ . '/../src/Infrastructure/ProviderJson.php';
require_once __DIR__ . '/../src/Infrastructure/AlphaVantageQuote.php';
use GainerInteractive\IGTradingJournal\Infrastructure\ProviderJson;
use GainerInteractive\IGTradingJournal\Infrastructure\AlphaVantageQuote;
use GainerInteractive\IGTradingJournal\Domain\Valuation;

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
