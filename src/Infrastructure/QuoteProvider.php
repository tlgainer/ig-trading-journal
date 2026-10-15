<?php
/**
 * Small quote boundary; authorization/persistence belong to the calling service.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

/** Provider operations never accept user notes, quantities or account identifiers. */
interface QuoteProvider {
	/**
	 * Supported operations.
	 *
	 * @return array
	 */
	public function capabilities(): array;
	/**
	 * Credential/endpoint result, without changing active provider.
	 *
	 * @return array
	 */
	public function test_connection(): array;
	/**
	 * Check one explicit instrument candidate without fuzzy substitution.
	 *
	 * @param array  $asset Local asset identity only.
	 * @param string $symbol Explicit canonical candidate.
	 * @return array Validated resolution or issue.
	 */
	public function resolve( array $asset, string $symbol ): array;
	/**
	 * Inspect one canonical instrument's published coverage.
	 *
	 * @param string $symbol Canonical symbol.
	 * @return array Safe coverage result.
	 */
	public function coverage( string $symbol ): array;

	/**
	 * Fetch admitted batches and retain independent per-asset outcomes.
	 *
	 * @param array $mappings Validated local identity mappings.
	 * @return array Per-asset accepted quotes/issues.
	 */
	public function quotes( array $mappings ): array;
}
