<?php
/**
 * Private normalized local images (MED 02,04-06).
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

/** Filesystem adapter; no public uploads, filenames or URLs. */
final class PrivateImages {
	/** Validate configured storage outside all known served roots.
	 *
	 * @param bool $writing Whether allocation or deletion needs write permission.
	 * @return string
	 * @throws \RuntimeException When storage is unavailable or public.
	 */
	public static function root( bool $writing = false ): string {
		$root = defined( 'TGIT_PRIVATE_MEDIA_DIR' ) ? realpath( TGIT_PRIVATE_MEDIA_DIR ) : false;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Private storage must be writable without FTP or a public uploads fallback.
		if ( ! $root || ! is_dir( $root ) || ! is_readable( $root ) || ( $writing && ! is_writable( $root ) ) ) {
			throw new \RuntimeException( 'Private image storage requires an accessible TGIT_PRIVATE_MEDIA_DIR outside the web root with appropriate permissions.' );
		}
		$normalized = strtolower( str_replace( '\\', '/', $root ) );
		$served     = array( ABSPATH, WP_CONTENT_DIR );
		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$document_root = realpath( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) );
			if ( ! $document_root ) {
				throw new \RuntimeException( 'Cannot resolve the web root for private storage.' );
			}
			$served[] = $document_root;
		} elseif ( 'cli' !== PHP_SAPI ) {
			throw new \RuntimeException( 'Cannot verify the web root for private storage.' );
		}
		foreach ( $served as $directory ) {
			$directory = realpath( $directory );
			if ( ! $directory ) {
				continue;
			}
			$directory = strtolower( rtrim( str_replace( '\\', '/', $directory ), '/' ) );
			if ( $normalized === $directory || str_starts_with( $normalized, $directory . '/' ) || str_starts_with( $directory, rtrim( $normalized, '/' ) . '/' ) ) {
				throw new \RuntimeException( 'Private image storage must be separate from all public roots.' );
			}
		}
		return $root;
	}
	/** Resolve only a generated workspace-scoped storage key.
	 *
	 * @param int    $workspace Workspace.
	 * @param string $key Random key.
	 * @param bool   $writing Whether a missing workspace directory may be allocated.
	 * @return string
	 * @throws \InvalidArgumentException When key is invalid.
	 * @throws \RuntimeException When a workspace directory is unsafe.
	 */
	public static function path( int $workspace, string $key, bool $writing = false ): string {
		if ( $workspace < 1 || ! preg_match( '/^[a-f0-9]{48}\.(?:jpg|png|webp)$/D', $key ) ) {
			throw new \InvalidArgumentException( 'Invalid private image key.' );
		}
		$root      = self::root( $writing );
		$directory = $root . DIRECTORY_SEPARATOR . $workspace;
		if ( is_link( $directory ) ) {
			throw new \RuntimeException( 'Private workspace directory cannot be a symbolic link.' );
		}
		if ( ! is_dir( $directory ) && ! $writing ) {
			return $directory . DIRECTORY_SEPARATOR . $key;
		}
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			throw new \RuntimeException( 'Private workspace directory could not be created.' );
		}
		$resolved = realpath( $directory );
		if ( ! $resolved || strtolower( str_replace( '\\', '/', $resolved ) ) !== strtolower( str_replace( '\\', '/', $directory ) ) ) {
			throw new \RuntimeException( 'Private workspace path is not contained in storage.' );
		}
		return $resolved . DIRECTORY_SEPARATOR . $key;
	}
	/** Decode and re-encode bounded originals and thumbnails, stripping metadata.
	 *
	 * @param int    $workspace Workspace.
	 * @param string $source Uploaded temporary file.
	 * @param string $filename Escaped metadata only.
	 * @param array  $settings Workspace bounds.
	 * @return array
	 * @throws \InvalidArgumentException When image validation fails.
	 * @throws \RuntimeException When encoding fails.
	 * @throws \Throwable Propagates failures after cleaning generated files.
	 */
	public static function normalize( int $workspace, string $source, string $filename, array $settings ): array {
		self::root( true );
		if ( ! extension_loaded( 'gd' ) ) {
			throw new \RuntimeException( 'PHP GD is required for image normalization.' );
		}
		$size = filesize( $source );
		if ( ! $size || $size > (int) $settings['max_file_bytes'] ) {
			throw new \InvalidArgumentException( 'Image exceeds the configured file limit.' );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Decoder diagnostics on malformed uploads must not reveal filesystem paths.
		$header    = @getimagesize( $source );
		$formats   = array(
			IMAGETYPE_JPEG => array( 'jpg', 'image/jpeg' ),
			IMAGETYPE_PNG  => array( 'png', 'image/png' ),
			IMAGETYPE_WEBP => array( 'webp', 'image/webp' ),
		);
		$format    = $header ? ( $formats[ $header[2] ] ?? null ) : null;
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! $format || ( $header['mime'] ?? '' ) !== $format[1] || ! in_array( $extension, 'jpg' === $format[0] ? array( 'jpg', 'jpeg' ) : array( $format[0] ), true ) ) {
			throw new \InvalidArgumentException( 'Use a genuine JPEG, PNG or WebP image with a matching filename extension.' );
		}
		$pixels = $header[0] * $header[1];
		$limit  = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $pixels < 1 || $pixels > (int) $settings['max_pixels'] ) {
			throw new \InvalidArgumentException( 'Image dimensions exceed the configured pixel limit. Resize the image and retry this image.' );
		}
		if ( $limit > 0 && $pixels * 12 + memory_get_usage( true ) + 16777216 > $limit ) {
			throw new \InvalidArgumentException( 'Not enough PHP memory to process this image. Resize it to about 1600 pixels on its longest side, or ask the host to raise PHP memory_limit to at least 256M, then retry this image.' );
		}
	 // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded uploaded file is decoded locally, never fetched from a URL.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Native decoder diagnostics are not safe API output.
		$decoded = @imagecreatefromstring( file_get_contents( $source ) );
		if ( ! $decoded ) {
			throw new \InvalidArgumentException( 'Image decoder rejected the file.' );
		}
		$keys = array();
		try {
			foreach ( array(
				'original'  => 4096,
				'thumbnail' => 512,
			) as $variant => $bound ) {
				$ratio  = min( 1, $bound / max( $header[0], $header[1] ) );
				$width  = max( 1, (int) round( $header[0] * $ratio ) );
				$height = max( 1, (int) round( $header[1] * $ratio ) );
				$canvas = imagecreatetruecolor( $width, $height );
				try {
					imagealphablending( $canvas, false );
					imagesavealpha( $canvas, true );
					imagecopyresampled( $canvas, $decoded, 0, 0, 0, 0, $width, $height, $header[0], $header[1] );
					$key              = bin2hex( random_bytes( 24 ) ) . '.' . $format[0];
					$keys[ $variant ] = $key;
					$path             = self::path( $workspace, $key, true );
					$encoded          = 'jpg' === $format[0] ? imagejpeg( $canvas, $path, 85 ) : ( 'png' === $format[0] ? imagepng( $canvas, $path, 6 ) : imagewebp( $canvas, $path, 85 ) );
					if ( ! $encoded ) {
						throw new \RuntimeException( 'Image encoding failed.' );
					}
					if ( filesize( $path ) > ( 'original' === $variant ? (int) $settings['max_file_bytes'] : 1048576 ) ) {
						throw new \InvalidArgumentException( 'Normalized image exceeds its storage limit. Resize the image and retry with the replacement file.' );
					}
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Generated private images require restrictive local permissions.
					chmod( $path, 0600 );
					$output[ $variant ] = array(
						'key'    => $key,
						'bytes'  => filesize( $path ),
						'width'  => $width,
						'height' => $height,
						'hash'   => hash_file( 'sha256', $path ),
					);
				} finally {
					imagedestroy( $canvas );
				}
			}
			return $output + array( 'mime' => $format[1] );
		} catch ( \Throwable $error ) {
			foreach ( $keys as $key ) {
				self::remove( $workspace, $key );
			}
			throw $error;
		} finally {
			imagedestroy( $decoded );
		}
	}
	/** Remove a known private object; no recursive deletes.
	 *
	 * @param int    $workspace Workspace.
	 * @param string $key Random key.
	 * @return void
	 * @throws \RuntimeException When private bytes cannot be removed.
	 */
	public static function remove( int $workspace, string $key ): void {
		self::root( true );
		$path = self::path( $workspace, $key );
		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Private generated paths must bypass public attachment filters and return controlled failures.
			if ( ! @unlink( $path ) ) {
				throw new \RuntimeException( 'Private image deletion failed. Retained quota cannot be released.' );
			}
			clearstatcache( true, $path );
		}
	}
}
