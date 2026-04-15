interface StagifyModalData {
	ajaxUrl: string;
	nonce: string;
	actionLabels: Record< string, string >;
	typeLabels: Record< string, string >;
	loadingPreview: string;
}

interface ConfirmOptions {
	title?: string;
	message?: string;
	confirm?: string;
	danger?: boolean;
	prompt?: boolean;
	promptDefault?: string;
	promptPlaceholder?: string;
	previewTaskId?: number;
}

interface PreviewItem {
	action: string;
	type: string;
	title?: string;
	object_id: string;
	excerpt?: string;
}

declare const stagifyModal: StagifyModalData;

( () => {
	function getEl( id: string ): HTMLElement | null {
		return document.getElementById( id );
	}

	( window as any ).stagifyConfirm = ( opts: ConfirmOptions ): Promise< boolean | string > => {
		return new Promise( ( resolve ) => {
			const ov = getEl( 'stagify-modal-overlay' );
			const ti = getEl( 'stagify-modal-title' );
			const msg = getEl( 'stagify-modal-message' );
			const pv = getEl( 'stagify-modal-preview' );
			const ok = getEl( 'stagify-modal-ok' ) as HTMLButtonElement | null;
			const cn = getEl( 'stagify-modal-cancel' ) as HTMLButtonElement | null;

			if ( ! ov || ! ti || ! msg || ! pv || ! ok || ! cn ) {
				resolve( false );
				return;
			}

			const pr = getEl( 'stagify-modal-prompt' );
			const inp = getEl( 'stagify-modal-input' ) as HTMLInputElement | null;

			ti.textContent = opts.title || '';
			msg.textContent = opts.message || '';
			pv.innerHTML = '';
			pv.style.display = 'none';

			if ( opts.prompt && pr && inp ) {
				pr.style.display = 'block';
				inp.value = opts.promptDefault || '';
				inp.placeholder = opts.promptPlaceholder || '';
			} else if ( pr ) {
				pr.style.display = 'none';
			}

			ok.textContent = opts.confirm || 'OK';
			ok.className = 'button button-primary' + ( opts.danger ? ' stagify-modal-confirm--danger' : '' );
			ov.classList.add( 'stagify-modal--open' );

			if ( opts.prompt && inp ) {
				setTimeout( () => inp.focus(), 50 );
			}

			// Fetch preview if taskId provided.
			if ( opts.previewTaskId && ( window as any ).stagifyAdminBar ) {
				pv.style.display = 'block';
				pv.innerHTML = '<div class="stagify-preview-loading">' + stagifyModal.loadingPreview + '</div>';

				fetch( ( window as any ).stagifyAdminBar.ajaxUrl + '?action=stagify_preview_task&task_id=' + opts.previewTaskId + '&_ajax_nonce=' + ( window as any ).stagifyAdminBar.nonce )
					.then( ( r ) => r.json() )
					.then( ( d: { success: boolean; data: { items: PreviewItem[] } } ) => {
						if ( ! d.success ) {
							pv.innerHTML = '';
							pv.style.display = 'none';
							return;
						}

						const items = d.data.items;
						if ( ! items.length ) {
							pv.innerHTML = '';
							pv.style.display = 'none';
							return;
						}

						let html = '<div class="stagify-preview-list">';
						items.forEach( ( item ) => {
							const actionCls = 'stagify-action--' + item.action;
							const actionLabel = stagifyModal.actionLabels[ item.action ] || item.action;
							const typeLabel = stagifyModal.typeLabels[ item.type ] || item.type;
							html += '<div class="stagify-preview-item">';
							html += '<span class="stagify-preview-action ' + actionCls + '">' + actionLabel + '</span> ';
							html += '<strong>' + ( item.title || item.object_id ) + '</strong>';
							html += '<span class="stagify-preview-type">' + typeLabel + '</span>';
							if ( item.excerpt ) {
								html += '<p class="stagify-preview-excerpt">' + item.excerpt + '</p>';
							}
							html += '</div>';
						} );
						html += '</div>';
						pv.innerHTML = html;
					} )
					.catch( () => {
						pv.innerHTML = '';
						pv.style.display = 'none';
					} );
			}

			function close( val: boolean | string ): void {
				ov!.classList.remove( 'stagify-modal--open' );
				ok!.onclick = null;
				cn!.onclick = null;
				pv!.innerHTML = '';
				pv!.style.display = 'none';
				if ( pr ) {
					pr.style.display = 'none';
				}
				resolve( val );
			}

			ok.onclick = () => {
				if ( opts.prompt && inp ) {
					const v = inp.value.trim();
					if ( ! v ) {
						return;
					}
					close( v );
				} else {
					close( true );
				}
			};

			if ( opts.prompt && inp ) {
				inp.onkeydown = ( e: KeyboardEvent ) => {
					if ( e.key === 'Enter' ) {
						e.preventDefault();
						ok.click();
					}
				};
			}

			cn.onclick = () => close( false );

			ov.addEventListener( 'click', ( e: Event ) => {
				if ( e.target === ov ) {
					close( false );
				}
			}, { once: true } );
		} );
	};

	// Links with data-confirm attributes.
	document.addEventListener( 'click', ( e: Event ) => {
		const el = ( e.target as HTMLElement ).closest< HTMLAnchorElement >( '.stagify-confirm-link' );
		if ( ! el ) {
			return;
		}
		e.preventDefault();
		( window as any ).stagifyConfirm( {
			title: el.dataset.confirmTitle,
			message: el.dataset.confirmMessage,
			confirm: el.dataset.confirmLabel,
			danger: el.dataset.confirmDanger === '1',
		} ).then( ( ok: boolean ) => {
			if ( ok ) {
				window.location.href = el.href;
			}
		} );
	} );

	// Submit buttons with data-confirm attributes.
	document.addEventListener( 'click', ( e: Event ) => {
		const el = ( e.target as HTMLElement ).closest< HTMLElement >( '.stagify-confirm-submit' );
		if ( ! el ) {
			return;
		}
		e.preventDefault();
		( window as any ).stagifyConfirm( {
			title: el.dataset.confirmTitle,
			message: el.dataset.confirmMessage,
			confirm: el.dataset.confirmLabel,
			danger: el.dataset.confirmDanger === '1',
		} ).then( ( ok: boolean ) => {
			if ( ok ) {
				el.closest( 'form' )?.submit();
			}
		} );
	} );

	// Confetti function — called on first-time milestones.
	( window as any ).stagifyConfetti = (): void => {
		const c = document.createElement( 'div' );
		c.className = 'stagify-confetti-container';
		document.body.appendChild( c );

		const colors = [ '#ff7759', '#39594d', '#4c6ee6', '#d18ee2', '#f0b849', '#212121', '#ca492d' ];
		for ( let i = 0; i < 80; i++ ) {
			const p = document.createElement( 'div' );
			p.className = 'stagify-confetti-piece';
			p.style.left = Math.random() * 100 + '%';
			p.style.background = colors[ Math.floor( Math.random() * colors.length ) ];
			p.style.width = ( Math.random() * 8 + 6 ) + 'px';
			p.style.height = ( Math.random() * 4 + 4 ) + 'px';
			p.style.animationDuration = ( Math.random() * 2 + 1.5 ) + 's';
			p.style.animationDelay = ( Math.random() * 0.8 ) + 's';
			p.style.borderRadius = Math.random() > 0.5 ? '50%' : '2px';
			c.appendChild( p );
		}

		setTimeout( () => c.remove(), 4000 );
	};

	// Inline rename handler.
	document.addEventListener( 'click', ( e: Event ) => {
		const el = ( e.target as HTMLElement ).closest< HTMLElement >( '.stagify-rename-trigger' );
		if ( ! el ) {
			return;
		}
		e.preventDefault();

		const id = el.dataset.taskId;
		const current = el.dataset.title || '';
		const row = el.closest( 'tr' );
		const titleCell = row?.querySelector< HTMLElement >( '.column-title' );
		if ( ! titleCell ) {
			return;
		}

		const link = titleCell.querySelector( 'a' );
		const origHtml = titleCell.innerHTML;
		const input = document.createElement( 'input' );
		input.type = 'text';
		input.value = current;
		input.className = 'stagify-rename-input';
		input.style.cssText = 'width:100%;height:32px;border:1px solid #d9d9dd;border-radius:8px;padding:0 10px;font-size:13px;';
		titleCell.innerHTML = '';
		titleCell.appendChild( input );
		input.focus();
		input.select();

		function save(): void {
			const val = input.value.trim();
			if ( ! val || val === current ) {
				titleCell!.innerHTML = origHtml;
				return;
			}

			if ( typeof stagifyAdminBar === 'undefined' ) {
				titleCell!.innerHTML = origHtml;
				return;
			}

			fetch( ( window as any ).stagifyAdminBar.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: new URLSearchParams( {
					action: 'stagify_rename_task',
					_ajax_nonce: ( window as any ).stagifyAdminBar.nonce,
					task_id: id || '',
					title: val,
				} ),
			} )
				.then( ( r ) => r.json() )
				.then( ( d: { success: boolean; data: { title: string } } ) => {
					if ( d.success && link ) {
						link.innerHTML = '<strong>' + d.data.title + '</strong>';
						titleCell!.innerHTML = '';
						titleCell!.appendChild( link );

						const acts = document.createElement( 'div' );
						acts.innerHTML = origHtml;
						const ra = acts.querySelector( '.row-actions' );
						if ( ra ) {
							titleCell!.appendChild( ra );
						}

						const trigger = titleCell!.querySelector< HTMLElement >( '.stagify-rename-trigger' );
						if ( trigger ) {
							trigger.dataset.title = d.data.title;
						}
					} else {
						titleCell!.innerHTML = origHtml;
					}
				} )
				.catch( () => {
					titleCell!.innerHTML = origHtml;
				} );
		}

		input.addEventListener( 'keydown', ( ev: KeyboardEvent ) => {
			if ( ev.key === 'Enter' ) {
				ev.preventDefault();
				save();
			}
			if ( ev.key === 'Escape' ) {
				titleCell!.innerHTML = origHtml;
			}
		} );

		input.addEventListener( 'blur', () => save() );
	} );
} )();
