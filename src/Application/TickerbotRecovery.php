<?php
/**
 * Revision-checked owner recovery; never resets admission or creates jobs.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

/** Shared operational controls reveal no foreign workspace portfolio details. */
trait TickerbotRecovery {
	/**
	 * Explain recovery eligibility under the account lock.
	 *
	 * @param int   $workspace Authorized workspace ID.
	 * @param array $account Locked account metadata.
	 * @return string Safe reason, ready means an explicit review can recover.
	 */
	private function recovery_state( int $workspace, array $account ): string {
		if ( (int) $account['controller_workspace_id'] !== $workspace ) {
			return 'controller_only'; }
		if ( ! $account['paused_until'] ) {
			return 'not_paused'; }
		if ( $account['active_run_id'] && $account['lease_until'] > $this->time() ) {
			return 'active_operation'; }
		if ( $account['recovery_not_before'] && $account['recovery_not_before'] > $this->time() ) {
			return 'wait_for_provider'; }
		if ( $account['paused_until'] > $this->time() ) {
			if ( in_array( $account['pause_kind'], array( 'rate_limit', 'monthly_quota' ), true ) ) {
				return 'wait_for_provider'; }
			if ( ! in_array( $account['pause_kind'], array( 'authentication', 'capability' ), true ) ) {
				return 'legacy_review'; }
		}
		return 'ready';
	}

	/**
	 * Clear only the exact reviewed pause after an owner acknowledgment.
	 *
	 * @param int  $workspace Controlling workspace ID.
	 * @param int  $expected Reviewed pause revision.
	 * @param bool $acknowledged Cause review and possible existing-work resumption.
	 * @return array Safe new revision; no provider request or send capability.
	 * @throws \InvalidArgumentException On missing review acknowledgment.
	 */
	public function recover_pause( int $workspace, int $expected, bool $acknowledged ): array {
		if ( $expected < 0 || ! $acknowledged ) {
			throw new \InvalidArgumentException( 'Review the pause cause and acknowledge recovery first.' ); }
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $expected ): array {
				$account = $this->account();
				$this->settings( $workspace, $account );
				if ( (int) $account['pause_revision'] !== $expected ) {
					throw new \UnexpectedValueException( 'The shared pause changed; reload before recovery.' ); }
				if ( 'ready' !== $this->recovery_state( $workspace, $account ) ) {
					throw new \DomainException( 'Recovery is blocked. Review the saved recovery status and provider wait time.' ); }
				$id = $this->db->insert(
					'tickerbot_pause_recoveries',
					array(
						'workspace_id'      => $workspace,
						'account_id'        => $account['id'],
						'expected_revision' => $expected,
						'actor_id'          => $this->actor,
						'prior_kind'        => $account['pause_kind'],
						'prior_until'       => $account['paused_until'],
						'created_at'        => $this->time(),
					)
				);
				$this->db->query( 'UPDATE ' . $this->db->table( 'tickerbot_accounts' ) . ' SET paused_until = NULL, pause_kind = NULL, recovery_not_before = NULL, pause_revision = pause_revision + 1 WHERE id = %d', array( $account['id'] ) );
				$this->audit( $workspace, 'tickerbot.pause.recover', 'tickerbot_pause_recoveries', $id );
				return array(
					'state'          => 'recovered',
					'pause_revision' => $expected + 1,
				);
			}
		);
	}
}
