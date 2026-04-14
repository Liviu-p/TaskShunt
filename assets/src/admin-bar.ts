interface StagifyAdminBarData {
	ajaxUrl: string;
	nonce: string;
	allTasksUrl: string;
	allTasksLabel: string;
	pushLabel: string;
	noServerLabel: string;
	settingsUrl: string;
	hasServer: boolean;
	discardLabel: string;
	discardConfirm: string;
	discardMessage: string;
	pushConfirm: string;
	pushMessage: string;
	pushingLabel: string;
	pushedLabel: string;
	noActiveLabel: string;
	activeTaskId: number;
	moreLabel: string;
}

interface TaskItem {
	id: number;
	label: string;
}

interface SwitchableTask {
	id: number;
	title: string;
}

interface AjaxResponseData {
	admin_bar_title: string;
	items: TaskItem[];
	total_items: number;
	task_id: number;
	tasks: SwitchableTask[];
}

interface AjaxResponse {
	success: boolean;
	data: AjaxResponseData;
}

declare const stagifyAdminBar: StagifyAdminBarData;

( () => {
	const ROOT_ID = 'wp-admin-bar-stagify';
	const TASK_PREFIX = 'wp-admin-bar-stagify-task-';
	const ITEM_PREFIX = 'wp-admin-bar-stagify-item-';
	const ALL_TASKS_ID = 'wp-admin-bar-stagify-all-tasks';
	const SEPARATOR_ID = 'wp-admin-bar-stagify-separator';
	const PUSH_ID = 'wp-admin-bar-stagify-push';
	const DISCARD_ID = 'wp-admin-bar-stagify-discard';
	const MORE_ID = 'wp-admin-bar-stagify-items-more';

	let busy = false;
	let activeTaskId = 0;

	document.addEventListener( 'DOMContentLoaded', () => {
		activeTaskId = Number( stagifyAdminBar.activeTaskId ) || 0;

		initPagePushButtons();

		const root = document.getElementById( ROOT_ID );
		if ( ! root ) {
			return;
		}

		root.addEventListener( 'click', ( e: Event ) => {
			const clicked = e.target as HTMLElement;

			// Handle push click.
			if ( clicked.closest( `#${ PUSH_ID }` ) ) {
				e.preventDefault();
				if ( busy || ! activeTaskId || ! stagifyAdminBar.hasServer ) {
					return;
				}
				const confirmFn = ( window as any ).stagifyConfirm;
				if ( confirmFn ) {
					confirmFn( { title: stagifyAdminBar.pushConfirm, message: stagifyAdminBar.pushMessage, confirm: stagifyAdminBar.pushLabel, previewTaskId: activeTaskId } ).then( ( ok: boolean ) => {
						if ( ok ) pushTask( activeTaskId, root );
					} );
				} else if ( window.confirm( stagifyAdminBar.pushConfirm ) ) {
					pushTask( activeTaskId, root );
				}
				return;
			}

			// Handle discard click.
			if ( clicked.closest( `#${ DISCARD_ID }` ) ) {
				e.preventDefault();
				if ( busy || ! activeTaskId ) {
					return;
				}
				const confirmFn = ( window as any ).stagifyConfirm;
				if ( confirmFn ) {
					confirmFn( { title: stagifyAdminBar.discardConfirm, message: stagifyAdminBar.discardMessage, confirm: stagifyAdminBar.discardLabel, danger: true } ).then( ( ok: boolean ) => {
						if ( ok ) discardTask( activeTaskId, root );
					} );
				} else if ( window.confirm( stagifyAdminBar.discardConfirm ) ) {
					discardTask( activeTaskId, root );
				}
				return;
			}

			// Handle task switch click.
			const target = clicked.closest< HTMLElement >(
				`[id^="${ TASK_PREFIX }"] .ab-item`
			);
			if ( ! target ) {
				return;
			}

			const li = target.closest< HTMLElement >( `[id^="${ TASK_PREFIX }"]` );
			if ( ! li ) {
				return;
			}

			e.preventDefault();

			if ( busy ) {
				return;
			}

			const taskId = li.id.replace( TASK_PREFIX, '' );
			if ( ! taskId ) {
				return;
			}

			activateTask( parseInt( taskId, 10 ), root, li );
		} );
	} );

	async function activateTask(
		taskId: number,
		root: HTMLElement,
		clickedLi: HTMLElement
	): Promise< void > {
		busy = true;
		clickedLi.style.opacity = '0.5';

		try {
			const body = new URLSearchParams( {
				action: 'stagify_activate_task',
				_ajax_nonce: stagifyAdminBar.nonce,
				task_id: String( taskId ),
			} );

			const response = await fetch( stagifyAdminBar.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );

			const data = ( await response.json() ) as AjaxResponse;

			if ( ! data.success ) {
				window.location.reload();
				return;
			}

			activeTaskId = data.data.task_id;
			rebuildDropdown( root, data.data );
		} catch {
			window.location.reload();
		} finally {
			busy = false;
		}
	}

	async function pushTask(
		taskId: number,
		root: HTMLElement
	): Promise< void > {
		busy = true;

		// Show "Pushing…" state in the push node.
		const pushLink = root.querySelector< HTMLElement >( `#${ PUSH_ID } .ab-item` );
		const originalLabel = pushLink?.innerHTML ?? '';
		if ( pushLink ) {
			pushLink.textContent = stagifyAdminBar.pushingLabel;
		}

		try {
			const body = new URLSearchParams( {
				action: 'stagify_push_task_ajax',
				_ajax_nonce: stagifyAdminBar.nonce,
				task_id: String( taskId ),
			} );

			const response = await fetch( stagifyAdminBar.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );

			const data = ( await response.json() ) as { success: boolean; data: { message: string } };

			if ( pushLink ) {
				if ( data.success ) {
					pushLink.textContent = stagifyAdminBar.pushedLabel;
					pushLink.style.color = '#39594d';

					// Update the title to show "Pushed" status.
					const titleLink = root.querySelector< HTMLElement >( ':scope > .ab-item' );
					if ( titleLink ) {
						titleLink.innerHTML = '<span style="color:#9e9e9e;">' + stagifyAdminBar.pushedLabel + '</span>';
					}

					// Remove discard button after successful push.
					document.getElementById( DISCARD_ID )?.remove();
					activeTaskId = 0;

					showPageBanner( 'success', data.data?.message ?? stagifyAdminBar.pushedLabel );
				} else {
					pushLink.innerHTML = originalLabel;
					showPageBanner( 'error', data.data?.message ?? 'Push failed.' );
				}
			}
		} catch {
			if ( pushLink ) {
				pushLink.innerHTML = originalLabel;
			}
			showPageBanner( 'error', 'Push request failed. Check your network.' );
		} finally {
			busy = false;
		}
	}

	async function discardTask(
		taskId: number,
		root: HTMLElement
	): Promise< void > {
		busy = true;

		try {
			const body = new URLSearchParams( {
				action: 'stagify_discard_task_ajax',
				_ajax_nonce: stagifyAdminBar.nonce,
				task_id: String( taskId ),
			} );

			const response = await fetch( stagifyAdminBar.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );

			const data = ( await response.json() ) as AjaxResponse;

			if ( ! data.success ) {
				window.location.reload();
				return;
			}

			activeTaskId = 0;
			rebuildDropdown( root, data.data );
		} catch {
			window.location.reload();
		} finally {
			busy = false;
		}
	}

	function rebuildDropdown(
		root: HTMLElement,
		data: AjaxResponseData
	): void {
		// Update the main title.
		const titleLink = root.querySelector< HTMLElement >( ':scope > .ab-item' );
		if ( titleLink ) {
			titleLink.innerHTML = data.admin_bar_title;
		}

		const submenu = root.querySelector< HTMLElement >( `#${ ROOT_ID }-default` );
		if ( ! submenu ) {
			return;
		}

		// Clear everything except the "All tasks" link.
		const allTasksNode = document.getElementById( ALL_TASKS_ID );
		while ( submenu.firstChild && submenu.firstChild !== allTasksNode ) {
			submenu.removeChild( submenu.firstChild );
		}

		const ref = allTasksNode ?? null;
		const hasActiveTask = data.task_id > 0;
		const viewUrl = `${ stagifyAdminBar.allTasksUrl }&action=view&task_id=${ data.task_id }`;

		if ( hasActiveTask ) {
			// Active task items.
			for ( const item of data.items ) {
				submenu.insertBefore(
					makeNode( `${ ITEM_PREFIX }${ item.id }`, item.label, viewUrl, 'stagify-ab-item' ),
					ref
				);
			}

			// "+ N more…" link.
			if ( data.total_items > 5 ) {
				const moreLabel = stagifyAdminBar.moreLabel.replace(
					'%d',
					String( data.total_items - 5 )
				);
				submenu.insertBefore(
					makeNode( MORE_ID, moreLabel, viewUrl, 'stagify-ab-item' ),
					ref
				);
			}

			// Push button or server config message.
			if ( data.total_items > 0 ) {
				if ( stagifyAdminBar.hasServer ) {
					submenu.insertBefore(
						makeNode( PUSH_ID, stagifyAdminBar.pushLabel, '#', 'stagify-ab-push' ),
						ref
					);
				} else {
					submenu.insertBefore(
						makeNode( PUSH_ID, stagifyAdminBar.noServerLabel, stagifyAdminBar.settingsUrl, 'stagify-ab-no-server' ),
						ref
					);
				}
			}

			// Discard button.
			submenu.insertBefore(
				makeNode( DISCARD_ID, stagifyAdminBar.discardLabel, '#', 'stagify-ab-discard' ),
				ref
			);
		}

		// Separator.
		submenu.insertBefore( makeNode( SEPARATOR_ID, '', '#', 'stagify-ab-separator' ), ref );

		// Switch-to tasks.
		for ( const task of data.tasks ) {
			submenu.insertBefore(
				makeNode( `${ TASK_PREFIX }${ task.id }`, task.title, '#' ),
				ref
			);
		}
	}

	function makeNode(
		id: string,
		html: string,
		href: string,
		className?: string
	): HTMLLIElement {
		const li = document.createElement( 'li' );
		li.id = id;
		if ( className ) {
			li.className = className;
		}

		const a = document.createElement( 'a' );
		a.className = 'ab-item';
		a.href = href;
		a.innerHTML = html;

		li.appendChild( a );
		return li;
	}

	/**
	 * Intercept all .stagify-push-btn clicks on the page (task list + detail page).
	 */
	function initPagePushButtons(): void {
		document.addEventListener( 'click', ( e: Event ) => {
			const btn = ( e.target as HTMLElement ).closest< HTMLElement >( '.stagify-push-btn' );
			if ( ! btn ) {
				return;
			}

			e.preventDefault();

			if ( busy ) {
				return;
			}

			const taskId = parseInt( btn.getAttribute( 'data-task-id' ) ?? '0', 10 );
			if ( ! taskId ) {
				return;
			}

			const confirmFn = ( window as any ).stagifyConfirm;
			if ( confirmFn ) {
				confirmFn( { title: stagifyAdminBar.pushConfirm, message: stagifyAdminBar.pushMessage, confirm: stagifyAdminBar.pushLabel, previewTaskId: taskId } ).then( ( ok: boolean ) => {
					if ( ok ) pagePush( taskId, btn );
				} );
			} else if ( window.confirm( stagifyAdminBar.pushConfirm ) ) {
				pagePush( taskId, btn );
			}
		} );
	}

	function showPageBanner( type: 'success' | 'error', message: string ): void {
		// Remove any existing banner.
		document.querySelector( '.stagify-push-status-banner' )?.remove();

		const wrap = document.querySelector( '.stagify-wrap' );
		if ( ! wrap ) return;

		const banner = document.createElement( 'div' );
		banner.className = 'stagify-push-status-banner stagify-push-status-banner--' + type;

		const icon = type === 'success' ? 'dashicons-yes-alt' : 'dashicons-warning';
		const link = type === 'success'
			? ' <a href="' + window.location.href + '">Refresh page</a>'
			: '';

		banner.innerHTML = '<span class="dashicons ' + icon + '"></span>'
			+ '<span>' + message + '</span>'
			+ link;

		wrap.insertBefore( banner, wrap.firstChild );
	}

	function showPushingOverlay(): HTMLElement | null {
		const wrap = document.querySelector( '.stagify-wrap' );
		if ( ! wrap ) return null;

		const overlay = document.createElement( 'div' );
		overlay.className = 'stagify-pushing-overlay';
		overlay.innerHTML = '<div class="stagify-pushing-content">'
			+ '<div class="stagify-pushing-spinner"></div>'
			+ '<strong>' + stagifyAdminBar.pushingLabel + '</strong>'
			+ '<p>Sending changes to production…</p>'
			+ '</div>';

		( wrap as HTMLElement ).style.position = 'relative';
		wrap.appendChild( overlay );
		return overlay;
	}

	async function pagePush( taskId: number, btn: HTMLElement ): Promise< void > {
		busy = true;
		const originalText = btn.textContent ?? '';
		btn.textContent = stagifyAdminBar.pushingLabel;
		btn.classList.add( 'disabled' );
		btn.style.pointerEvents = 'none';

		const overlay = showPushingOverlay();

		try {
			const body = new URLSearchParams( {
				action: 'stagify_push_task_ajax',
				_ajax_nonce: stagifyAdminBar.nonce,
				task_id: String( taskId ),
			} );

			const response = await fetch( stagifyAdminBar.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );

			const data = ( await response.json() ) as { success: boolean; data: { message: string } };

			overlay?.remove();

			if ( data.success ) {
				btn.textContent = stagifyAdminBar.pushedLabel;
				btn.style.pointerEvents = 'none';

				// Update the admin bar title.
				const titleLink = document.querySelector< HTMLElement >(
					`#${ ROOT_ID } > .ab-item`
				);
				if ( titleLink ) {
					titleLink.innerHTML =
						'<span style="color:#a0a5aa;">' + stagifyAdminBar.noActiveLabel + '</span>';
				}

				document.getElementById( PUSH_ID )?.remove();
				document.getElementById( DISCARD_ID )?.remove();
				activeTaskId = 0;

				showPageBanner( 'success', data.data?.message ?? stagifyAdminBar.pushedLabel );
			} else {
				btn.textContent = originalText;
				btn.style.pointerEvents = '';
				btn.classList.remove( 'disabled' );
				showPageBanner( 'error', data.data?.message ?? 'Push failed.' );
			}
		} catch {
			overlay?.remove();
			btn.textContent = originalText;
			btn.style.pointerEvents = '';
			btn.classList.remove( 'disabled' );
			showPageBanner( 'error', 'Push request failed. Check your network.' );
		} finally {
			busy = false;
		}
	}
} )();
