<?php
/**
 * Owner-facing settings with explicit verified processing enablement.
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
	 * @throws \InvalidArgumentException On attempts to enable without verified server evidence.
	 */
	public static function policy( AiSpending $spending, int $workspace, ?array $input = null ): array {
		if ( null !== $input ) {
			$catalog = array();
			if ( true === ( $input['enabled'] ?? null ) ) {
				if ( ! \GainerInteractive\IGTradingJournal\Infrastructure\AiTransport::enabled() ) {
					throw new \InvalidArgumentException( 'Enablement requires a configured server key and server processing switch.' );
				}
				try {
					$evidence = \GainerInteractive\IGTradingJournal\Infrastructure\AiConfiguration::current( $input['model'] ?? '' );
					$catalog  = array( $input['model'] => $evidence['pricing'] );
				} catch ( \Throwable $error ) {
					throw new \InvalidArgumentException( 'Enablement requires current verified model, pricing and counting-cost evidence.' );
				}
			}
			$spending->configure( $workspace, $input, $catalog );
		}
		$result = $spending->status( $workspace );
		unset( $result['allowed'] );
		$result['processing_available'] = true;
		$result['readiness']            = \GainerInteractive\IGTradingJournal\Infrastructure\AiConfiguration::readiness( $result['model'] );
		return $result;
	}
}
