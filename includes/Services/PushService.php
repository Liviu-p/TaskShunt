<?php
/**
 * Push service.
 *
 * @package Stagify\Services
 */

declare(strict_types=1);

namespace Stagify\Services;

use Stagify\Contracts\EventDispatcherInterface;
use Stagify\Contracts\ServerRepositoryInterface;
use Stagify\Contracts\TaskItemRepositoryInterface;
use Stagify\Contracts\TaskRepositoryInterface;
use Stagify\Domain\PushResult;
use Stagify\Domain\Task;
use Stagify\Domain\TaskStatus;
use Stagify\Events\TaskFailed;
use Stagify\Events\TaskPushed;

/**
 * Pushes a task to the production server. This is what happens when you click "Push now":
 *
 *  1. Loads the task and all its items from the database.
 *  2. Serializes everything into a JSON payload.
 *  3. Sends an HTTP POST to {server_url}/wp-json/stagify/v1/receive with the API key header.
 *  4. Reads the receiver's response — checks if each item succeeded or failed.
 *  5. Updates the task status to Pushed (success) or Failed (error).
 */
final class PushService {

	/**
	 * Receive endpoint path appended to the server URL.
	 */
	private const RECEIVE_PATH = '/wp-json/stagify/v1/receive';

	/**
	 * Request timeout in seconds.
	 */
	private const TIMEOUT = 300;

	/**
	 * Create the push service.
	 *
	 * @param TaskRepositoryInterface     $task_repository      Task repository.
	 * @param TaskItemRepositoryInterface $task_item_repository Task item repository.
	 * @param ServerRepositoryInterface   $server_repository    Server repository.
	 * @param EventDispatcherInterface    $event_dispatcher     Event dispatcher.
	 */
	public function __construct(
		private readonly TaskRepositoryInterface $task_repository,
		private readonly TaskItemRepositoryInterface $task_item_repository,
		private readonly ServerRepositoryInterface $server_repository,
		private readonly EventDispatcherInterface $event_dispatcher,
	) {}

	/**
	 * Push a task to the configured server.
	 *
	 * @param int $task_id Task ID to push.
	 * @return PushResult
	 */
	public function push( int $task_id ): PushResult {
		$task = $this->task_repository->find_by_id( $task_id );

		if ( null === $task ) {
			return new PushResult( false, 0, __( 'Task not found.', 'stagify' ) );
		}

		$server = $this->server_repository->find();

		if ( null === $server ) {
			return new PushResult( false, 0, __( 'No server configured.', 'stagify' ) );
		}

		$this->task_repository->update_status( $task_id, TaskStatus::Pushing );

		$url      = rtrim( $server->url->get_value(), '/' ) . self::RECEIVE_PATH;
		$body     = $this->build_request_body( $task );
		$response = $this->send_request( $url, $server->api_key->get_value(), $body );

		return $this->handle_response( $response, $task );
	}

	/**
	 * Build the JSON request body from the task and its items.
	 *
	 * @param Task $task The task to serialize.
	 * @return string JSON-encoded request body.
	 */
	private function build_request_body( Task $task ): string {
		$items    = $this->task_item_repository->find_by_task( $task->id );
		$item_arr = array();

		foreach ( $items as $item ) {
			$item_arr[] = array(
				'item_type'   => $item->type->value,
				'action'      => $item->action->value,
				'object_type' => $item->object_type,
				'object_id'   => (int) $item->object_id,
				'payload'     => json_decode( $item->payload, true ),
			);
		}

		return (string) wp_json_encode(
			array(
				'task_id'    => $task->id,
				'task_title' => $task->title,
				'site_url'   => site_url(),
				'items'      => $item_arr,
			)
		);
	}

	/**
	 * Send the HTTP POST request to the receiver.
	 *
	 * @param string $url     Full receive endpoint URL.
	 * @param string $api_key API key for the X-Stagify-API-Key header.
	 * @param string $body    JSON-encoded request body.
	 * @return array|\WP_Error wp_remote_post response or WP_Error.
	 */
	private function send_request( string $url, string $api_key, string $body ): array|\WP_Error {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		return wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'X-Stagify-API-Key' => $api_key,
				),
				'body'    => $body,
			)
		);
	}

	/**
	 * Process the HTTP response and update task status accordingly.
	 *
	 * @param array|\WP_Error $response HTTP response or error.
	 * @param Task            $task     The task being pushed.
	 * @return PushResult
	 */
	private function handle_response( array|\WP_Error $response, Task $task ): PushResult {
		if ( is_wp_error( $response ) ) {
			return $this->fail( $task, 0, $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = $decoded['message'] ?? '';
			/* translators: 1: HTTP status code, 2: error message from server */
			return $this->fail( $task, $code, sprintf( __( 'HTTP %1$d: %2$s', 'stagify' ), $code, $message ) );
		}

		// The receiver returns {success: bool, results: [{success, message}, ...]} for each item.
		// Even with HTTP 200, individual items may have failed (e.g. a plugin not found on WordPress.org).
		if ( is_array( $decoded ) && isset( $decoded['success'] ) && false === $decoded['success'] ) {
			$failed_messages = array();
			foreach ( ( $decoded['results'] ?? array() ) as $result ) {
				if ( ! empty( $result['message'] ) && empty( $result['success'] ) ) {
					$failed_messages[] = $result['message'];
				}
			}

			$message = ! empty( $failed_messages )
				? implode( '; ', $failed_messages )
				: __( 'One or more items failed on the receiver.', 'stagify' );

			return $this->fail( $task, $code, $message );
		}

		return $this->succeed( $task, $code );
	}

	/**
	 * Mark a task as pushed and dispatch the success event.
	 *
	 * @param Task $task The pushed task.
	 * @param int  $code HTTP response code.
	 * @return PushResult
	 */
	private function succeed( Task $task, int $code ): PushResult {
		$this->task_repository->update_status( $task->id, TaskStatus::Pushed );
		$this->task_repository->clear_active();
		$this->log_push( $task->id, $code, __( 'Task pushed successfully.', 'stagify' ) );
		$this->event_dispatcher->dispatch( new TaskPushed( $task, $code ) );

		return new PushResult( true, $code, __( 'Task pushed successfully.', 'stagify' ) );
	}

	/**
	 * Mark a task as failed and dispatch the failure event.
	 *
	 * @param Task   $task    The failed task.
	 * @param int    $code    HTTP response code (0 if no response).
	 * @param string $message Human-readable failure reason.
	 * @return PushResult
	 */
	private function fail( Task $task, int $code, string $message ): PushResult {
		$this->task_repository->update_status( $task->id, TaskStatus::Failed );
		$this->log_push( $task->id, $code, $message );
		$this->event_dispatcher->dispatch( new TaskFailed( $task, $message ) );

		return new PushResult( false, $code, $message );
	}

	/**
	 * Write a row to the push log table.
	 *
	 * @param int    $task_id Task ID.
	 * @param int    $code    HTTP response code.
	 * @param string $message Result message.
	 * @return void
	 */
	private function log_push( int $task_id, int $code, string $message ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'stagify_push_log',
			array(
				'task_id'          => $task_id,
				'pushed_at'        => current_time( 'mysql' ),
				'http_code'        => $code,
				'response_message' => $message,
			),
			array( '%d', '%s', '%d', '%s' )
		);
	}
}
