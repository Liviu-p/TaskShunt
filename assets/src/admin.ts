( () => {
	// New task form toggle (TasksPage).
	const newTaskBtn = document.getElementById( 'stagify-new-task-toggle' );
	const createForm = document.getElementById( 'stagify-create-form' );
	const createCancel = document.getElementById( 'stagify-create-cancel' );

	if ( newTaskBtn && createForm && createCancel ) {
		newTaskBtn.addEventListener( 'click', () => {
			newTaskBtn.style.display = 'none';
			createForm.style.display = 'flex';
			createForm.querySelector< HTMLInputElement >( 'input' )?.focus();
		} );
		createCancel.addEventListener( 'click', () => {
			createForm.style.display = 'none';
			newTaskBtn.style.display = 'inline-flex';
		} );
	}

	// Payload detail toggles (TaskDetailPage).
	document.querySelectorAll< HTMLButtonElement >( '.stagify-payload-toggle' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			const pre = document.getElementById( btn.dataset.target || '' );
			if ( ! pre ) {
				return;
			}
			const hidden = pre.style.display === 'none';
			pre.style.display = hidden ? 'block' : 'none';
			btn.textContent = hidden
				? ( btn.dataset.labelHide || 'Hide' )
				: ( btn.dataset.labelShow || 'Details' );
		} );
	} );

	// Setup wizard card selection (SetupPage).
	const modeInput = document.getElementById( 'stagify-mode-input' ) as HTMLInputElement | null;
	const setupSubmit = document.getElementById( 'stagify-setup-submit' );
	const setupBtn = document.getElementById( 'stagify-setup-go' );
	const cards = document.querySelectorAll< HTMLElement >( '.stagify-card--selectable' );

	if ( modeInput && setupSubmit && setupBtn && cards.length ) {
		cards.forEach( ( card ) => {
			card.addEventListener( 'click', () => {
				cards.forEach( ( c ) => c.classList.remove( 'stagify-card--selected' ) );
				card.classList.add( 'stagify-card--selected' );
				modeInput.value = card.dataset.mode || '';
				setupBtn.textContent = card.dataset.label || '';
				setupSubmit.style.display = 'block';
			} );
		} );
	}

	// Copy API key to clipboard (ReceiverSettingsPage).
	const copyBtn = document.getElementById( 'stagify-copy-key' );
	const keyValue = document.getElementById( 'stagify-key-value' );

	if ( copyBtn && keyValue ) {
		copyBtn.addEventListener( 'click', () => {
			navigator.clipboard.writeText( keyValue.textContent || '' ).then( () => {
				const orig = copyBtn.textContent;
				copyBtn.textContent = copyBtn.dataset.copied || 'Copied!';
				setTimeout( () => {
					copyBtn.textContent = orig;
				}, 1500 );
			} );
		} );
	}

	// Mode switch confirmation (SettingsPage + ReceiverSettingsPage).
	const switchBtn = document.getElementById( 'stagify-switch-mode-btn' );
	const modePanel = document.getElementById( 'stagify-mode-confirm' );
	const switchCancel = document.getElementById( 'stagify-switch-mode-cancel' );

	if ( switchBtn && modePanel && switchCancel ) {
		switchBtn.addEventListener( 'click', () => {
			modePanel.style.display = 'block';
			switchBtn.style.display = 'none';
		} );
		switchCancel.addEventListener( 'click', () => {
			modePanel.style.display = 'none';
			switchBtn.style.display = 'inline-flex';
		} );
	}

	// API key show/hide toggle (SettingsPage).
	const toggleKeyBtn = document.getElementById( 'stagify-toggle-key' );
	const keyInput = document.getElementById( 'stagify_api_key' ) as HTMLInputElement | null;

	if ( toggleKeyBtn && keyInput ) {
		toggleKeyBtn.addEventListener( 'click', () => {
			const shown = keyInput.type === 'text';
			keyInput.type = shown ? 'password' : 'text';
			toggleKeyBtn.textContent = shown
				? ( toggleKeyBtn.dataset.labelShow || 'Show' )
				: ( toggleKeyBtn.dataset.labelHide || 'Hide' );
		} );
	}
} )();
