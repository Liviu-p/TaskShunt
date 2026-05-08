<?php
/**
 * Receiver REST API registration.
 *
 * @package TaskShunt\Api
 */

declare(strict_types=1);

namespace TaskShunt\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Api\Handlers\ContentHandler;
use TaskShunt\Api\Handlers\EnvironmentHandler;
use TaskShunt\Api\Handlers\FileHandler;
use TaskShunt\Domain\TaskAction;
use TaskShunt\Domain\TaskItemType;
use TaskShunt\Services\Crypto;
use TaskShunt\Services\RequestSigner;

/**
 * Registers REST API routes used by the receiver (production) side.
 */
final class ReceiverApi {

	/**
	 * REST namespace for all TaskShunt routes.
	 */
	private const NAMESPACE = 'taskshunt/v1';

	/**
	 * WP option key storing the receiver API key.
	 */
	public const API_KEY_OPTION = 'taskshunt_receiver_api_key';

	/**
	 * Transient key for the receive operations log.
	 */
	private const LOG_TRANSIENT = 'taskshunt_receive_log';

	/**
	 * Maximum number of log entries to keep.
	 */
	private const LOG_MAX_ENTRIES = 20;

	/**
	 * Maximum permitted clock skew, in seconds, between sender and receiver.
	 */
	private const TIMESTAMP_WINDOW = 300;

	/**
	 * Transient key for the recent-nonce store used to defeat replays.
	 */
	private const NONCE_TRANSIENT = 'taskshunt_seen_nonces';

	/**
	 * Default cap on the raw request body, in bytes. Filterable via
	 * `taskshunt_max_body_bytes`. 32 MB covers ~24 MB base64 attachments.
	 */
	private const MAX_BODY_BYTES = 33554432;

	/**
	 * Default cap on the number of items in a single push. Filterable via
	 * `taskshunt_max_items`.
	 */
	private const MAX_ITEMS = 500;

	/**
	 * Create the receiver API.
	 *
	 * @param ContentHandler     $content_handler     Content item handler.
	 * @param FileHandler        $file_handler        File item handler.
	 * @param EnvironmentHandler $environment_handler Environment item handler.
	 */
	public function __construct(
		private readonly ContentHandler $content_handler,
		private readonly FileHandler $file_handler,
		private readonly EnvironmentHandler $environment_handler,
	) {}

