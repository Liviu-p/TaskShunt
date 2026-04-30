interface TaskShuntAdminBarData {
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
	newTaskLabel: string;
	newTaskPrompt: string;
	creatingLabel: string;
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

declare const taskshuntAdminBar: TaskShuntAdminBarData;

( () => {
	const ROOT_ID = 'wp-admin-bar-taskshunt';
	const TASK_PREFIX = 'wp-admin-bar-taskshunt-task-';
	const ITEM_PREFIX = 'wp-admin-bar-taskshunt-item-';
	const ALL_TASKS_ID = 'wp-admin-bar-taskshunt-all-tasks';
	const SEPARATOR_ID = 'wp-admin-bar-taskshunt-separator';
	const PUSH_ID = 'wp-admin-bar-taskshunt-push';
	const DISCARD_ID = 'wp-admin-bar-taskshunt-discard';
	const MORE_ID = 'wp-admin-bar-taskshunt-items-more';
	const NEW_TASK_ID = 'wp-admin-bar-taskshunt-new-task';

	let busy = false;
	let activeTaskId = 0;

	document.addEventListener( 'DOMContentLoaded', () => {
		activeTaskId = Number( taskshuntAdminBar.activeTaskId ) || 0;

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
				if ( busy || ! activeTaskId || ! taskshuntAdminBar.hasServer ) {
					return;
				}
				const confirmFn = ( window as any ).taskshuntConfirm;
				if ( confirmFn ) {
					confirmFn( { title: taskshuntAdminBar.pushConfirm, message: taskshuntAdminBar.pushMessage, confirm: taskshuntAdminBar.pushLabel, previewTaskId: activeTaskId } ).then( ( ok: boolean ) => {
						if ( ok ) pushTask( activeTaskId, root );
					} );
				} else if ( window.confirm( taskshuntAdminBar.pushConfirm ) ) {
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
				const confirmFn = ( window as any ).taskshuntConfirm;
				if ( confirmFn ) {
					confirmFn( { title: taskshuntAdminBar.discardConfirm, message: taskshuntAdminBar.discardMessage, confirm: taskshuntAdminBar.discardLabel, danger: true } ).then( ( ok: boolean ) => {
						if ( ok ) discardTask( activeTaskId, root );
					} );
				} else if ( window.confirm( taskshuntAdminBar.discardConfirm ) ) {
					discardTask( activeTaskId, root );
				}
				return;
			}

			// Handle new task click — opens the styled modal with an input field.
			if ( clicked.closest( `#${ NEW_TASK_ID }` ) ) {
				e.preventDefault();
				if ( busy ) {
					return;
				}
				const confirmFn = ( window as any ).taskshuntConfirm;
				if ( confirmFn ) {
					confirmFn( {
						title: taskshuntAdminBar.newTaskLabel,
						message: taskshuntAdminBar.newTaskPrompt,
						confirm: taskshuntAdminBar.creatingLabel.replace( '…', '' ),
						prompt: true,
						promptPlaceholder: 'e.g. Homepage update',
					} ).then( ( result: string | false ) => {
						if ( result && typeof result === 'string' ) {
							createTask( result, root );
						}
					} );
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
				action: 'taskshunt_activate_task',
				_ajax_nonce: taskshuntAdminBar.nonce,
				task_id: String( taskId ),
			} );

			const response = await fetch( taskshuntAdminBar.ajaxUrl, {
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

	async function createTask(
		title: string,
		root: HTMLElement
	): Promise< void > {
		busy = true;

		const newTaskLink = root.querySelector< HTMLElement >( `#${ NEW_TASK_ID } .ab-item` );
		const originalLabel = newTaskLink?.textContent ?? '';
		if ( newTaskLink ) {
			newTaskLink.textContent = taskshuntAdminBar.creatingLabel;
		}

		try {
			const body = new URLSearchParams( {
				action: 'taskshunt_create_task',
				_ajax_nonce: taskshuntAdminBar.nonce,
				title,
			} );

			const response = await fetch( taskshuntAdminBar.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );

			const data = ( await response.json() ) as AjaxResponse;

			if ( ! data.success ) {
				if ( newTaskLink ) {
					newTaskLink.textContent = originalLabel;
				}
				return;
			}

			activeTaskId = data.data.task_id;
			rebuildDropdown( root, data.data );
		} catch {
			if ( newTaskLink ) {
				newTaskLink.textContent = originalLabel;
			}
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
			pushLink.textContent = taskshuntAdminBar.pushingLabel;
		}

		try {
			const body = new URLSearchParams( {
				action: 'taskshunt_push_task_ajax',
				_ajax_nonce: taskshuntAdminBar.nonce,
				task_id: String( taskId ),
			} );

			const response = await fetch( taskshuntAdminBar.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );

			const data = ( await response.json() ) as { success: boolean; data: { message: string } };

			if ( pushLink ) {
				if ( data.success ) {
					pushLink.textContent = taskshuntAdminBar.pushedLabel;
					pushLink.style.color = '#39594d';

					// Update the title to show "Pushed" status.
					const titleLink = root.querySelector< HTMLElement >( ':scope > .ab-item' );
					if ( titleLink ) {
						titleLink.innerHTML = '<span style="color:#9e9e9e;">' + taskshuntAdminBar.pushedLabel + '</span>';
					}

					// Remove discard button after successful push.
					document.getElementById( DISCARD_ID )?.remove();
					activeTaskId = 0;

					showPageBanner( 'success', data.data?.message ?? taskshuntAdminBar.pushedLabel );
					triggerFirstTimeConfetti( 'push' );
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
				action: 'taskshunt_discard_task_ajax',
				_ajax_nonce: taskshuntAdminBar.nonce,
				task_id: String( taskId ),
			} );

			const response = await fetch( taskshuntAdminBar.ajaxUrl, {
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
		const viewUrl = `${ taskshuntAdminBar.allTasksUrl }&action=view&task_id=${ data.task_id }`;

		if ( hasActiveTask ) {
			// Active task items.
			for ( const item of data.items ) {
				submenu.insertBefore(
					makeNode( `${ ITEM_PREFIX }${ item.id }`, item.label, viewUrl, 'taskshunt-ab-item' ),
					ref
				);
			}

			// "+ N more…" link.
			if ( data.total_items > 5 ) {
				const moreLabel = taskshuntAdminBar.moreLabel.replace(
					'%d',
					String( data.total_items - 5 )
				);
				submenu.insertBefore(
					makeNode( MORE_ID, moreLabel, viewUrl, 'taskshunt-ab-item' ),
					ref
				);
			}

			// Push button or server config message.
			if ( data.total_items > 0 ) {
				if ( taskshuntAdminBar.hasServer ) {
					submenu.insertBefore(
						makeNode( PUSH_ID, taskshuntAdminBar.pushLabel, '#', 'taskshunt-ab-push' ),
						ref
					);
				} else {
					submenu.insertBefore(
						makeNode( PUSH_ID, taskshuntAdminBar.noServerLabel, taskshuntAdminBar.settingsUrl, 'taskshunt-ab-no-server' ),
						ref
					);
				}
			}

			// Discard button.
			submenu.insertBefore(
				makeNode( DISCARD_ID, taskshuntAdminBar.discardLabel, '#', 'taskshunt-ab-discard' ),
				ref
			);
		}

		// Separator.
		submenu.insertBefore( makeNode( SEPARATOR_ID, '', '#', 'taskshunt-ab-separator' ), ref );

		// Switch-to tasks.
		for ( const task of data.tasks ) {
			submenu.insertBefore(
				makeNode( `${ TASK_PREFIX }${ task.id }`, task.title, '#' ),
				ref
			);
		}

		// "+ New task" button.
		submenu.insertBefore(
			makeNode( NEW_TASK_ID, taskshuntAdminBar.newTaskLabel, '#', 'taskshunt-ab-new-task' ),
			ref
		);
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
	 * Intercept all .taskshunt-push-btn clicks on the page (task list + detail page).
	 */
	function initPagePushButtons(): void {
		document.addEventListener( 'click', ( e: Event ) => {
			const btn = ( e.target as HTMLElement ).closest< HTMLElement >( '.taskshunt-push-btn' );
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

			const confirmFn = ( window as any ).taskshuntConfirm;
			if ( confirmFn ) {
				confirmFn( { title: taskshuntAdminBar.pushConfirm, message: taskshuntAdminBar.pushMessage, confirm: taskshuntAdminBar.pushLabel, previewTaskId: taskId } ).then( ( ok: boolean ) => {
					if ( ok ) pagePush( taskId, btn );
				} );
			} else if ( window.confirm( taskshuntAdminBar.pushConfirm ) ) {
				pagePush( taskId, btn );
			}
		} );
	}

	function triggerFirstTimeConfetti( milestone: string ): void {
		const key = 'taskshunt_confetti_' + milestone;
		if ( localStorage.getItem( key ) ) {
			return;
		}
		localStorage.setItem( key, '1' );
		const confettiFn = ( window as any ).taskshuntConfetti;
		if ( confettiFn ) {
			confettiFn();
		}
	}

	function showPageBanner( type: 'success' | 'error', message: string ): void {
		// Remove any existing banner.
		document.querySelector( '.taskshunt-push-status-banner' )?.remove();

		const wrap = document.querySelector( '.taskshunt-wrap' );
		if ( ! wrap ) return;

		const banner = document.createElement( 'div' );
		banner.className = 'taskshunt-push-status-banner taskshunt-push-status-banner--' + type;

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
		const wrap = document.querySelector( '.taskshunt-wrap' );
		if ( ! wrap ) return null;

		const overlay = document.createElement( 'div' );
		overlay.className = 'taskshunt-pushing-overlay';
		overlay.innerHTML = '<div class="taskshunt-pushing-content">'
			+ '<div class="taskshunt-pushing-spinner"></div>'
			+ '<strong>' + taskshuntAdminBar.pushingLabel + '</strong>'
			+ '<p>Sending changes to production…</p>'
			+ '</div>';

		( wrap as HTMLElement ).style.position = 'relative';
		wrap.appendChild( overlay );
		return overlay;
	}

	async function pagePush( taskId: number, btn: HTMLElement ): Promise< void > {
		busy = true;
		const originalText = btn.textContent ?? '';
		btn.textContent = taskshuntAdminBar.pushingLabel;
		btn.classList.add( 'disabled' );
		btn.style.pointerEvents = 'none';

		const overlay = showPushingOverlay();

		try {
			const body = new URLSearchParams( {
				action: 'taskshunt_push_task_ajax',
				_ajax_nonce: taskshuntAdminBar.nonce,
				task_id: String( taskId ),
			} );

			const response = await fetch( taskshuntAdminBar.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );

			const data = ( await response.json() ) as { success: boolean; data: { message: string } };

			overlay?.remove();

			if ( data.success ) {
				btn.textContent = taskshuntAdminBar.pushedLabel;
				btn.style.pointerEvents = 'none';

				// Update the admin bar title.
				const titleLink = document.querySelector< HTMLElement >(
					`#${ ROOT_ID } > .ab-item`
				);
				if ( titleLink ) {
					titleLink.innerHTML =
						'<span style="color:#a0a5aa;">' + taskshuntAdminBar.noActiveLabel + '</span>';
				}

				document.getElementById( PUSH_ID )?.remove();
				document.getElementById( DISCARD_ID )?.remove();
				activeTaskId = 0;

				showPageBanner( 'success', data.data?.message ?? taskshuntAdminBar.pushedLabel );
				triggerFirstTimeConfetti( 'push' );
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
