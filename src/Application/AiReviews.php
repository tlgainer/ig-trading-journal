<?php
/**
 * Immutable review persistence; no AI transport or spending mutations.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Infrastructure\Database;
use GainerInteractive\IGTradingJournal\Domain\AiReview;

/** Server-only writes; owner-scoped integrity-checked history. */
final class AiReviews {
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
	 * Bind the explicit owner context.
	 *
	 * @param Database $db Database.
	 * @param int      $actor Actor.
	 * @param string   $correlation Audit context.
	 */
	public function __construct( Database $db, int $actor, string $correlation ) {
		$this->db          = $db;
		$this->actor       = $actor;
		$this->correlation = $correlation;
	}

	/**
	 * Persist a normalized, verified server result for one settled request.
	 *
	 * @param int   $workspace Workspace.
	 * @param int   $approval Approved evidence identity.
	 * @param int   $request Settled request identity.
	 * @param array $response Normalized server response, never client supplied.
	 * @return array Immutable metadata, without private text or credential digests.
	 */
	public function save( int $workspace, int $approval, int $request, array $response ): array {
		list($evidence, $spending) = $this->context( $workspace, $approval, $request, true );
		$review                    = AiReview::build( $response, $evidence['bundle'], $spending['model'] );
		$commands                  = new JournalCommands( $this->db, $this->actor, $this->correlation );
		return $commands->run(
			$workspace,
			'tgit_manage_members',
			'ai.review.save',
			array(
				'actor'       => $this->actor,
				'approval'    => $approval,
				'request'     => $request,
				'fingerprint' => $review['fingerprint'],
			),
			'review-request-' . $request,
			function () use ( $commands, $workspace, $approval, $request, $review ): array {
				// Read terminal immutable facts inside the command transaction; never nest budget transactions.
				list($evidence) = $this->context( $workspace, $approval, $request, true );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Canonical bytes must match the pure validator.
				$json = json_encode( $review['output'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
				$id   = $this->db->insert(
					'ai_reviews',
					array(
						'workspace_id'         => $workspace,
						'asset_id'             => $evidence['asset_id'],
						'approval_id'          => $approval,
						'request_id'           => $request,
						'actor_id'             => $this->actor,
						'evidence_fingerprint' => $evidence['fingerprint'],
						'output_fingerprint'   => $review['fingerprint'],
						'output_json'          => $json,
						'created_at'           => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$commands->audit( $workspace, 'ai.review.saved', 'ai_reviews', $id, 1 );
				return $this->metadata( $this->db->object( 'ai_reviews', $workspace, $id ) );
			}
		);
	}


	/**
	 * Publish only the immutable completed receipt of a settled bound request.
	 *
	 * @param int $workspace Workspace.
	 * @param int $request Request.
	 * @return array Immutable review metadata.
	 * @throws \UnexpectedValueException On unavailable, quarantined or overrun output.
	 */
	public function publish_received( int $workspace, int $request ): array {
		$spending = new AiSpending( $this->db, $this->actor, $this->correlation );
		$row      = $spending->request( $workspace, $request );
		$receipt  = $spending->received( $workspace, $request );
		if ( 'settled' !== $row['state'] || null === $receipt || 'completed' !== ( $receipt['result']['reason'] ?? null ) || ! is_array( $receipt['result']['review'] ?? null ) ) {
			throw new \UnexpectedValueException( 'AI response cannot be published before verified settlement and complete output.' );
		}
		return $this->save( $workspace, $receipt['approval_id'], $request, $receipt['result']['review'] );
	}

	/**
	 * Project saved publication identity and original-owner eligibility.
	 *
	 * @param int $workspace Workspace.
	 * @param int $request Request.
	 * @return array Flags only, never private receipt output.
	 */
	public function publication_status( int $workspace, int $request ): array {
		$spending = new AiSpending( $this->db, $this->actor, $this->correlation );
		$row      = $spending->request( $workspace, $request );
		$prior    = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'ai_reviews' ) . ' WHERE workspace_id = %d AND request_id = %d', array( $workspace, $request ) );
		$result   = array(
			'can_publish' => false,
			'review_id'   => null,
		);
		if ( $prior ) {
			$review              = $this->read( $workspace, (int) $prior['id'] );
			$result['review_id'] = $review['id'];
			return $result;
		}
		if ( 'settled' !== $row['state'] || ! $row['approval_id'] || (int) $row['actor_id'] !== $this->actor ) {
			return $result;
		}
		try {
			$receipt = $spending->received( $workspace, $request );
			if ( null !== $receipt && 'completed' === ( $receipt['result']['reason'] ?? null ) && is_array( $receipt['result']['review'] ?? null ) ) {
				list($evidence) = $this->context( $workspace, $receipt['approval_id'], $request, true );
				AiReview::build( $receipt['result']['review'], $evidence['bundle'], $row['model'] );
				$result['can_publish'] = true;
			}
		} catch ( \InvalidArgumentException | \UnexpectedValueException | \OutOfBoundsException | \TypeError $error ) {
			// Unusable stored evidence never grants an action or exposes private diagnostics.
			$result['can_publish'] = false;
		}
		return $result;
	}

	/**
	 * Read immutable history without exposing request credentials or pricing.
	 *
	 * @param int $workspace Workspace.
	 * @param int $id Review.
	 * @return array Metadata and validated untrusted output.
	 * @throws \UnexpectedValueException On damaged history.
	 */
	public function read( int $workspace, int $id ): array {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
		$row                      = $this->db->object( 'ai_reviews', $workspace, $id );
		list($evidence, $request) = $this->context( $workspace, (int) $row['approval_id'], (int) $row['request_id'], false );
		if ( (int) $row['asset_id'] !== $evidence['asset_id'] || (int) $row['actor_id'] !== (int) $request['actor_id'] || ! hash_equals( $row['evidence_fingerprint'], $evidence['fingerprint'] ) || ! hash_equals( $row['output_fingerprint'], hash( 'sha256', $row['output_json'] ) ) ) {
			throw new \UnexpectedValueException( 'Saved AI review integrity failed.' );
		}
		try {
			$output     = json_decode( $row['output_json'], true, 64, JSON_THROW_ON_ERROR );
			$normalized = AiReview::build( array_intersect_key( $output, array_flip( array( 'response_id', 'model', 'summary', 'findings' ) ) ), $evidence['bundle'], $request['model'] );
			if ( $normalized['output'] !== $output ) {
				throw new \UnexpectedValueException( 'Saved AI review schema is unsupported.' );
			}
		} catch ( \JsonException | \InvalidArgumentException | \TypeError $error ) {
			throw new \UnexpectedValueException( 'Saved AI review output is damaged.' );
		}
		return $this->metadata( $row ) + array( 'output' => $output );
	}

	/**
	 * Page owner-scoped review metadata for one stock.
	 *
	 * @param int $workspace Workspace.
	 * @param int $asset Asset.
	 * @param int $after Cursor.
	 * @param int $limit Page size.
	 * @return array Items and next cursor; not a complete-dataset claim.
	 * @throws \InvalidArgumentException On invalid pagination.
	 */
	public function history( int $workspace, int $asset, int $after = 0, int $limit = 100 ): array {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
		$this->db->object( 'assets', $workspace, $asset );
		if ( $after < 0 || $limit < 1 || $limit > 100 ) {
			throw new \InvalidArgumentException( 'Invalid review history pagination.' );
		}
		$rows = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'ai_reviews' ) . ' WHERE workspace_id = %d AND asset_id = %d AND id > %d ORDER BY id LIMIT %d', array( $workspace, $asset, $after, $limit ) );
		return array(
			'items'       => array_map( array( $this, 'metadata' ), $rows ),
			'next_cursor' => count( $rows ) === $limit ? (string) end( $rows )['id'] : null,
		);
	}

