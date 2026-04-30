( () => {
	// New task form toggle (TasksPage).
	const newTaskBtn = document.getElementById( 'taskshunt-new-task-toggle' );
	const createForm = document.getElementById( 'taskshunt-create-form' );
	const createCancel = document.getElementById( 'taskshunt-create-cancel' );

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
	document.querySelectorAll< HTMLButtonElement >( '.taskshunt-payload-toggle' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			const pre = document.getElementById( btn.dataset.target || '' );
			if ( ! pre ) {
				return;
			}
			const hidden = getComputedStyle( pre ).display === 'none';
			pre.style.display = hidden ? 'block' : 'none';
			btn.textContent = hidden
				? ( btn.dataset.labelHide || 'Hide' )
				: ( btn.dataset.labelShow || 'Details' );
		} );
	} );

	// Setup wizard card selection (SetupPage).
	const modeInput = document.getElementById( 'taskshunt-mode-input' ) as HTMLInputElement | null;
	const setupSubmit = document.getElementById( 'taskshunt-setup-submit' );
	const setupBtn = document.getElementById( 'taskshunt-setup-go' );
	const cards = document.querySelectorAll< HTMLElement >( '.taskshunt-card--selectable' );

	if ( modeInput && setupSubmit && setupBtn && cards.length ) {
		cards.forEach( ( card ) => {
			card.addEventListener( 'click', () => {
				cards.forEach( ( c ) => c.classList.remove( 'taskshunt-card--selected' ) );
				card.classList.add( 'taskshunt-card--selected' );
				modeInput.value = card.dataset.mode || '';
				setupBtn.textContent = card.dataset.label || '';
				setupSubmit.style.display = 'block';
			} );
		} );
	}

	// Copy API key to clipboard (ReceiverSettingsPage).
	const copyBtn = document.getElementById( 'taskshunt-copy-key' );
	const keyValue = document.getElementById( 'taskshunt-key-value' );

	if ( copyBtn && keyValue ) {
		copyBtn.addEventListener( 'click', () => {
			const key = keyValue.dataset.key || keyValue.textContent || '';
			const onCopied = () => {
				const orig = copyBtn.textContent;
				copyBtn.textContent = copyBtn.dataset.copied || 'Copied!';
				setTimeout( () => {
					copyBtn.textContent = orig;
				}, 1500 );
			};

			if ( navigator.clipboard?.writeText ) {
				navigator.clipboard.writeText( key ).then( onCopied ).catch( () => {
					fallbackCopy( key ) && onCopied();
				} );
			} else {
				fallbackCopy( key ) && onCopied();
			}
		} );
	}

	function fallbackCopy( text: string ): boolean {
		const textarea = document.createElement( 'textarea' );
		textarea.value = text;
		textarea.style.position = 'fixed';
		textarea.style.opacity = '0';
		document.body.appendChild( textarea );
		textarea.select();
		let ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch {
			// Ignore.
		}
		document.body.removeChild( textarea );
		return ok;
	}

	// Show/hide API key toggle (ReceiverSettingsPage).
	const toggleReceiverKey = document.getElementById( 'taskshunt-toggle-receiver-key' );

	if ( toggleReceiverKey && keyValue ) {
		toggleReceiverKey.addEventListener( 'click', () => {
			const isHidden = ! keyValue.dataset.visible;
			if ( isHidden ) {
				keyValue.textContent = keyValue.dataset.key || '';
				keyValue.dataset.visible = '1';
				toggleReceiverKey.textContent = toggleReceiverKey.dataset.labelHide || 'Hide';
			} else {
				keyValue.innerHTML = '\u2022'.repeat( 20 );
				delete keyValue.dataset.visible;
				toggleReceiverKey.textContent = toggleReceiverKey.dataset.labelShow || 'Show';
			}
		} );
	}

	// Mode switch confirmation (SettingsPage + ReceiverSettingsPage).
	const switchBtn = document.getElementById( 'taskshunt-switch-mode-btn' );
	const modePanel = document.getElementById( 'taskshunt-mode-confirm' );
	const switchCancel = document.getElementById( 'taskshunt-switch-mode-cancel' );

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
	const toggleKeyBtn = document.getElementById( 'taskshunt-toggle-key' );
	const keyInput = document.getElementById( 'taskshunt_api_key' ) as HTMLInputElement | null;

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
