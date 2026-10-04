<?php
/**
 * Trade journals and immutable strategy versions (JR 01-03).
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Domain\JournalInput as Input;
use GainerInteractive\IGTradingJournal\Domain\Decimal;
use GainerInteractive\IGTradingJournal\Infrastructure\Database;

/** Journal writes never modify ledger facts, cash or lots. */
final class Journal {
	/**
	 * Scoped persistence.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Authorized command context.
	 *
	 * @var JournalCommands
	 */
	private JournalCommands $commands;
	/**
	 * Explicit actor.
	 *
	 * @var int
	 */
	private int $actor;
	/** Bind context.
	 *
	 * @param Database $db Persistence.
	 * @param int      $actor Actor.
	 * @param string   $correlation Request context.
	 */
	public function __construct( Database $db, int $actor, string $correlation ) {
		$this->db       = $db;
		$this->actor    = $actor;
		$this->commands = new JournalCommands( $db, $actor, $correlation );
	}
	/** List scoped journals or strategies with bounded cursor pagination.
	 *
	 * @param int    $workspace Workspace.
	 * @param string $type Trusted object type.
	 * @param int    $after Cursor.
	 * @param int    $limit Page size.
	 * @return array
	 * @throws \InvalidArgumentException When object type or page is invalid.
	 */
	public function listing( int $workspace, string $type, int $after, int $limit ): array {
		$this->commands->authorize( $workspace );
		if ( ! in_array( $type, array( 'trades', 'strategies' ), true ) || $after < 0 || $limit < 1 || $limit > 100 ) {
			throw new \InvalidArgumentException( 'Invalid journal page.' );
		}
		$items = array();
		if ( 'strategies' === $type ) {
			$items = $this->db->rows( 'SELECT * FROM ' . $this->db->table( $type ) . ' WHERE workspace_id = %d AND id > %d ORDER BY id LIMIT %d', array( $workspace, $after, $limit ) );
		}
		if ( 'trades' === $type ) {
			$items = $this->db->rows(
				'SELECT t.*,a.symbol,a.exchange,j.created_at AS updated_at,j.payload AS journal_payload,v.payload AS strategy_payload,(SELECT COUNT(*) FROM ' . $this->db->table( 'media' ) . " i WHERE i.workspace_id = t.workspace_id AND i.trade_id = t.id AND i.state IN ('reserved','ready','failed')) AS image_count FROM " . $this->db->table( 'trades' ) . ' t JOIN ' . $this->db->table( 'assets' ) . ' a ON a.workspace_id = t.workspace_id AND a.id = t.asset_id LEFT JOIN ' . $this->db->table( 'trade_journals' ) . ' j ON j.workspace_id = t.workspace_id AND j.trade_id = t.id AND j.revision = t.revision LEFT JOIN ' . $this->db->table( 'strategy_versions' ) . ' v ON v.workspace_id = t.workspace_id AND v.id = t.strategy_version_id WHERE t.workspace_id = %d AND t.id > %d ORDER BY t.id LIMIT %d',
				array( $workspace, $after, $limit )
			);
			foreach ( $items as &$item ) {
				$journal               = json_decode( $item['journal_payload'] ?? '{}', true );
				$strategy              = json_decode( $item['strategy_payload'] ?? '{}', true );
				$item['tags']          = $journal['fields']['tags'] ?? array();
				$item['strategy_name'] = $strategy['name'] ?? null;
				unset( $item['journal_payload'], $item['strategy_payload'] );
			}
			unset( $item );
		}
		return array(
			'items'       => $items,
			'next_cursor' => count( $items ) === $limit ? (string) end( $items )['id'] : null,
		);
	}
	/** Read a strategy and immutable versions.
	 *
	 * @param int $workspace Workspace.
	 * @param int $id Strategy.
	 * @return array
	 */
	public function strategy( int $workspace, int $id ): array {
		$this->commands->authorize( $workspace );
		$page = $this->history( $workspace, 'strategies', $id, PHP_INT_MAX, 20 );
		return array(
			'strategy'        => $this->db->object( 'strategies', $workspace, $id ),
			'versions'        => $page['items'],
			'versions_cursor' => $page['next_cursor'],
		);
	}
	/** Save a strategy revision, preserving historical snapshots.
	 *
	 * @param int    $workspace Workspace.
	 * @param int    $id Strategy; zero for create.
	 * @param array  $input Full replacement facts.
	 * @param string $key Retry identity.
	 * @return array
	 * @throws \InvalidArgumentException When name or status is invalid.
	 */
	public function save_strategy( int $workspace, int $id, array $input, string $key ): array {
		Input::fields( $input, array( 'name', 'status', 'description', 'rules', 'tags', 'expected_revision' ), array( 'name', 'status', 'description', 'rules', 'tags' ) );
		$name = sanitize_text_field( Input::text( $input['name'], 190 ) );
		if ( '' === $name || ! in_array( $input['status'], array( 'active', 'archived' ), true ) ) {
			throw new \InvalidArgumentException( 'Use a strategy name and active or archived status.' );
		}
		$facts    = array(
			'name'        => $name,
			'status'      => $input['status'],
			'description' => self::rich( Input::text( $input['description'] ) ),
			'rules'       => self::rich( Input::text( $input['rules'] ) ),
			'tags'        => array_map( 'sanitize_text_field', Input::labels( $input['tags'] ) ),
		);
		$expected = $id ? Input::id( $input['expected_revision'] ?? null ) : null;
		return $this->commands->run(
			$workspace,
			'tgit_manage_settings',
			'strategy.save.' . $id,
			array(
				'facts'             => $facts,
				'expected_revision' => $expected,
			),
			$key,
			function () use ( $workspace, $id, $facts, $expected ) {
				$revision = 1;
				if ( $id ) {
					$record = $this->db->object( 'strategies', $workspace, $id );
					if ( (int) $record['revision'] !== $expected ) {
						throw new \UnexpectedValueException( 'Strategy changed. Reload its latest revision.' );
					}
					$revision = $expected + 1;
					$this->db->update_object(
						'strategies',
						$workspace,
						$id,
						array(
							'name'     => $facts['name'],
							'status'   => $facts['status'],
							'revision' => $revision,
						)
					);
				} else {
					$id = $this->db->insert(
						'strategies',
						array(
							'workspace_id' => $workspace,
							'uuid'         => wp_generate_uuid4(),
							'name'         => $facts['name'],
							'status'       => $facts['status'],
							'revision'     => 1,
							'created_by'   => $this->actor,
							'created_at'   => gmdate( 'Y-m-d H:i:s' ),
						)
					);
				}
				$version = $this->db->insert(
					'strategy_versions',
					array(
						'workspace_id' => $workspace,
						'strategy_id'  => $id,
						'revision'     => $revision,
						'payload'      => wp_json_encode( $facts ),
						'actor_id'     => $this->actor,
						'created_at'   => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->commands->audit( $workspace, 'strategy.saved', 'strategy', $id, $revision );
				return array(
					'strategy'   => $this->db->object( 'strategies', $workspace, $id ),
					'version_id' => $version,
				);
			}
		);
	}
	/** Read a trade, fill facts, journal history and captured strategy version.
	 *
	 * @param int $workspace Workspace.
	 * @param int $id Trade.
	 * @return array
	 */
	public function trade( int $workspace, int $id ): array {
		$this->commands->authorize( $workspace );
		$trade     = $this->db->object( 'trades', $workspace, $id );
		$page      = $this->history( $workspace, 'trades', $id, PHP_INT_MAX, 20 );
		$revisions = $page['items'];
		$fills     = $this->db->rows( 'SELECT t.* FROM ' . $this->db->table( 'transactions' ) . ' t JOIN ' . $this->db->table( 'trade_fills' ) . ' f ON f.workspace_id = t.workspace_id AND f.transaction_id = t.id WHERE f.workspace_id = %d AND f.trade_id = %d ORDER BY t.effective_date,t.id', array( $workspace, $id ) );
		return array(
			'trade'            => $trade,
			'journal'          => json_decode( end( $revisions )['payload'], true, 512, JSON_THROW_ON_ERROR ),
			'revisions'        => $revisions,
			'revisions_cursor' => $page['next_cursor'],
			'fills'            => $fills,
			'strategy_version' => $trade['strategy_version_id'] ? $this->db->object( 'strategy_versions', $workspace, (int) $trade['strategy_version_id'] ) : null,
		);
	}
	/** Validate optional journal facts, with blank values retained.
	 *
	 * @param array $input Journal fields.
	 * @return array
	 * @throws \InvalidArgumentException When confidence is invalid.
	 */
	private function journal_fields( array $input ): array {
		$text   = array( 'thesis', 'entry_rationale', 'exit_rationale', 'emotions', 'lessons', 'notes', 'confluence_text', 'original_confluences' );
		$levels = array( 'premarket_low', 'premarket_high', 'previous_day_low', 'previous_day_high', 'planned_stop', 'planned_target' );
		Input::fields( $input, array_merge( $text, $levels, array( 'confidence', 'tags', 'confluences' ) ) );
		$result = array();
		foreach ( $text as $field ) {
			$result[ $field ] = 'original_confluences' === $field ? Input::text( $input[ $field ] ?? '' ) : self::rich( Input::text( $input[ $field ] ?? '' ) );
		}
		foreach ( $levels as $field ) {
			$result[ $field ] = ! isset( $input[ $field ] ) || '' === $input[ $field ] ? null : Decimal::input( $input[ $field ], 18 );
		}
		$confidence = $input['confidence'] ?? null;
		if ( null !== $confidence && ( ! is_int( $confidence ) || $confidence < 0 || $confidence > 100 ) ) {
			throw new \InvalidArgumentException( 'Confidence must be blank or an integer from 0 to 100.' );
		}
		$result['confidence'] = $confidence;
		foreach ( array( 'tags' ) as $field ) {
			$result[ $field ] = array_map( 'sanitize_text_field', Input::labels( $input[ $field ] ?? array() ) );
		}
		$checklist = $input['confluences'] ?? array();
		if ( ! is_array( $checklist ) || ! array_is_list( $checklist ) || count( $checklist ) > 50 ) {
			throw new \InvalidArgumentException( 'Use at most 50 confluence checklist items.' );
		}
		$result['confluences'] = array();
		foreach ( $checklist as $item ) {
			if ( is_string( $item ) ) {
				$item = array(
					'label'   => $item,
					'checked' => true,
				);
			}
			if ( ! is_array( $item ) ) {
				throw new \InvalidArgumentException( 'Checklist items require a label and boolean checked state.' );
			}
			Input::fields( $item, array( 'label', 'checked' ), array( 'label', 'checked' ) );
			if ( ! is_bool( $item['checked'] ) ) {
				throw new \InvalidArgumentException( 'Checklist checked state must be boolean.' );
			}
			$result['confluences'][] = array(
				'label'   => sanitize_text_field( Input::text( $item['label'], 190 ) ),
				'checked' => $item['checked'],
			);
		}
		return $result;
	}
	/** Save trade grouping and journal evidence without financial effects.
	 *
	 * @param int    $workspace Workspace.
	 * @param int    $id Trade; zero for create.
	 * @param array  $input Full replacement facts.
	 * @param string $key Retry identity.
	 * @return array
	 * @throws \InvalidArgumentException When title, state, dates or fills are invalid.
	 */
	public function save_trade( int $workspace, int $id, array $input, string $key ): array {
		Input::fields( $input, array( 'asset_id', 'title', 'state', 'opened_on', 'closed_on', 'strategy_version_id', 'transaction_ids', 'journal', 'expected_revision' ), array( 'asset_id', 'title', 'state', 'journal', 'transaction_ids' ) );
		$title = sanitize_text_field( Input::text( $input['title'], 190 ) );
		if ( '' === $title || ! in_array( $input['state'], array( 'planned', 'open', 'closed', 'archived' ), true ) || ! is_array( $input['journal'] ) ) {
			throw new \InvalidArgumentException( 'Use a trade title, supported state and journal object.' );
		}
		$facts = array(
			'asset_id'            => Input::id( $input['asset_id'] ),
			'title'               => $title,
			'state'               => $input['state'],
			'opened_on'           => Input::date( $input['opened_on'] ?? null ),
			'closed_on'           => Input::date( $input['closed_on'] ?? null ),
			'strategy_version_id' => isset( $input['strategy_version_id'] ) ? Input::id( $input['strategy_version_id'] ) : null,
		);
		if ( $facts['opened_on'] && $facts['closed_on'] && $facts['closed_on'] < $facts['opened_on'] ) {
			throw new \InvalidArgumentException( 'Closing date precedes opening date.' );
		}
		$journal = $this->journal_fields( $input['journal'] );
		if ( ! is_array( $input['transaction_ids'] ) || ! array_is_list( $input['transaction_ids'] ) || count( $input['transaction_ids'] ) > 200 ) {
			throw new \InvalidArgumentException( 'Use at most 200 fill IDs.' );
		}
		$fills = array_values( array_unique( array_map( array( Input::class, 'id' ), $input['transaction_ids'] ) ) );
		sort( $fills );
		$expected = $id ? Input::id( $input['expected_revision'] ?? null ) : null;
		return $this->commands->run(
			$workspace,
			'tgit_edit_journal',
			'trade.save.' . $id,
			array(
				'facts'             => $facts,
				'journal'           => $journal,
				'fills'             => $fills,
				'expected_revision' => $expected,
			),
			$key,
			function () use ( $workspace, $id, $facts, $journal, $fills, $expected ) {
				$this->db->object( 'assets', $workspace, $facts['asset_id'] );
				if ( $facts['strategy_version_id'] ) {
					$this->db->object( 'strategy_versions', $workspace, $facts['strategy_version_id'] );
				}
				$revision = 1;
				if ( $id ) {
					$record = $this->db->object( 'trades', $workspace, $id );
					if ( (int) $record['revision'] !== $expected ) {
						throw new \UnexpectedValueException( 'Trade changed. Reload its latest revision.' );
					}
					$revision = $expected + 1;
				}
				foreach ( $fills as $fill ) {
					$transaction = $this->db->object( 'transactions', $workspace, $fill );
					if ( (int) $transaction['asset_id'] !== $facts['asset_id'] || ! in_array( $transaction['action'], array( 'buy', 'sell' ), true ) || ! in_array( $transaction['state'], array( 'posted', 'draft' ), true ) ) {
						throw new \InvalidArgumentException( 'Fills must be buys or sells for this asset.' );
					}
					$link = $this->db->row( 'SELECT trade_id FROM ' . $this->db->table( 'trade_fills' ) . ' WHERE workspace_id = %d AND transaction_id = %d', array( $workspace, $fill ) );
					if ( $link && (int) $link['trade_id'] !== $id ) {
						throw new \UnexpectedValueException( 'A fill already belongs to another trade.' );
					}
				}
				if ( $id ) {
					$this->db->update_object( 'trades', $workspace, $id, $facts + array( 'revision' => $revision ) );
				} else {
					$id = $this->db->insert(
						'trades',
						$facts + array(
							'workspace_id' => $workspace,
							'uuid'         => wp_generate_uuid4(),
							'revision'     => 1,
							'created_by'   => $this->actor,
							'created_at'   => gmdate( 'Y-m-d H:i:s' ),
						)
					);
				}
				$this->db->query( 'DELETE FROM ' . $this->db->table( 'trade_fills' ) . ' WHERE workspace_id = %d AND trade_id = %d', array( $workspace, $id ) );
				foreach ( $fills as $fill ) {
					$this->db->insert(
						'trade_fills',
						array(
							'workspace_id'   => $workspace,
							'trade_id'       => $id,
							'transaction_id' => $fill,
						)
					);
				}
				$this->db->insert(
					'trade_journals',
					array(
						'workspace_id' => $workspace,
						'trade_id'     => $id,
						'revision'     => $revision,
						'payload'      => wp_json_encode(
							array(
								'trade'           => $facts,
								'fields'          => $journal,
								'transaction_ids' => $fills,
							)
						),
						'actor_id'     => $this->actor,
						'created_at'   => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->commands->audit( $workspace, 'trade.saved', 'trade', $id, $revision );
				return $this->trade( $workspace, $id );
			}
		);
	}
	/** Sanitize the supported basic rich text without remote embedded media.
	 *
	 * @param string $text User-authored content.
	 * @return string
	 */
	private static function rich( string $text ): string {
		return wp_kses(
			$text,
			array(
				'p'          => array(),
				'br'         => array(),
				'strong'     => array(),
				'b'          => array(),
				'em'         => array(),
				'i'          => array(),
				'ul'         => array(),
				'ol'         => array(),
				'li'         => array(),
				'blockquote' => array(),
				'a'          => array(
					'href'  => true,
					'title' => true,
				),
			)
		);
	}
	/** Read bounded immutable revision pages, newest page first.
	 *
	 * @param int    $workspace Workspace.
	 * @param string $type Trades or strategies.
	 * @param int    $id Object identity.
	 * @param int    $before Exclusive revision cursor.
	 * @param int    $limit Page size.
	 * @return array
	 * @throws \InvalidArgumentException When pagination is invalid.
	 */
	public function history( int $workspace, string $type, int $id, int $before, int $limit ): array {
		$this->commands->authorize( $workspace );
		if ( ! in_array( $type, array( 'trades', 'strategies' ), true ) || $before < 1 || $limit < 1 || $limit > 20 ) {
			throw new \InvalidArgumentException( 'Invalid revision page.' );
		}
		$this->db->object( $type, $workspace, $id );
		$table = 'trades' === $type ? 'trade_journals' : 'strategy_versions';
		$field = 'trades' === $type ? 'trade_id' : 'strategy_id';
		$items = $this->db->rows( 'SELECT * FROM ' . $this->db->table( $table ) . ' WHERE workspace_id = %d AND ' . $field . ' = %d AND revision < %d ORDER BY revision DESC LIMIT %d', array( $workspace, $id, $before, $limit ) );
		return array(
			'items'       => array_reverse( $items ),
			'next_cursor' => count( $items ) === $limit ? (string) end( $items )['revision'] : null,
		);
	}
}