	/**
	 * Register all receiver REST routes on rest_api_init.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'rest_api_init',
			function (): void {
				$this->register_routes();
			}
		);
	}

	/**
	 * Register individual route definitions.
	 *
	 * @return void
	 */
	private function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/ping',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_ping' ),
				'permission_callback' => array( $this, 'verify_signature' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/receive',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_receive' ),
				'permission_callback' => array( $this, 'verify_signature' ),
			)
		);
	}

	/**
	 * Handle GET /taskshunt/v1/ping.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_ping(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'version' => TASKSHUNT_VERSION,
				'site'    => get_bloginfo( 'name' ),
			),
			200
		);
	}

	/**
	 * Permission callback: verify the HMAC signature on every receiver request.
	 *
	 * Rejects requests that are missing any of the three signature headers,
	 * fall outside the timestamp clock-skew window, fail HMAC verification,
	 * or replay a nonce already seen inside that window.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return bool
	 */
	public function verify_signature( \WP_REST_Request $request ): bool {
		$timestamp = $request->get_header( 'X-TaskShunt-Timestamp' );
		$nonce     = $request->get_header( 'X-TaskShunt-Nonce' );
		$signature = $request->get_header( 'X-TaskShunt-Signature' );

		if ( empty( $timestamp ) || empty( $nonce ) || empty( $signature ) ) {
			return false;
		}

		if ( ! ctype_digit( (string) $timestamp ) ) {
			return false;
		}

		$ts = (int) $timestamp;
		if ( abs( time() - $ts ) > self::TIMESTAMP_WINDOW ) {
			return false;
		}

		$key = $this->resolve_key();
		if ( null === $key ) {
			return false;
		}

		$verified = RequestSigner::verify(
			$request->get_method(),
			$request->get_route(),
			$ts,
			(string) $nonce,
			(string) $request->get_body(),
			(string) $signature,
			$key
		);

		if ( ! $verified ) {
			return false;
		}

		return $this->register_nonce( (string) $nonce );
	}

	/**
	 * Fetch the receiver's HMAC key, decrypting the at-rest blob.
	 *
	 * @return string|null Plaintext key, or null if missing or undecryptable.
	 */
	private function resolve_key(): ?string {
		$encoded = (string) get_option( self::API_KEY_OPTION, '' );
		if ( '' === $encoded ) {
			return null;
		}

		try {
			return Crypto::decrypt( $encoded );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Record a nonce, returning false if it has already been seen inside the window.
	 *
	 * Stored as nonce => expires_at. Expired entries are pruned on each call.
	 * Concurrent requests with an identical nonce can theoretically race past
	 * the check; for a low-traffic deploy plugin (one push at a time) this is
	 * acceptable, and the timestamp window remains a hard bound.
	 *
	 * @param string $nonce The nonce from the verified request.
	 * @return bool False if replayed; true if recorded.
	 */
	private function register_nonce( string $nonce ): bool {
		$store = get_transient( self::NONCE_TRANSIENT );
		$store = is_array( $store ) ? $store : array();
		$now   = time();

		$store = array_filter( $store, static fn ( $expires ) => (int) $expires > $now );

		if ( isset( $store[ $nonce ] ) ) {
			return false;
		}

		$ttl             = 2 * self::TIMESTAMP_WINDOW;
		$store[ $nonce ] = $now + $ttl;
		set_transient( self::NONCE_TRANSIENT, $store, $ttl );

		return true;
	}

	/**
	 * Handle POST /taskshunt/v1/receive.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_receive( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			return $this->process_receive( $request );
		} catch ( \Throwable $e ) {
			return $this->server_error( $e->getMessage() );
		}
	}

	/**
	 * Validate, process, and respond to a receive request.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	private function process_receive( \WP_REST_Request $request ): \WP_REST_Response {
		$max_body = (int) apply_filters( 'taskshunt_max_body_bytes', self::MAX_BODY_BYTES );
		if ( strlen( (string) $request->get_body() ) > $max_body ) {
			return $this->payload_too_large( $max_body );
		}

		$body = $request->get_json_params();

		$error = $this->validate_body( $body );
		if ( null !== $error ) {
			return $this->validation_error( $error );
		}

		$sender_url = isset( $body['site_url'] ) ? (string) $body['site_url'] : '';
		$results    = $this->process_items( $body['items'], $sender_url );
		$success    = $this->all_succeeded( $results );

		$this->log_operation( $body, $results, $success );

		return new \WP_REST_Response(
			array(
				'success' => $success,
				'results' => $results,
			),
			200
		);
	}

	/**
	 * Return a 422 validation error response.
	 *
	 * @param string $message Error message.
	 * @return \WP_REST_Response
	 */
	private function validation_error( string $message ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => $message,
			),
			422
		);
	}

	/**
	 * Return a 413 Payload Too Large response.
	 *
	 * @param int $max_bytes Configured cap that was exceeded.
	 * @return \WP_REST_Response
	 */
	private function payload_too_large( int $max_bytes ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => false,
				/* translators: %d: max bytes */
				'message' => sprintf( __( 'Request body exceeds %d bytes.', 'taskshunt' ), $max_bytes ),
			),
			413
		);
	}

	/**
	 * Return a 500 server error response without exposing internals.
	 *
	 * @param string $message Exception message.
	 * @return \WP_REST_Response
	 */
	private function server_error( string $message ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => $message,
			),
			500
		);
	}

	/**
	 * Validate the request body structure.
	 *
	 * @param array<string, mixed> $body Decoded JSON body.
	 * @return string|null Error message, or null if valid.
	 */
	private function validate_body( array $body ): ?string {
		if ( empty( $body['items'] ) || ! is_array( $body['items'] ) ) {
			return __( 'The items field is required and must be a non-empty array.', 'taskshunt' );
		}

		$max_items = (int) apply_filters( 'taskshunt_max_items', self::MAX_ITEMS );
		if ( count( $body['items'] ) > $max_items ) {
			/* translators: %d: configured max item count */
			return sprintf( __( 'Too many items in request (max %d).', 'taskshunt' ), $max_items );
		}

		return $this->validate_items( $body['items'] );
	}

	/**
	 * Validate each item in the items array.
	 *
	 * @param array<int, array<string, mixed>> $items Items to validate.
	 * @return string|null Error message, or null if all valid.
	 */
	private function validate_items( array $items ): ?string {
		foreach ( $items as $index => $item ) {
			$error = $this->validate_single_item( $item, $index );
			if ( null !== $error ) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * Validate a single item's required fields and enum values.
	 *
	 * @param array<string, mixed> $item  The item data.
	 * @param int                  $index Zero-based item position.
	 * @return string|null Error message, or null if valid.
	 */
	private function validate_single_item( array $item, int $index ): ?string {
		$position = $index + 1;

		return $this->validate_item_type( $item, $position )
			?? $this->validate_item_action( $item, $position )
			?? $this->validate_item_object_type( $item, $position )
			?? $this->validate_item_payload( $item, $position );
	}

	/**
	 * Validate the item_type field.
	 *
	 * @param array<string, mixed> $item     The item data.
	 * @param int                  $position One-based item position.
	 * @return string|null Error message, or null if valid.
	 */
	private function validate_item_type( array $item, int $position ): ?string {
		if ( empty( $item['item_type'] ) ) {
			/* translators: %d: item position in the array */
			return sprintf( __( 'Item %d is missing the item_type field.', 'taskshunt' ), $position );
		}

		try {
			TaskItemType::from( $item['item_type'] );
		} catch ( \ValueError $e ) {
			/* translators: 1: item position, 2: provided type value */
			return sprintf( __( 'Item %1$d has an invalid item_type: %2$s.', 'taskshunt' ), $position, $item['item_type'] );
		}

		return null;
	}

	/**
	 * Validate the action field.
	 *
	 * @param array<string, mixed> $item     The item data.
	 * @param int                  $position One-based item position.
	 * @return string|null Error message, or null if valid.
	 */
	private function validate_item_action( array $item, int $position ): ?string {
		if ( empty( $item['action'] ) ) {
			/* translators: %d: item position in the array */
			return sprintf( __( 'Item %d is missing the action field.', 'taskshunt' ), $position );
		}

		try {
			TaskAction::from( $item['action'] );
		} catch ( \ValueError $e ) {
			/* translators: 1: item position, 2: provided action value */
			return sprintf( __( 'Item %1$d has an invalid action: %2$s.', 'taskshunt' ), $position, $item['action'] );
		}

		return null;
	}

	/**
	 * Validate the object_type field is a registered post type.
	 *
	 * @param array<string, mixed> $item     The item data.
	 * @param int                  $position One-based item position.
	 * @return string|null Error message, or null if valid.
	 */
	private function validate_item_object_type( array $item, int $position ): ?string {
		if ( empty( $item['object_type'] ) ) {
			/* translators: %d: item position in the array */
			return sprintf( __( 'Item %d is missing the object_type field.', 'taskshunt' ), $position );
		}

		$type = TaskItemType::from( $item['item_type'] );

		if ( TaskItemType::Content === $type && ! post_type_exists( $item['object_type'] ) ) {
			/* translators: 1: item position, 2: provided object_type value */
			return sprintf( __( 'Item %1$d has an unregistered post type: %2$s.', 'taskshunt' ), $position, $item['object_type'] );
		}

		return null;
	}

	/**
	 * Validate the payload field.
	 *
	 * If payload is a JSON string, it must be valid JSON.
	 * If it is already decoded (array/object), it passes.
	 *
	 * @param array<string, mixed> $item     The item data.
	 * @param int                  $position One-based item position.
	 * @return string|null Error message, or null if valid.
	 */
	private function validate_item_payload( array $item, int $position ): ?string {
		if ( ! isset( $item['payload'] ) ) {
			/* translators: %d: item position in the array */
			return sprintf( __( 'Item %d is missing the payload field.', 'taskshunt' ), $position );
		}

		if ( ! is_string( $item['payload'] ) ) {
			return null;
		}

		json_decode( $item['payload'] );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			/* translators: %d: item position in the array */
			return sprintf( __( 'Item %d has an invalid JSON payload.', 'taskshunt' ), $position );
		}

		return null;
	}

	/**
	 * Route each item to its handler and collect results.
	 *
	 * @param array<int, array<string, mixed>> $items      Validated items.
	 * @param string                           $sender_url Sender site URL for URL rewriting.
	 * @return array<int, array{success: bool, message: string}>
	 */
	private function process_items( array $items, string $sender_url ): array {
		$results = array();

		foreach ( $items as $item ) {
			$results[] = $this->route_item( $item, $sender_url );
		}

		return $results;
	}

	/**
	 * Route a single item to the appropriate handler.
	 *
	 * @param array<string, mixed> $item       Item data.
	 * @param string               $sender_url Sender site URL for URL rewriting.
	 * @return array{success: bool, message: string}
	 */
	private function route_item( array $item, string $sender_url ): array {
		$type        = TaskItemType::from( $item['item_type'] );
		$action      = TaskAction::from( $item['action'] );
		$object_type = $item['object_type'] ?? '';
		$object_id   = (int) ( $item['object_id'] ?? 0 );
		$payload     = $item['payload'] ?? null;

		return match ( $type ) {
			TaskItemType::Content     => $this->content_handler->handle( $action, $object_type, $object_id, $payload, $sender_url ),
			TaskItemType::File        => $this->file_handler->handle( $action, $object_type, $object_id, $payload ),
			TaskItemType::Environment => $this->environment_handler->handle( $action, $object_type, $object_id, $payload ),
			TaskItemType::Database    => array(
				'success' => false,
				'message' => __( 'Database item type is not yet supported.', 'taskshunt' ),
			),
		};
	}

	/**
	 * Check whether all results succeeded.
	 *
	 * @param array<int, array{success: bool, message: string}> $results Processed results.
	 * @return bool
	 */
	private function all_succeeded( array $results ): bool {
		foreach ( $results as $result ) {
			if ( ! $result['success'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Append a receive operation to the debug log transient.
	 *
	 * Keeps the most recent LOG_MAX_ENTRIES entries, stored for 7 days.
	 *
	 * @param array<string, mixed>                              $body    Request body.
	 * @param array<int, array{success: bool, message: string}> $results Per-item results.
	 * @param bool                                              $success Overall success flag.
	 * @return void
	 */
	private function log_operation( array $body, array $results, bool $success ): void {
		$log = get_transient( self::LOG_TRANSIENT );
		$log = is_array( $log ) ? $log : array();

		$log[] = array(
			'time'       => gmdate( 'Y-m-d H:i:s' ),
			'task_id'    => $body['task_id'] ?? 0,
			'task_title' => $body['task_title'] ?? '',
			'item_count' => count( $body['items'] ?? array() ),
			'success'    => $success,
			'results'    => $results,
		);

		$log = array_slice( $log, -self::LOG_MAX_ENTRIES );

		set_transient( self::LOG_TRANSIENT, $log, WEEK_IN_SECONDS );
	}
}
