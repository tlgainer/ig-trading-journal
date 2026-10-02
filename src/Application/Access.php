<?php
/**
 * Explicit workspace-role grants (ACL 01-04).
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

/** Access service for the current implementation slice. */
final class Access {
	private const GRANTS = array(
		'owner'       => array( 'tgit_view', 'tgit_edit_journal', 'tgit_post', 'tgit_create_draft', 'tgit_manage_settings', 'tgit_manage_members' ),
		'manager'     => array( 'tgit_view', 'tgit_edit_journal', 'tgit_post', 'tgit_create_draft', 'tgit_manage_settings' ),
		'contributor' => array( 'tgit_view', 'tgit_edit_journal', 'tgit_create_draft' ),
		'viewer'      => array( 'tgit_view' ),
	);

	/**
	 * Evaluate a capability against current explicit membership.
	 *
	 * @param array|null $membership membership input.
	 * @param string     $capability capability input.
	 * @return bool
	 */
	public static function allows( ?array $membership, string $capability ): bool {
		return null !== $membership && 'active' === $membership['state']
		&& in_array( $capability, self::GRANTS[ $membership['role'] ] ?? array(), true );
	}

	/**
	 * List supported workspace roles.
	 *
	 * @return array
	 */
	public static function roles(): array {
		return array_keys( self::GRANTS );
	}
}
