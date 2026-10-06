<?php
/**
 * Explicit weekly New York fundamental refresh clock.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Weekday slots do not claim an exchange holiday calendar. */
final class FundamentalSchedule {

	/**
	 * Return the next strictly future 7:30 PM New York slot.
	 *
	 * @param \DateTimeImmutable $now Current instant.
	 * @param string             $frequency off or weekly.
	 * @param int                $weekday ISO weekday, Monday through Friday.
	 * @return int|null UTC timestamp or null for disabled enrollment.
	 * @throws \InvalidArgumentException On invalid enrollment.
	 * @throws \LogicException On an unavailable future slot.
	 */
	public static function next( \DateTimeImmutable $now, string $frequency, int $weekday ): ?int {
		if ( ! in_array( $frequency, array( 'off', 'weekly' ), true ) || $weekday < 1 || $weekday > 5 ) {
			throw new \InvalidArgumentException( 'Invalid fundamental schedule.' );
		}
		if ( 'off' === $frequency ) {
			return null;
		}
		$local = $now->setTimezone( new \DateTimeZone( 'America/New_York' ) );
		for ( $day = 0; $day < 8; ++$day ) {
			$slot = $local->modify( '+' . $day . ' days' )->setTime( 19, 30 );
			if ( (int) $slot->format( 'N' ) === $weekday && $slot > $now ) {
				return $slot->getTimestamp();
			}
		}
		throw new \LogicException( 'No future fundamental slot found.' );
	}
}
