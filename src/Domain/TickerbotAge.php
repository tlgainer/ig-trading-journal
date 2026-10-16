<?php
/**
 * Honest elapsed source age; no market-session or realtime inference.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** A new receipt cannot refresh the underlying observation time. */
final class TickerbotAge {
	/**
	 * Label a source timestamp against twice the saved elapsed interval.
	 *
	 * @param string|null        $source UTC stored source time.
	 * @param \DateTimeImmutable $now Current instant.
	 * @param int                $interval Saved refresh seconds.
	 * @return array Diagnostic age; unknown/future never become recent.
	 */
	public static function inspect( ?string $source, \DateTimeImmutable $now, int $interval ): array {
		TickerbotSchedule::next( $now, $interval );
		$threshold = 2 * $interval;
		$empty     = array(
			'state'               => 'unknown',
			'seconds'             => null,
			'stale_after_seconds' => $threshold,
		);
		if ( null === $source ) {
			return $empty;}
		$value = strlen( $source ) === 19 ? $source . '.000000' : $source;
		$date  = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d H:i:s.u' ) !== $value ) {
			return $empty;}
		if ( $date > $now ) {
			return array_replace( $empty, array( 'state' => 'future' ) );}
		$age = $now->getTimestamp() - $date->getTimestamp();
		return array(
			'state'               => $age > $threshold ? 'stale' : 'recent',
			'seconds'             => $age,
			'stale_after_seconds' => $threshold,
		);
	}
}
