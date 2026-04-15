<?php
/**
 * Post type registry service.
 *
 * @package Stagify\Services
 */

declare(strict_types=1);

namespace Stagify\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the set of public post types Stagify should monitor.
 *
 * Post types are loaded lazily on the first call to get_tracked() and cached
 * for the lifetime of the request.
 */
final class PostTypeRegistry {

	/**
	 * WP option key storing the tracked post type slugs.
	 */
	public const OPTION_KEY = 'stagify_tracked_post_types';

	/**
	 * Cached list of tracked post type slugs, or null before first resolution.
	 *
	 * @var list<string>|null
	 */
	private ?array $tracked = null;

	/**
	 * Return all post types that should be tracked.
	 *
	 * If the option has never been set, defaults to all public post types.
	 *
	 * @return list<string>
	 */
	public function get_tracked(): array {
		if ( null !== $this->tracked ) {
			return $this->tracked;
		}

		$saved = get_option( self::OPTION_KEY, false );

		if ( false === $saved ) {
			$this->tracked = array_values( (array) get_post_types( array( 'public' => true ), 'names' ) );
			return $this->tracked;
		}

		$this->tracked = is_array( $saved ) ? array_values( $saved ) : array();
		return $this->tracked;
	}
}
