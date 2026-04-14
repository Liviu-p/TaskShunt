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
		button.disabled = true;
		result.textContent = '';
		result.removeAttribute( 'style' );

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
			result.style.color = data.success ? '#46b450' : '#dc3232';
			result.style.fontWeight = '600';
			result.style.marginLeft = '10px';
		} catch {
			result.textContent = 'Request failed. Check your network.';
			result.style.color = '#dc3232';
			result.style.fontWeight = '600';
			result.style.marginLeft = '10px';
		} finally {
			button.disabled = false;
		}
	} );
} );
