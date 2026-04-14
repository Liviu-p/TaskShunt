interface TestConnectionResponse {
	success: boolean;
	message: string;
}

interface StagifySettingsData {
	ajaxUrl: string;
	nonce: string;
}

declare const stagifySettings: StagifySettingsData;

document.addEventListener( 'DOMContentLoaded', () => {
	const button = document.querySelector< HTMLButtonElement >( '#stagify-test-connection' );
	const result = document.querySelector< HTMLSpanElement >( '#stagify-test-result' );

	if ( ! button || ! result ) {
		return;
	}

	button.addEventListener( 'click', async () => {
		const originalText = button.textContent ?? '';
		button.disabled = true;
		button.textContent = 'Testing…';
		button.classList.add( 'stagify-btn-loading' );
		result.textContent = '';
		result.className = 'stagify-test-result';

		try {
			const body = new URLSearchParams( {
				action: 'stagify_test_connection',
				_ajax_nonce: stagifySettings.nonce,
			} );

			const response = await fetch( stagifySettings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );

			const data: TestConnectionResponse = await response.json() as TestConnectionResponse;

			result.textContent = data.message;
			result.className = 'stagify-test-result ' + ( data.success ? 'stagify-test-result--success' : 'stagify-test-result--error' );
		} catch {
			result.textContent = 'Request failed. Check your network.';
			result.className = 'stagify-test-result stagify-test-result--error';
		} finally {
			button.disabled = false;
			button.textContent = originalText;
			button.classList.remove( 'stagify-btn-loading' );
		}
	} );
} );