	/**
	 * Verify scoped immutable approval/request relationships and final usage.
	 *
	 * @param int  $workspace Workspace.
	 * @param int  $approval Approval.
	 * @param int  $request Request.
	 * @param bool $writing Require original owner for persistence.
	 * @return array Evidence and internal spending facts.
	 * @throws \UnexpectedValueException On unmatched or incomplete results.
	 */
	private function context( int $workspace, int $approval, int $request, bool $writing ): array {
		$evidence = ( new AiEvidencePreview( $this->db, $this->actor, $this->correlation ) )->approved( $workspace, $approval );
		$source   = $this->db->object( 'ai_evidence_bundles', $workspace, $approval );
		$spending = ( new AiSpending( $this->db, $this->actor, $this->correlation ) )->request( $workspace, $request );
		if ( ( (int) ( $spending['approval_id'] ?? 0 ) && (int) $spending['approval_id'] !== $approval ) || 'settled' !== $spending['state'] || null === $spending['usage_json'] || ! hash_equals( $spending['input_fingerprint'], $evidence['fingerprint'] ) || (int) $source['actor_id'] !== (int) $spending['actor_id'] || ( $writing && (int) $spending['actor_id'] !== $this->actor ) ) {
			throw new \UnexpectedValueException( 'AI review requires matching approved evidence and a settled original-owner request.' );
		}
		return array( $evidence, $spending );
	}

	/**
	 * Exclude private output and internal request/authorizer details from lists.
	 *
	 * @param array $row Review row.
	 * @return array Public owner metadata.
	 */
	private function metadata( array $row ): array {
		return array(
			'id'                   => (int) $row['id'],
			'asset_id'             => (int) $row['asset_id'],
			'approval_id'          => (int) $row['approval_id'],
			'request_id'           => (int) $row['request_id'],
			'evidence_fingerprint' => $row['evidence_fingerprint'],
			'output_fingerprint'   => $row['output_fingerprint'],
			'created_at'           => $row['created_at'],
		);
	}
}
