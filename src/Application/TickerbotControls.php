<?php
/**
 * Local owner scope and cadence previews; no provider requests.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

/** Complete bounded local choices, with no cross-workspace customer records. */
trait TickerbotControls {
	/**
	 * Read complete local selections and saved inventory under workspace/account locks.
	 *
	 * @param int $workspace Authorized owner workspace.
	 * @return array Safe choices, reviewed revisions and saved inventory only.
	 */
	public function controls( int $workspace ): array {
		return $this->atomic(
			$workspace,
			function () use ( $workspace ): array {
				$account  = $this->account();
				$settings = $this->settings( $workspace, $account );
				$lists    = $this->db->rows( 'SELECT id,name FROM ' . $this->db->table( 'watchlists' ) . ' WHERE workspace_id=%d ORDER BY id LIMIT 10001', array( $workspace ) );
				$trades   = $this->db->rows( 'SELECT id,title,state FROM ' . $this->db->table( 'trades' ) . ' WHERE workspace_id=%d ORDER BY id LIMIT 10001', array( $workspace ) );
				if ( count( $lists ) > 10000 || count( $trades ) > 10000 ) {
					throw new \DomainException( 'Asset selection exceeds the supported limit; no partial choices are shown.' );}
				$cadence = $this->cadence( $workspace );
				return array(
					'selection'         => $this->scope_selection( $workspace ),
					'settings_revision' => (int) $settings['revision'],
					'interval_seconds'  => (int) $settings['interval_seconds'],
					'watchlists'        => array_map(
						static fn( $r )=>array(
							'id'   => (int) $r['id'],
							'name' => $r['name'],
						),
						$lists
					),
					'trades'            => array_map(
						static fn( $r )=>array(
							'id'    => (int) $r['id'],
							'title' => $r['title'],
							'state' => $r['state'],
						),
						$trades
					),
					'cadence'           => array(
						'revision' => (int) ( $cadence['revision'] ?? 0 ),
						'enabled'  => 1 === (int) ( $cadence['enabled'] ?? 0 ),
					),
					'inventory'         => $this->inventory( $workspace ),
					'manual_assets'     => $this->manual_asset_choices( $workspace ),
				);
			}
		);
	}
	/**
	 * Complete local asset choices; read only, no saved recurring-scope mutation.
	 *
	 * @param int $workspace Authorized workspace.
	 * @return array Safe mapped/unmapped identity choices.
	 * @throws \DomainException When complete choices exceed the supported bound.
	 */
	private function manual_asset_choices( int $workspace ): array {
		$assets = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'assets' ) . ' WHERE workspace_id=%d ORDER BY id LIMIT 10001', array( $workspace ) );
		if ( count( $assets ) > 10000 ) {
			throw new \DomainException( 'Too many assets for complete manual choices.' ); }
		$maps     = $this->db->rows( 'SELECT m.* FROM ' . $this->db->table( 'tickerbot_mappings' ) . ' m WHERE m.workspace_id=%d AND m.id IN (SELECT MAX(id) FROM ' . $this->db->table( 'tickerbot_mappings' ) . ' WHERE workspace_id=%d GROUP BY asset_id)', array( $workspace, $workspace ) );
		$by_asset = array_column( $maps, null, 'asset_id' );
		return array_map( static fn( $asset ) => TickerbotInventory::entry( $asset, $by_asset[ $asset['id'] ] ?? null, array( 'manual_selection' ) ), $assets );
	}
}
