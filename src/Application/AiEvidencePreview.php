<?php
/**
 * Owner-authorized saved-evidence preview, with no external processing.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Infrastructure\Database;
use GainerInteractive\IGTradingJournal\Domain\AiEvidence;

/** Preview does not constitute consent or approval and never reserves spending. */
final class AiEvidencePreview {
	/**
	 * Scoped persistence.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Explicit actor.
	 *
	 * @var int
	 */
	private int $actor;
	/**
	 * Audit context.
	 *
	 * @var string
	 */
	private string $correlation;

	/**
	 * Bind an explicit owner context.
	 *
	 * @param Database $db Persistence.
	 * @param int      $actor Actor.
	 * @param string   $correlation Context.
	 */
	public function __construct( Database $db, int $actor, string $correlation ) {
		$this->db          = $db;
		$this->actor       = $actor;
		$this->correlation = $correlation;
	}

	/**
	 * Read selected immutable snapshots and the current expected thesis revision.
	 *
	 * @param int      $workspace Workspace.
	 * @param int      $asset Asset.
	 * @param array    $snapshots Dataset to snapshot IDs.
	 * @param int|null $trade Optional trade.
	 * @param int|null $revision Expected current journal revision.
	 * @return array Bounded preview; no approval or storage side effects.
	 * @throws \InvalidArgumentException On invalid selection.
	 */
	public function preview( int $workspace, int $asset, array $snapshots, ?int $trade = null, ?int $revision = null ): array {
		$tracker = new Tracker( $this->db, $this->actor, $this->correlation );
		$tracker->authorize( $workspace, 'tgit_manage_members' );
		if ( ( null === $trade ) !== ( null === $revision ) || ( null !== $trade && ( $trade <= 0 || $revision <= 0 ) ) ) {
			throw new \InvalidArgumentException( 'Select a trade and its expected journal revision together.' );
		}
		return $this->db->atomic(
			function () use ( $tracker, $workspace, $asset, $snapshots, $trade, $revision ): array {
				$this->db->row( 'SELECT id FROM ' . $this->db->table( 'workspaces' ) . ' WHERE id = %d FOR UPDATE', array( $workspace ) );
				$tracker->authorize( $workspace, 'tgit_manage_members' );
				$identity = $this->db->object( 'assets', $workspace, $asset );
				if ( 'stock' !== $identity['asset_class'] ) {
					throw new \InvalidArgumentException( 'Saved fundamental previews require a stock asset.' );
				}
				$thesis = null;
				if ( null !== $trade ) {
					$record = $this->db->object( 'trades', $workspace, $trade );
					if ( (int) $record['asset_id'] !== $asset || (int) $record['revision'] !== $revision ) {
						throw new \UnexpectedValueException( 'Trade asset or journal revision changed; review the current journal.' );
					}
					$journal = $this->db->row( 'SELECT payload FROM ' . $this->db->table( 'trade_journals' ) . ' WHERE workspace_id = %d AND trade_id = %d AND revision = %d', array( $workspace, $trade, $revision ) );
					try {
						$payload = json_decode( $journal['payload'] ?? '', true, 64, JSON_THROW_ON_ERROR );
					} catch ( \JsonException $error ) {
						throw new \UnexpectedValueException( 'Saved journal thesis evidence is damaged.' );
					}
					$text = $payload['fields']['thesis'] ?? null;
					if ( ! is_string( $text ) || (int) ( $payload['trade']['asset_id'] ?? 0 ) !== $asset ) {
						throw new \UnexpectedValueException( 'Saved journal thesis evidence is damaged.' );
					}
					$thesis = array(
						'trade_id' => $trade,
						'revision' => $revision,
						'text'     => html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					);
				}
				$market = new MarketData( $this->db, $this->actor, $this->correlation );
				return AiEvidence::build( $identity, $market->fundamental_metrics( $workspace, $asset, $snapshots ), $thesis );
			}
		);
	}
}
