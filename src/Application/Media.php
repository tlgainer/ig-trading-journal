<?php
/**
 * Private trade images with quotas, retries and recoverable deletion.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Domain\JournalInput as Input;
use GainerInteractive\IGTradingJournal\Infrastructure\Database;
use GainerInteractive\IGTradingJournal\Infrastructure\PrivateImages;

/** Workspace-scoped media; normalized files never enter the public media library. */
final class Media {
	/**
	 * Scoped persistence.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Authorized commands.
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
	/** Return operational limits without disclosing filesystem paths.
	 *
	 * @param int $workspace Workspace.
	 * @return array
	 */
	public function settings( int $workspace ): array {
		$this->commands->authorize( $workspace );
		$settings       = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'media_settings' ) . ' WHERE workspace_id = %d', array( $workspace ) );
		$storage_status = 'ready';
		try {
			PrivateImages::root( true );
		} catch ( \RuntimeException $error ) {
			$storage_status = 'private_directory_unavailable';
		}
		if ( ! extension_loaded( 'gd' ) ) {
			$storage_status = 'gd_unavailable';
		} elseif ( 'ready' === $storage_status && ( ! function_exists( 'imagetypes' ) || ( imagetypes() & ( IMG_JPG | IMG_PNG | IMG_WEBP ) ) !== ( IMG_JPG | IMG_PNG | IMG_WEBP ) ) ) {
			$storage_status = 'image_codecs_unavailable';
		}
		$usage = $this->db->row( 'SELECT COALESCE(SUM(reserved_bytes),0) AS bytes FROM ' . $this->db->table( 'media' ) . ' WHERE workspace_id = %d', array( $workspace ) );
		return array(
			'configured'     => null !== $settings,
			'storage_ready'  => 'ready' === $storage_status,
			'storage_status' => $storage_status,
			'used_bytes'     => $usage['bytes'],
			'limits'         => $settings ?? array(
				'max_images'     => 20,
				'max_file_bytes' => 10485760,
				'max_pixels'     => 40000000,
				'quota_bytes'    => 1073741824,
				'trash_days'     => 30,
				'revision'       => 0,
			),
		);
	}
	/** Owner configures quota before allocation; writes are revision checked.
	 *
	 * @param int    $workspace Workspace.
	 * @param array  $input Settings.
	 * @param string $key Retry identity.
	 * @return array
	 * @throws \InvalidArgumentException When bounds are invalid.
	 */
	public function save_settings( int $workspace, array $input, string $key ): array {
		$bounds = array(
			'max_images'     => 100,
			'max_file_bytes' => 10485760,
			'max_pixels'     => 40000000,
			'quota_bytes'    => 107374182400,
			'trash_days'     => 365,
		);
		Input::fields( $input, array_merge( array_keys( $bounds ), array( 'expected_revision' ) ), array_merge( array_keys( $bounds ), array( 'expected_revision' ) ) );
		foreach ( $bounds as $field => $max ) {
			if ( ! is_int( $input[ $field ] ) || $input[ $field ] < 1 || $input[ $field ] > $max ) {
				throw new \InvalidArgumentException( 'Media setting is outside supported bounds.' );
			}
		}
		if ( ! is_int( $input['expected_revision'] ) || $input['expected_revision'] < 0 ) {
			throw new \InvalidArgumentException( 'Expected revision must be a nonnegative integer.' );
		}
		$result = $this->commands->run(
			$workspace,
			'tgit_manage_members',
			'media.settings',
			$input,
			$key,
			function () use ( $workspace, $input ) {
				$prior = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'media_settings' ) . ' WHERE workspace_id = %d', array( $workspace ) );
				if ( (int) ( $prior['revision'] ?? 0 ) !== $input['expected_revision'] ) {
					throw new \UnexpectedValueException( 'Media settings changed. Reload.' );
				}
				$usage = $this->settings( $workspace )['used_bytes'];
				if ( $input['quota_bytes'] < (int) $usage ) {
					throw new \InvalidArgumentException( 'Quota cannot be below retained bytes and reservations.' );
				}
				$data = $input;
				unset( $data['expected_revision'] );
				$data['revision'] = $input['expected_revision'] + 1;
				if ( $prior ) {
					$this->db->update_object( 'media_settings', $workspace, (int) $prior['id'], $data );
				} else {
					$this->db->insert( 'media_settings', $data + array( 'workspace_id' => $workspace ) );
				}
				$this->commands->audit( $workspace, 'media.settings', 'workspace', $workspace, $data['revision'] );
				return $this->settings( $workspace );
			}
		);
		\GainerInteractive\IGTradingJournal\Infrastructure\MediaJobs::schedule( $workspace, $this->actor );
		return $result;
	}
	/** Hide storage identifiers and hashes used for request verification.
	 *
	 * @param array $row Internal row.
	 * @return array
	 */
	private static function public_row( array $row ): array {
		foreach ( array( 'storage_key', 'thumb_key', 'expected_hash' ) as $field ) {
			unset( $row[ $field ] );
		} return $row;
	}
	/** Read active gallery and recoverable trash, bounded by the per-trade quota.
	 *
	 * @param int $workspace Workspace.
	 * @param int $trade Trade.
	 * @return array
	 */
	public function gallery( int $workspace, int $trade ): array {
		$this->commands->authorize( $workspace );
		$this->db->object( 'trades', $workspace, $trade );
		return array( 'items' => array_map( array( self::class, 'public_row' ), $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'media' ) . ' WHERE workspace_id = %d AND trade_id = %d AND state <> %s ORDER BY sort_order,id LIMIT 200', array( $workspace, $trade, 'purged' ) ) ) );
	}
	/** Validate gallery metadata.
	 *
	 * @param array $input Metadata.
	 * @return array
	 * @throws \InvalidArgumentException When stage or order is invalid.
	 */
	private static function metadata( array $input ): array {
		$stage = $input['stage'] ?? 'review';
		$order = $input['sort_order'] ?? 0;
		if ( ! in_array( $stage, array( 'before', 'entry', 'exit', 'review' ), true ) || ! is_int( $order ) || $order < 0 || $order > 100000 ) {
			throw new \InvalidArgumentException( 'Invalid image stage or order.' );
		}
		return array(
			'caption'    => sanitize_textarea_field( Input::text( $input['caption'] ?? '', 2000 ) ),
			'alt_text'   => sanitize_textarea_field( Input::text( $input['alt_text'] ?? '', 2000 ) ),
			'stage'      => $stage,
			'timeframe'  => sanitize_text_field( Input::text( $input['timeframe'] ?? '', 64 ) ),
			'sort_order' => $order,
		);
	}
	/** Reserve image count and storage before upload.
	 *
	 * @param int    $workspace Workspace.
	 * @param int    $trade Trade.
	 * @param array  $input Filename, hash, size and optional metadata.
	 * @param string $key Retry identity.
	 * @return array
	 * @throws \InvalidArgumentException When hash is invalid.
	 */
	public function reserve( int $workspace, int $trade, array $input, string $key ): array {
		Input::fields( $input, array( 'filename', 'hash', 'size', 'caption', 'alt_text', 'stage', 'timeframe', 'sort_order' ), array( 'filename', 'hash', 'size' ) );
		$filename = sanitize_text_field( Input::text( $input['filename'], 190 ) );
		$size     = Input::id( $input['size'] );
		if ( ! is_string( $input['hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $input['hash'] ) ) {
			throw new \InvalidArgumentException( 'Use a SHA-256 content hash.' );
		}
		$metadata = self::metadata( $input );
		return $this->commands->run(
			$workspace,
			'tgit_edit_journal',
			'media.reserve.' . $trade,
			$input,
			$key,
			function () use ( $workspace, $trade, $input, $filename, $size, $metadata ) {
				PrivateImages::root( true );
				$this->db->object( 'trades', $workspace, $trade );
				$settings = $this->settings( $workspace );
				if ( ! $settings['configured'] ) {
					throw new \InvalidArgumentException( 'Workspace owner must configure image quota first.' );
				}
				if ( ! $settings['storage_ready'] ) {
					throw new \InvalidArgumentException( 'Private storage and PHP GD must be configured before reserving images.' );
				}
				$limits = $settings['limits'];
				if ( $size > (int) $limits['max_file_bytes'] || ! in_array( strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ), array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
					throw new \InvalidArgumentException( 'Use JPEG, PNG or WebP within the file limit.' );
				}
				$count    = $this->db->row( 'SELECT COUNT(*) AS count FROM ' . $this->db->table( 'media' ) . ' WHERE workspace_id = %d AND trade_id = %d AND state <> %s', array( $workspace, $trade, 'purged' ) );
				$reserved = (int) $limits['max_file_bytes'] + 1048576;
				if ( (int) $count['count'] >= (int) $limits['max_images'] || (int) $settings['used_bytes'] + $reserved > (int) $limits['quota_bytes'] ) {
					throw new \InvalidArgumentException( 'Trade image count or workspace storage quota exceeded. Trash remains within quota until purged.' );
				}
				$now = gmdate( 'Y-m-d H:i:s' );
				$id  = $this->db->insert(
					'media',
					$metadata + array(
						'workspace_id'   => $workspace,
						'trade_id'       => $trade,
						'uuid'           => wp_generate_uuid4(),
						'filename'       => $filename,
						'expected_hash'  => $input['hash'],
						'expected_bytes' => $size,
						'reserved_bytes' => $reserved,
						'state'          => 'reserved',
						'uploader_id'    => $this->actor,
						'created_at'     => $now,
						'updated_at'     => $now,
					)
				);
				$this->commands->audit( $workspace, 'media.reserved', 'media', $id, 1 );
				return self::public_row( $this->db->object( 'media', $workspace, $id ) );
			}
		);
	}
	/** Replace a failed or unuploaded file reservation without consuming another image slot.
	 *
	 * @param int    $workspace Workspace.
	 * @param int    $id Media.
	 * @param array  $input Expected revision and replacement file facts.
	 * @param string $key Retry identity.
	 * @return array
	 * @throws \InvalidArgumentException When replacement file facts are invalid.
	 */
	public function retry( int $workspace, int $id, array $input, string $key ): array {
		Input::fields( $input, array( 'expected_revision', 'filename', 'hash', 'size' ), array( 'expected_revision', 'filename', 'hash', 'size' ) );
		$expected = Input::id( $input['expected_revision'] );
		$size     = Input::id( $input['size'] );
		$filename = sanitize_text_field( Input::text( $input['filename'], 190 ) );
		if ( ! is_string( $input['hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $input['hash'] ) ) {
			throw new \InvalidArgumentException( 'Use a SHA-256 content hash.' );
		}
		return $this->commands->run(
			$workspace,
			'tgit_edit_journal',
			'media.retry.' . $id,
			$input,
			$key,
			function () use ( $workspace, $id, $input, $expected, $size, $filename ) {
				$row = $this->db->object( 'media', $workspace, $id );
				$this->db->object( 'trades', $workspace, (int) $row['trade_id'] );
				if ( (int) $row['revision'] !== $expected || ! in_array( $row['state'], array( 'reserved', 'failed' ), true ) ) {
					throw new \UnexpectedValueException( 'Reload the image before replacing its pending file.' );
				}
				$health = $this->settings( $workspace );
				$limits = $health['limits'];
				$budget = (int) $limits['max_file_bytes'] + 1048576;
				if ( $size > (int) $limits['max_file_bytes'] || ! in_array( strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ), array( 'jpg', 'jpeg', 'png', 'webp' ), true ) || (int) $health['used_bytes'] - (int) $row['reserved_bytes'] + $budget > (int) $limits['quota_bytes'] ) {
					throw new \InvalidArgumentException( 'Replacement exceeds file or workspace storage limits.' );
				}
				$this->db->update_object(
					'media',
					$workspace,
					$id,
					array(
						'filename'       => $filename,
						'expected_hash'  => $input['hash'],
						'expected_bytes' => $size,
						'state'          => 'reserved',
						'reserved_bytes' => $budget,
						'failure_code'   => null,
						'revision'       => $expected + 1,
						'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->commands->audit( $workspace, 'media.retry', 'media', $id, $expected + 1 );
				return self::public_row( $this->db->object( 'media', $workspace, $id ) );
			}
		);
	}

	/** Finalize a multipart upload, retrying only the selected image.
	 *
	 * @param int    $workspace Workspace.
	 * @param int    $id Media.
	 * @param array  $file PHP upload descriptor.
	 * @param string $key Retry identity.
	 * @return array
	 * @throws \InvalidArgumentException When upload is incomplete or forged.
	 */
	public function upload( int $workspace, int $id, array $file, string $key ): array {
		$this->commands->authorize( $workspace, 'tgit_edit_journal' );
		if ( ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! isset( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			throw new \InvalidArgumentException( 'Upload failed or exceeded the server limit. Retry this image.' );
		}
		$size = filesize( $file['tmp_name'] );
		if ( $size > 10485760 ) {
			throw new \InvalidArgumentException( 'Upload exceeds the hard file limit.' );
		}
		$hash   = hash_file( 'sha256', $file['tmp_name'] );
		$output = null;
		try {
			return $this->commands->run(
				$workspace,
				'tgit_edit_journal',
				'media.upload.' . $id,
				array(
					'size' => $size,
					'hash' => $hash,
				),
				$key,
				function () use ( $workspace, $id, $file, $size, $hash, &$output ) {
					$row = $this->db->object( 'media', $workspace, $id );
					$this->db->object( 'trades', $workspace, (int) $row['trade_id'] );
					if ( ! in_array( $row['state'], array( 'reserved', 'failed' ), true ) ) {
						throw new \UnexpectedValueException( 'Image is no longer awaiting upload.' );
					}
					if ( $size !== (int) $row['expected_bytes'] || ! hash_equals( $row['expected_hash'], $hash ) ) {
						throw new \InvalidArgumentException( 'Upload differs from the reserved file.' );
					}
					$health   = $this->settings( $workspace );
					$settings = $health['limits'];
					$budget   = max( (int) $row['reserved_bytes'], (int) $settings['max_file_bytes'] + 1048576 );
					if ( (int) $health['used_bytes'] - (int) $row['reserved_bytes'] + $budget > (int) $settings['quota_bytes'] ) {
						throw new \InvalidArgumentException( 'Updated settings require additional storage reservation. Workspace quota exceeded.' );
					}
					$this->db->update_object( 'media', $workspace, $id, array( 'reserved_bytes' => $budget ) );
					$this->db->update_object( 'media', $workspace, $id, array( 'state' => 'uploaded' ) );
					$this->db->update_object( 'media', $workspace, $id, array( 'state' => 'validating' ) );
					$this->db->update_object( 'media', $workspace, $id, array( 'state' => 'processing' ) );
					$output = PrivateImages::normalize( $workspace, $file['tmp_name'], $row['filename'], $settings );
					$data   = array(
						'state'          => 'ready',
						'revision'       => (int) $row['revision'] + 1,
						'storage_key'    => $output['original']['key'],
						'thumb_key'      => $output['thumbnail']['key'],
						'content_hash'   => $output['original']['hash'],
						'mime'           => $output['mime'],
						'bytes'          => $output['original']['bytes'],
						'thumb_bytes'    => $output['thumbnail']['bytes'],
						'reserved_bytes' => $output['original']['bytes'] + $output['thumbnail']['bytes'],
						'width'          => $output['original']['width'],
						'height'         => $output['original']['height'],
						'failure_code'   => null,
						'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
					);
					$this->db->update_object( 'media', $workspace, $id, $data );
					$this->commands->audit( $workspace, 'media.ready', 'media', $id, $data['revision'] );
					return self::public_row( $this->db->object( 'media', $workspace, $id ) );
				}
			);
		} catch ( \Throwable $error ) {
			if ( $output ) {
				foreach ( array( 'original', 'thumbnail' ) as $variant ) {
					PrivateImages::remove( $workspace, $output[ $variant ]['key'] );
				}
			}
			// Failure evidence is separate from the rolled-back file finalization.
			try {
				$this->commands->run(
					$workspace,
					'tgit_edit_journal',
					'media.failure.' . $id,
					array( 'hash' => $hash ),
					wp_generate_uuid4(),
					function () use ( $workspace, $id ) {
						$row = $this->db->object( 'media', $workspace, $id );
						if ( in_array( $row['state'], array( 'reserved', 'failed' ), true ) ) {
							$revision = (int) $row['revision'] + 1;
							$this->db->update_object(
								'media',
								$workspace,
								$id,
								array(
									'state'        => 'failed',
									'revision'     => $revision,
									'failure_code' => 'upload_rejected',
									'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
								)
							);
							$this->commands->audit( $workspace, 'media.failed', 'media', $id, $revision );
						}
						return array();
					}
				);
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Secondary audit failure must not replace the original upload failure.
			} catch ( \Throwable $ignored ) {
				/* Preserve original error; retry remains safe. */
			}
			throw $error;
		}
	}
	/** Update metadata, soft delete/cancel, or recover retained bytes.
	 *
	 * @param int    $workspace Workspace.
	 * @param int    $id Media.
	 * @param string $operation Update, delete or restore.
	 * @param array  $input Expected revision plus replacement metadata.
	 * @param string $key Retry identity.
	 * @return array
	 * @throws \InvalidArgumentException When operation is invalid.
	 */
	public function change( int $workspace, int $id, string $operation, array $input, string $key ): array {
		if ( ! in_array( $operation, array( 'update', 'delete', 'restore' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid image operation.' );
		}
		Input::fields( $input, 'update' === $operation ? array( 'expected_revision', 'caption', 'alt_text', 'stage', 'timeframe', 'sort_order' ) : array( 'expected_revision' ), array( 'expected_revision' ) );
		$expected = Input::id( $input['expected_revision'] );
		$metadata = 'update' === $operation ? self::metadata( $input ) : array();
		return $this->commands->run(
			$workspace,
			'tgit_edit_journal',
			'media.' . $operation . '.' . $id,
			$input,
			$key,
			function () use ( $workspace, $id, $operation, $expected, $metadata ) {
				$row = $this->db->object( 'media', $workspace, $id );
				$this->db->object( 'trades', $workspace, (int) $row['trade_id'] );
				if ( (int) $row['revision'] !== $expected || 'purged' === $row['state'] ) {
					throw new \UnexpectedValueException( 'Image changed or is no longer recoverable. Reload.' );
				}
				$data = $metadata;
				if ( 'delete' === $operation ) {
					if ( 'deleted' === $row['state'] ) {
						throw new \UnexpectedValueException( 'Image already deleted.' );
					}
					$data += array(
						'state'          => 'deleted',
						'previous_state' => $row['state'],
						'deleted_at'     => gmdate( 'Y-m-d H:i:s' ),
					);
				} elseif ( 'restore' === $operation ) {
					$days = (int) $this->settings( $workspace )['limits']['trash_days'];
					if ( 'deleted' !== $row['state'] || strtotime( $row['deleted_at'] . ' UTC' ) + $days * DAY_IN_SECONDS <= time() ) {
						throw new \UnexpectedValueException( 'Image trash retention expired.' );
					}
					$data += array(
						'state'          => $row['previous_state'],
						'previous_state' => null,
						'deleted_at'     => null,
					);
				} elseif ( 'deleted' === $row['state'] ) {
					throw new \UnexpectedValueException( 'Restore image before editing.' );
				}
				$data += array(
					'revision'   => $expected + 1,
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				);
				$this->db->update_object( 'media', $workspace, $id, $data );
				$this->commands->audit( $workspace, 'media.' . $operation, 'media', $id, $expected + 1 );
				return self::public_row( $this->db->object( 'media', $workspace, $id ) );
			}
		);
	}
	/** Resolve authenticated content; descriptors never appear in JSON responses.
	 *
	 * @param int    $workspace Workspace.
	 * @param int    $id Media.
	 * @param string $variant Original or thumbnail.
	 * @return array
	 * @throws \InvalidArgumentException When variant is invalid.
	 * @throws \OutOfBoundsException When image is not active or bytes are missing.
	 */
	public function content( int $workspace, int $id, string $variant ): array {
		$this->commands->authorize( $workspace );
		if ( ! in_array( $variant, array( 'original', 'thumbnail' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid image variant.' );
		}
		$row = $this->db->object( 'media', $workspace, $id );
		$this->db->object( 'trades', $workspace, (int) $row['trade_id'] );
		if ( 'ready' !== $row['state'] ) {
			throw new \OutOfBoundsException( 'Image is not available.' );
		}
		$path = PrivateImages::path( $workspace, $row[ 'original' === $variant ? 'storage_key' : 'thumb_key' ] );
		if ( ! is_file( $path ) ) {
			throw new \OutOfBoundsException( 'Image bytes are unavailable.' );
		}
		return array(
			'path'  => $path,
			'mime'  => $row['mime'],
			'bytes' => filesize( $path ),
		);
	}
	/** Owner-triggered retention job; bounded and retry safe after file/DB failures.
	 *
	 * @param int    $workspace Workspace.
	 * @param string $key Retry identity.
	 * @return array
	 */
	public function cleanup( int $workspace, string $key ): array {
		return $this->commands->run(
			$workspace,
			'tgit_manage_members',
			'media.cleanup',
			array(),
			$key,
			function () use ( $workspace ) {
				$days = (int) $this->settings( $workspace )['limits']['trash_days'];
				$rows = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'media' ) . ' WHERE workspace_id = %d AND ((state = %s AND deleted_at < %s) OR (state IN (%s,%s) AND updated_at < %s)) ORDER BY id LIMIT 100', array( $workspace, 'deleted', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ), 'reserved', 'failed', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
				foreach ( $rows as $row ) {
					foreach ( array( 'storage_key', 'thumb_key' ) as $field ) {
						if ( $row[ $field ] ) {
											PrivateImages::remove( $workspace, $row[ $field ] );
						}
					}
					$this->db->update_object(
						'media',
						$workspace,
						(int) $row['id'],
						array(
							'state'          => 'purged',
							'reserved_bytes' => 0,
							'storage_key'    => null,
							'thumb_key'      => null,
							'revision'       => (int) $row['revision'] + 1,
							'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
						)
					);
					$this->commands->audit( $workspace, 'media.purged', 'media', (int) $row['id'], (int) $row['revision'] + 1 );
				}
				return array( 'purged' => count( $rows ) );
			}
		);
	}
}
