interface TestConnectionResponse {
	success: boolean;
	message: string;
}

interface TaskShuntSettingsData {
	ajaxUrl: string;
	nonce: string;
}

declare const taskshuntSettings: TaskShuntSettingsData;

document.addEventListener( 'DOMContentLoaded', () => {
	const button = document.querySelector< HTMLButtonElement >( '#taskshunt-test-connection' );
	const result = document.querySelector< HTMLSpanElement >( '#taskshunt-test-result' );

	if ( ! button || ! result ) {
		return;
	}

	button.addEventListener( 'click', async () => {
		const originalText = button.textContent ?? '';
		button.disabled = true;
		button.textContent = 'Testing…';
		button.classList.add( 'taskshunt-btn-loading' );
		result.textContent = '';
		result.className = 'taskshunt-test-result';

		try {
			const body = new URLSearchParams( {
				action: 'taskshunt_test_connection',
				_ajax_nonce: taskshuntSettings.nonce,
			} );

			const response = await fetch( taskshuntSettings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );

			const data: TestConnectionResponse = await response.json() as TestConnectionResponse;

			result.textContent = data.message;
			result.className = 'taskshunt-test-result ' + ( data.success ? 'taskshunt-test-result--success' : 'taskshunt-test-result--error' );
		} catch {
			result.textContent = 'Request failed. Check your network.';
			result.className = 'taskshunt-test-result taskshunt-test-result--error';
		} finally {
			button.disabled = false;
			button.textContent = originalText;
			button.classList.remove( 'taskshunt-btn-loading' );
		}
	} );
} );
