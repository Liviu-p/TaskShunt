<?php
/**
 * Test connection AJAX action.
 *
 * @package TaskShunt\Admin\Ajax
 */

declare(strict_types=1);

namespace TaskShunt\Admin\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Contracts\ServerRepositoryInterface;

/**
 * Handles the wp_ajax_taskshunt_test_connection request.
 *
 * PINGs the configured server and returns a JSON response.
 */
final class TestConnectionAction {

	/**
	 * Ping endpoint path appended to the server URL.
	 */
	private const PING_ROUTE = '/taskshunt/v1/ping';

	/**
	 * Request timeout in seconds.
	 */
	private const TIMEOUT = 10;

	/**
	 * Create the action handler.
	 *
	 * @param ServerRepositoryInterface $server_repository Server repository.
	 */
	public function __construct(
		private readonly ServerRepositoryInterface $server_repository,
	) {}

	/**
	 * Handle the AJAX request.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_ajax_referer( 'taskshunt_test_connection' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'taskshunt' ) ), 403 );
		}

		$server = $this->server_repository->find();

		if ( null === $server ) {
			wp_send_json_error( array( 'message' => __( 'No server configured.', 'taskshunt' ) ), 400 );
		}

		$url = rtrim( $server->url->get_value(), '/' ) . '/?rest_route=' . rawurlencode( self::PING_ROUTE );
		wp_send_json( $this->ping( $url, $server->api_key->get_value() ) );
	}

	/**
	 * Perform the HTTP ping and return a response array.
	 *
	 * @param string $url     Full ping endpoint URL.
	 * @param string $api_key API key sent in the request header.
	 * @return array{success: bool, message: string}
	 */
	private function ping( string $url, string $api_key ): array {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'X-TaskShunt-API-Key' => $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				/* translators: %s: error message returned by the HTTP request */
				'message' => sprintf( __( 'Connection failed: %s', 'taskshunt' ), $response->get_error_message() ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return array(
				'success' => true,
				'message' => __( 'Connection successful.', 'taskshunt' ),
			);
		}

		return array(
			'success' => false,
			/* translators: %d: HTTP status code returned by the server */
			'message' => sprintf( __( 'Server responded with HTTP %d.', 'taskshunt' ), $code ),
		);
	}
}
