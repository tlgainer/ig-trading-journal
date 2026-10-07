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

/** Preview and immutable approvals never reserve spending or enable transport. */
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
			function () use ( $workspace, $asset, $snapshots, $trade, $revision ): array {
				return $this->capture( $workspace, $asset, $snapshots, $trade, $revision );
			}
		);
	}

	/**
	 * Capture evidence inside the caller's workspace transaction.
	 *
	 * @param int      $workspace Workspace.
	 * @param int      $asset Asset.
	 * @param array    $snapshots Selected snapshots.
	 * @param int|null $trade Selected trade.
	 * @param int|null $revision Expected revision.
	 * @throws \InvalidArgumentException On unsupported asset evidence.
	 * @throws \UnexpectedValueException On stale or damaged journal evidence.
	 * @return array
	 */
	private function capture( int $workspace, int $asset, array $snapshots, ?int $trade, ?int $revision ): array {
		$tracker = new Tracker( $this->db, $this->actor, $this->correlation );
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

	/**
	 * Approve exactly the preview fingerprint without enabling any transport.
	 *
	 * @param int      $workspace Workspace.
	 * @param int      $asset Asset.
	 * @param array    $snapshots Explicit snapshot IDs.
	 * @param string   $fingerprint Previously reviewed preview fingerprint.
	 * @param string   $key Retry identity.
	 * @param int|null $trade Optional trade.
	 * @param int|null $revision Expected current journal revision.
	 * @return array Immutable approval metadata.
	 * @throws \InvalidArgumentException On invalid approval selection.
	 */
	public function approve( int $workspace, int $asset, array $snapshots, string $fingerprint, string $key, ?int $trade = null, ?int $revision = null ): array {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) || ( null === $trade ) !== ( null === $revision ) || ( null !== $trade && ( $trade <= 0 || $revision <= 0 ) ) ) {
			throw new \InvalidArgumentException( 'Approval requires a reviewed fingerprint and valid thesis selection.' );
		}
		ksort( $snapshots, SORT_STRING );
		$input    = array(
			'actor'       => $this->actor,
			'asset'       => $asset,
			'snapshots'   => $snapshots,
			'fingerprint' => $fingerprint,
			'trade'       => $trade,
			'revision'    => $revision,
		);
		$commands = new JournalCommands( $this->db, $this->actor, $this->correlation );
		return $commands->run(
			$workspace,
			'tgit_manage_members',
			'ai.evidence.approve',
			$input,
			$key,
			function () use ( $commands, $workspace, $asset, $snapshots, $fingerprint, $trade, $revision ): array {
				$preview = $this->capture( $workspace, $asset, $snapshots, $trade, $revision );
				if ( ! hash_equals( $fingerprint, $preview['fingerprint'] ) ) {
					throw new \UnexpectedValueException( 'Evidence changed; review the current preview before approving.' );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Canonical bytes must match the pure preview fingerprint.
				$json    = json_encode( $preview['bundle'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
				$created = gmdate( 'Y-m-d H:i:s' );
				$id      = $this->db->insert(
					'ai_evidence_bundles',
					array(
						'workspace_id' => $workspace,
						'asset_id'     => $asset,
						'actor_id'     => $this->actor,
						'version'      => 'ai-evidence-1',
						'fingerprint'  => $fingerprint,
						'bundle_json'  => $json,
						'created_at'   => $created,
					)
				);
				$commands->audit( $workspace, 'ai.evidence.approved', 'ai_evidence_bundles', $id, 1 );
				return array(
					'id'          => $id,
					'asset_id'    => $asset,
					'fingerprint' => $fingerprint,
					'approved_at' => $created,
				);
			}
		);
	}

	/**
	 * Read an approved immutable bundle within the owner's workspace.
	 *
	 * @param int $workspace Workspace.
	 * @param int $id Approval identity.
	 * @return array
	 * @throws \UnexpectedValueException On damaged stored evidence.
	 */
	public function approved( int $workspace, int $id ): array {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
		$row = $this->db->object( 'ai_evidence_bundles', $workspace, $id );
		$this->db->object( 'assets', $workspace, (int) $row['asset_id'] );
		if ( 'ai-evidence-1' !== $row['version'] || ! hash_equals( $row['fingerprint'], hash( 'sha256', $row['bundle_json'] ) ) ) {
			throw new \UnexpectedValueException( 'Approved evidence is damaged or unsupported.' );
		}
		return array(
			'id'          => (int) $row['id'],
			'asset_id'    => (int) $row['asset_id'],
			'fingerprint' => $row['fingerprint'],
			'approved_at' => $row['created_at'],
			'bundle'      => json_decode( $row['bundle_json'], true, 64, JSON_THROW_ON_ERROR ),
		);
	}
}
