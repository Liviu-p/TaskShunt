<?php
/**
 * Onboarding checklist — persistent setup guide.
 *
 * @package Stagify\Admin
 */

declare(strict_types=1);

namespace Stagify\Admin;

use Stagify\Admin\Pages\SetupPage;
use Stagify\Api\ReceiverApi;
use Stagify\Contracts\ServerRepositoryInterface;
use Stagify\Domain\PluginMode;

/**
 * Renders a step-by-step checklist until setup is complete.
 */
final class OnboardingChecklist {

	/**
	 * Option key to dismiss the checklist.
	 */
	public const DISMISS_OPTION = 'stagify_onboarding_dismissed';

	/**
	 * Check if the onboarding checklist should be shown.
	 *
	 * @return bool
	 */
	public static function should_show(): bool {
		if ( get_option( self::DISMISS_OPTION, false ) ) {
			return false;
		}

		$mode = SetupPage::get_mode();
		if ( null === $mode ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if all steps are complete.
	 *
	 * @param PluginMode                     $mode       Current plugin mode.
	 * @param ServerRepositoryInterface|null $server_repo Server repository (sender only).
	 * @return bool
	 */
	public static function is_complete( PluginMode $mode, ?ServerRepositoryInterface $server_repo = null ): bool {
		if ( PluginMode::Receiver === $mode ) {
			$api_key = get_option( ReceiverApi::API_KEY_OPTION, '' );
			return '' !== $api_key;
		}

		// Sender — need server configured.
		if ( null === $server_repo ) {
			return false;
		}

		return null !== $server_repo->find();
	}

	/**
	 * Render the sender (staging) checklist.
	 *
	 * @param ServerRepositoryInterface $server_repo Server repository.
	 * @return void
	 */
	public static function render_sender( ServerRepositoryInterface $server_repo ): void {
		if ( ! self::should_show() ) {
			return;
		}

		$mode = SetupPage::get_mode();
		if ( null === $mode || self::is_complete( $mode, $server_repo ) ) {
			return;
		}

		$has_server = null !== $server_repo->find();

		$steps = array(
			array(
				'done'  => true,
				'label' => __( 'Set this site as Staging', 'stagify' ),
				'desc'  => '',
			),
			array(
				'done'  => false,
				'label' => __( 'Set up Production site', 'stagify' ),
				'desc'  => __( 'Install Stagify on your live site, set it as Production, and generate an API key.', 'stagify' ),
			),
			array(
				'done'  => $has_server,
				'label' => __( 'Connect to Production', 'stagify' ),
				'desc'  => $has_server ? '' : sprintf(
					/* translators: %s: settings page URL */
					__( 'Go to <a href="%s">Settings</a> and paste the production URL and API key.', 'stagify' ),
					esc_url( admin_url( 'admin.php?page=stagify-settings' ) )
				),
			),
		);

		self::render_checklist( $steps );
	}

	/**
	 * Render the receiver (production) checklist.
	 *
	 * @return void
	 */
	public static function render_receiver(): void {
		if ( ! self::should_show() ) {
			return;
		}

		$mode = SetupPage::get_mode();
		if ( null === $mode || self::is_complete( $mode ) ) {
			return;
		}

		$api_key = get_option( ReceiverApi::API_KEY_OPTION, '' );
		$has_key = '' !== $api_key;

		$steps = array(
			array(
				'done'  => true,
				'label' => __( 'Set this site as Production', 'stagify' ),
				'desc'  => '',
			),
			array(
				'done'  => $has_key,
				'label' => __( 'Generate an API key', 'stagify' ),
				'desc'  => $has_key ? '' : __( 'Click "Generate API key" above to create one.', 'stagify' ),
			),
			array(
				'done'  => false,
				'label' => __( 'Connect from Staging', 'stagify' ),
				'desc'  => $has_key
					? __( 'Copy the API key above and paste it in the Stagify settings on your staging site.', 'stagify' )
					: __( 'After generating the key, copy it and paste it on your staging site.', 'stagify' ),
			),
		);

		self::render_checklist( $steps );
	}

	/**
	 * Render the checklist HTML.
	 *
	 * @param array<int, array{done: bool, label: string, desc: string}> $steps Steps to render.
	 * @return void
	 */
	private static function render_checklist( array $steps ): void {
		$done_count  = 0;
		$total_count = count( $steps );
		foreach ( $steps as $step ) {
			if ( $step['done'] ) {
				++$done_count;
			}
		}

		echo '<div class="stagify-checklist">';

		// Header.
		printf(
			'<div class="stagify-checklist-header">'
			. '<div>'
			. '<strong>%s</strong>'
			. '<span class="stagify-checklist-progress">%s</span>'
			. '</div>'
			. '<div class="stagify-checklist-bar"><div class="stagify-checklist-bar-fill" style="width:%d%%"></div></div>'
			. '</div>',
			esc_html__( 'Getting started', 'stagify' ),
			esc_html( sprintf(
				/* translators: 1: completed steps, 2: total steps */
				__( '%1$d of %2$d complete', 'stagify' ),
				$done_count,
				$total_count
			) ),
			$total_count > 0 ? (int) ( ( $done_count / $total_count ) * 100 ) : 0
		);

		// Steps.
		echo '<div class="stagify-checklist-steps">';

		foreach ( $steps as $index => $step ) {
			$class = $step['done'] ? 'stagify-checklist-step--done' : '';
			$icon  = $step['done']
				? '<span class="dashicons dashicons-yes-alt stagify-check-done"></span>'
				: '<span class="stagify-check-num">' . ( $index + 1 ) . '</span>';

			printf(
				'<div class="stagify-checklist-step %s">%s<div><strong>%s</strong>',
				esc_attr( $class ),
				$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$step['done'] ? '<s>' . esc_html( $step['label'] ) . '</s>' : esc_html( $step['label'] )
			);

			if ( '' !== $step['desc'] && ! $step['done'] ) {
				// Description may contain a link, so use wp_kses.
				echo '<p>' . wp_kses( $step['desc'], array( 'a' => array( 'href' => array() ) ) ) . '</p>';
			}

			echo '</div></div>';
		}

		echo '</div></div>';
	}
}
