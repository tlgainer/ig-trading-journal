<?php
/**
 * Owner-facing AI settings; external processing remains unavailable.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

/** Safe public settings projection over shared spending history. */
final class AiSettings {
	/**
	 * Read or save settings without returning internal pricing or identities.
	 *
	 * @param AiSpending $spending Authorized spending service.
	 * @param int        $workspace Current workspace.
	 * @param array|null $input Optional policy update.
	 * @return array
	 * @throws \InvalidArgumentException On attempts to enable unfinished transport.
	 */
	public static function policy( AiSpending $spending, int $workspace, ?array $input = null ): array {
		if ( null !== $input ) {
			if ( ( $input['enabled'] ?? null ) !== false ) {
				throw new \InvalidArgumentException( 'AI summary processing is not available yet. Save settings with processing disabled.' );
			}
			$spending->configure( $workspace, $input, array() );
		}
		$result = $spending->status( $workspace );
		unset( $result['allowed'] );
		$result['processing_available'] = false;
		return $result;
	}
}
