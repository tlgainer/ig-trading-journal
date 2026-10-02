<?php
/** Sanitized accounting and permission fixtures. Run: php tests/run.php */
declare(strict_types=1);
require_once __DIR__ . '/../src/Domain/Decimal.php';
require_once __DIR__ . '/../src/Domain/Ledger.php';
require_once __DIR__ . '/../src/Application/Access.php';
use GainerInteractive\IGTradingJournal\Domain\Decimal as D;
use GainerInteractive\IGTradingJournal\Domain\Ledger;
use GainerInteractive\IGTradingJournal\Application\Access;

if (!extension_loaded('bcmath')) { fwrite(STDERR, "BCMath is required.\n"); exit(1); }
$passed = 0;
function test(string $name, callable $callback): void {
 global $passed;
 try { $callback(); ++$passed; echo "PASS $name\n"; }
 catch (Throwable $error) { fwrite(STDERR, "FAIL $name: {$error->getMessage()}\n"); exit(1); }
}
function equal($actual, $expected): void { if ($actual !== $expected) { throw new RuntimeException(var_export($actual, true) . ' != ' . var_export($expected, true)); } }
function decimal(string $actual, string $expected): void { equal(D::compare($actual, $expected), 0); }
function rejects(callable $callback): void {
 try { $callback(); } catch (InvalidArgumentException $error) { return; }
 throw new RuntimeException('Expected validation failure.');
}
function lot(int $id, string $units, string $basis): array { return ['id' => $id, 'quantity_remaining' => $units, 'basis_remaining' => $basis]; }

test('AC 01 buy basis includes fee; partial sale allocates FIFO basis', function () {
 $buy = Ledger::buy('10', '100', '5'); decimal($buy['basis'], '1005'); decimal($buy['cash_delta'], '-1005');
 $sale = Ledger::sell([lot(1, '10', $buy['basis'])], '4', '120', '2');
 decimal($sale['realized_gain'], '76'); decimal($sale['cash_delta'], '478');
 decimal($sale['allocations'][0]['quantity_remaining'], '6'); decimal($sale['allocations'][0]['basis_remaining'], '603');
});
test('AC 03 multiple FIFO lots and partial second lot', function () {
 $sale = Ledger::sell([lot(1, '2', '20'), lot(2, '3', '60')], '3', '30', '3');
 equal(array_column($sale['allocations'], 'lot_id'), [1, 2]);
 decimal($sale['realized_gain'], '47'); decimal($sale['allocations'][1]['quantity_remaining'], '2'); decimal($sale['allocations'][1]['basis_remaining'], '40');
});
test('Full disposal consumes residual basis exactly after repeated partial sales', function () {
 $current = lot(1, '3', '1'); $cost = '0';
 for ($i = 0; $i < 3; ++$i) {
  $allocation = Ledger::sell([$current], '1', '2', '0')['allocations'][0];
  $cost = D::add($cost, $allocation['basis_native']);
  $current = lot(1, $allocation['quantity_remaining'], $allocation['basis_remaining']);
 }
 decimal($cost, '1'); decimal($current['quantity_remaining'], '0'); decimal($current['basis_remaining'], '0');
});
test('Proceeds allocation conserves total with rounding', function () {
 $sale = Ledger::sell([lot(1, '1', '1'), lot(2, '1', '1'), lot(3, '1', '1')], '3', '2', '0.000000000001');
 $total = '0'; foreach ($sale['allocations'] as $a) $total = D::add($total, $a['proceeds']);
 decimal($total, $sale['cash_delta']);
});
test('Crypto 18-place quantities remain exact', function () {
 $quantity = '0.001492537313432836'; $buy = Ledger::buy($quantity, '67000', '0');
 decimal($buy['basis'], '100');
 $sale = Ledger::sell([lot(1, $quantity, $buy['basis'])], $quantity, '68575', '0');
 decimal($sale['realized_gain'], '2.350746268657');
});
test('Oversell is rejected', fn() => rejects(fn() => Ledger::sell([lot(1, '1', '100')], '2', '100', '0')));
test('Empty position is rejected', fn() => rejects(fn() => Ledger::sell([], '1', '100', '0')));
test('Sale fee cannot exceed gross proceeds', fn() => rejects(fn() => Ledger::sell([lot(1, '1', '1')], '1', '1', '2')));
test('Half-up monetary rounding is symmetric', function () { equal(D::round('1.005', 2), '1.01'); equal(D::round('-1.005', 2), '-1.01'); });
test('Small decimal arithmetic avoids floating point', fn() => decimal(D::add('0.1', '0.2'), '0.3'));
test('Zero denominator rejected', fn() => rejects(fn() => D::div('1', '0')));
foreach (['-1', '1e3', 'NaN', '01', ' 1', '1.', '0.0000000000000000001', '100000000000000000000'] as $input) {
 test('Invalid decimal rejected: ' . $input, fn() => rejects(fn() => D::input($input)));
}
test('JSON number rejected as decimal', fn() => rejects(fn() => D::input(1.5)));
test('Zero quantity rejected', fn() => rejects(fn() => Ledger::buy('0', '1', '0')));
test('Zero price rejected', fn() => rejects(fn() => Ledger::buy('1', '0', '0')));
test('Negative fees rejected', fn() => rejects(fn() => Ledger::buy('1', '1', '-1')));
test('Multiplication overflow rejected before persistence', fn() => rejects(fn() => Ledger::buy('99999999999999999999', '99999999999999999999', '0')));
test('Purchase below monetary precision rejected', fn() => rejects(fn() => Ledger::buy('0.000000000000000001', '0.000000000000000001', '0')));
test('No membership cannot view or post, including site operators', function () { equal(Access::allows(null, 'tgit_view'), false); equal(Access::allows(null, 'tgit_post'), false); });
test('Viewer can read but cannot post or change members', function () {
 $m = ['role' => 'viewer', 'state' => 'active']; equal(Access::allows($m, 'tgit_view'), true); equal(Access::allows($m, 'tgit_post'), false); equal(Access::allows($m, 'tgit_manage_members'), false);
});
test('Contributor draft permission never grants posting', function () {
 $m = ['role' => 'contributor', 'state' => 'active']; equal(Access::allows($m, 'tgit_create_draft'), true); equal(Access::allows($m, 'tgit_post'), false);
});
test('Manager cannot change membership', function () {
 $m = ['role' => 'manager', 'state' => 'active']; equal(Access::allows($m, 'tgit_post'), true); equal(Access::allows($m, 'tgit_manage_members'), false);
});
test('Revoked owner denied every grant', function () {
 $m = ['role' => 'owner', 'state' => 'revoked']; foreach (['tgit_view', 'tgit_post', 'tgit_manage_members'] as $cap) equal(Access::allows($m, $cap), false);
});
test('Unknown role and capability denied', function () { equal(Access::allows(['role' => 'admin', 'state' => 'active'], 'tgit_view'), false); equal(Access::allows(['role' => 'owner', 'state' => 'active'], 'tgit_export'), false); });
echo "$passed tests passed.\n";
