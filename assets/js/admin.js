/*
 * MY Files PRO
 * Licensed under the GNU General Public License v3.0 or later. See LICENSE in the plugin root.
 */

(function () {
	'use strict';

	const config = window.MyFilesPro || {};

	if (!config.enabled || !window.wp || !wp.apiFetch) {
		return;
	}

	if (config.nonce && wp.apiFetch.createNonceMiddleware) {
		wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(config.nonce));
	}

	const text = config.i18n || {};
	const state = {
		selected: normalizeFolder(config.currentFolder || getUrlFolder() || 'all'),
		folders: [],
		special: {},
		search: '',
		loaded: false,
		draggingFolder: null,
		internalDragActive: false,
		selectedAttachmentIds: [],
		isMoving: false,
		isSorting: false,
		folderRefreshTimers: [],
		folderRefreshPromise: null,
		lastFolderRefreshAt: 0,
		lastUploadActivityAt: 0,
		lastMediaGridSignature: '',
		selectionSource: '',
		collapsedFolders: loadCollapsedFolders(),
		dragPreview: null,
		quickMoveMode: false,
		uploaders: new Set(),
		uploaderQueueBound: false,
		uploadListReloadTimer: null,
	};

	installUploaderParamBridge();
	document.addEventListener('DOMContentLoaded', init);

	function init() {
		if (window.localStorage) {
			window.localStorage.removeItem('myfilesQuickMoveMode');
		}

		document.body.classList.toggle('myfiles-quick-move-mode', state.quickMoveMode);
		installMediaQueryBridge();
		injectUploadPanel();
		observeMediaModal();
		observeAttachmentDraggables();
		bindInternalDragGuards();
		bindSelectionMemory();
		bindUploadRefresh();
		updateUploaderParams();
		fetchFolders();
	}

	function api(path, options = {}) {
		return wp.apiFetch(Object.assign({ path: config.restNamespace + path }, options));
	}

	function fetchFolders(options = {}) {
		const silent = options && options.silent === true;
		const minInterval = Number(options && options.minInterval ? options.minInterval : 0);
		const now = Date.now();

		if (minInterval && state.lastFolderRefreshAt && now - state.lastFolderRefreshAt < minInterval) {
			return Promise.resolve();
		}

		if (state.folderRefreshPromise) {
			return state.folderRefreshPromise;
		}

		if (!silent) {
			setLoading(true);
		}

		state.folderRefreshPromise = api(`/folders?refresh=${Date.now()}`)
			.then((response) => {
				state.folders = Array.isArray(response.folders) ? response.folders : [];
				state.special = response.special || {};
				state.loaded = true;

				if (!silent) {
					expandAncestors(state.selected);
				}

				renderPanels();
			})
			.catch(showError)
			.finally(() => {
				state.lastFolderRefreshAt = Date.now();
				state.folderRefreshPromise = null;

				if (!silent) {
					setLoading(false);
				}
			});

		return state.folderRefreshPromise;
	}

	function injectUploadPanel() {
		const wrap = document.querySelector('body.upload-php .wrap');

		if (!wrap || document.getElementById('my-files-pro-panel')) {
			return;
		}

		const panel = buildPanel('page');
		panel.id = 'my-files-pro-panel';

		const anchor = wrap.querySelector('.wp-filter, #posts-filter, .media-frame') || wrap.firstElementChild;

		if (anchor && anchor.nextSibling) {
			wrap.insertBefore(panel, anchor.nextSibling);
		} else {
			wrap.appendChild(panel);
		}

		document.body.classList.add('my-files-pro-active');
	}

	function observeMediaModal() {
		const observer = new MutationObserver(injectModalPanel);
		observer.observe(document.body, { childList: true, subtree: true });
		injectModalPanel();
	}

	function injectModalPanel() {
		const frame = document.querySelector('.media-modal .media-frame');

		if (!frame || frame.querySelector('.myfiles-panel--modal')) {
			return;
		}

		const panel = buildPanel('modal');
		panel.classList.add('myfiles-panel--modal');
		frame.classList.add('myfiles-modal-active');
		frame.appendChild(panel);
		renderPanels();
	}

	function buildPanel(context) {
		const panel = document.createElement('aside');
		const productName = String(config.productName || 'MY Files PRO');
		const productVersion = String(config.version || '');
		panel.className = 'myfiles-panel';
		panel.dataset.context = context;

		panel.innerHTML = [
			'<div class="myfiles-panel__header">',
			'<div class="myfiles-panel__heading">',
			`<h2>${escapeHtml(text.panelTitle || 'Folders')}</h2>`,
			'<div class="myfiles-panel__identity">',
			`<span class="myfiles-panel__product-name">${escapeHtml(productName)}</span>`,
			productVersion
				? `<span class="myfiles-panel__version"><span aria-hidden="true">·</span> v${escapeHtml(productVersion)}</span>`
				: '',
			'</div>',
			'</div>',
			'<button type="button" class="button-link myfiles-icon-button" data-action="refresh" title="Refresh"><span class="dashicons dashicons-update"></span></button>',
			'</div>',
			'<div class="myfiles-panel__toolbar">',
			`<button type="button" class="button button-secondary" data-action="new"><span class="dashicons dashicons-plus-alt2"></span><span>${escapeHtml(text.newFolder || 'New folder')}</span></button>`,
			`<button type="button" class="button button-secondary" data-action="rename"><span class="dashicons dashicons-edit"></span><span>${escapeHtml(text.rename || 'Rename')}</span></button>`,
			`<button type="button" class="button button-secondary" data-action="duplicate"><span class="dashicons dashicons-admin-page"></span><span>${escapeHtml(text.duplicate || 'Duplicate')}</span></button>`,
			`<button type="button" class="button button-secondary" data-action="delete"><span class="dashicons dashicons-trash"></span><span>${escapeHtml(text.delete || 'Delete')}</span></button>`,
			`<button type="button" class="button button-secondary" data-action="favorite"><span class="dashicons dashicons-star-empty"></span><span>${escapeHtml(text.favorite || 'Favorite')}</span></button>`,
			config.canManage
				? `<button type="button" class="button button-secondary" data-action="sort-az"><span class="dashicons dashicons-sort"></span><span>${escapeHtml(text.sortAlpha || 'Sort A–Z')}</span></button>`
				: '',
			'</div>',
			'<label class="myfiles-color-control">',
			`<span>${escapeHtml(text.folderColor || 'Folder color')}</span>`,
			'<input type="color" class="myfiles-color-picker" value="#2271b1" />',
			'</label>',
			`<input type="search" class="myfiles-search" placeholder="${escapeAttribute(text.search || 'Search folders')}" />`,
			'<div class="myfiles-status" hidden></div>',
			'<div class="myfiles-tree" role="tree"></div>',
			'<div class="myfiles-move-tools">',
			`<button type="button" class="button button-secondary myfiles-quick-move-button" data-action="quick-move" aria-pressed="false"><span class="dashicons dashicons-move"></span><span class="myfiles-quick-move-label">${escapeHtml(text.quickMove || 'Select to move')}</span></button>`,
			`<button type="button" class="button button-primary myfiles-move-button" data-action="move-selected"><span class="dashicons dashicons-move"></span><span class="myfiles-move-label">${escapeHtml(text.moveSelected || 'Move selected here')}</span></button>`,
			'</div>',
		].join('');

		panel.addEventListener('click', handlePanelClick);
		panel.addEventListener('dblclick', handlePanelDoubleClick);
		panel.addEventListener('keydown', handlePanelKeyDown);
		panel.addEventListener('input', handlePanelInput);
		panel.addEventListener('change', handlePanelChange);
		panel.addEventListener('dragstart', handleFolderDragStart);
		panel.addEventListener('dragend', clearInternalDragState);
		panel.addEventListener('dragover', handlePanelDragOver);
		panel.addEventListener('drop', handlePanelDrop);

		return panel;
	}

	function renderPanels() {
		document.querySelectorAll('.myfiles-panel').forEach((panel) => {
			renderPanel(panel);
		});
	}

	function renderPanel(panel) {
		const tree = panel.querySelector('.myfiles-tree');
		const search = panel.querySelector('.myfiles-search');
		const moveButton = panel.querySelector('[data-action="move-selected"]');
		const favoriteButton = panel.querySelector('[data-action="favorite"]');
		const quickMoveButton = panel.querySelector('[data-action="quick-move"]');
		const colorPicker = panel.querySelector('.myfiles-color-picker');
		const selectedFolder = getSelectedFolder();

		if (!tree) {
			return;
		}

		if (search && search.value !== state.search) {
			search.value = state.search;
		}

		tree.innerHTML = '';
		tree.appendChild(renderRow(state.special.all || { id: 'all', name: 'All Files' }, 0, true));
		tree.appendChild(renderRow(state.special.uncategorized || { id: 'uncategorized', name: 'Uncategorized' }, 0, true));

		const children = groupFolders(state.folders);
		const matches = state.search.trim().toLowerCase();

		if (matches) {
			state.folders
				.filter((folder) => folder.name.toLowerCase().includes(matches))
				.forEach((folder) => tree.appendChild(renderRow(folder, 0, false, false, true)));
		} else {
			renderBranch(tree, children, 0, 0);
		}

		panel.querySelectorAll('[data-action="rename"], [data-action="duplicate"], [data-action="delete"]').forEach((button) => {
			button.disabled = !isNumericFolder(state.selected) || !config.canManage;
		});

		panel.querySelectorAll('[data-action="new"]').forEach((button) => {
			button.disabled = !config.canManage;
		});

		panel.querySelectorAll('[data-action="sort-az"]').forEach((button) => {
			button.disabled = !config.canManage || state.isSorting || state.folders.length < 2;
		});

		if (favoriteButton) {
			favoriteButton.disabled = !selectedFolder || !config.canManage;
			favoriteButton.classList.toggle('is-active', Boolean(selectedFolder && selectedFolder.favorite));
			favoriteButton.querySelector('.dashicons').className = selectedFolder && selectedFolder.favorite
				? 'dashicons dashicons-star-filled'
				: 'dashicons dashicons-star-empty';
		}

		if (quickMoveButton) {
			const quickMoveLabel = quickMoveButton.querySelector('.myfiles-quick-move-label');

			quickMoveButton.classList.toggle('is-active', state.quickMoveMode);
			quickMoveButton.setAttribute('aria-pressed', state.quickMoveMode ? 'true' : 'false');

			if (quickMoveLabel) {
				quickMoveLabel.textContent = state.quickMoveMode
					? (text.quickMoveActive || 'Cancel move selection')
					: (text.quickMove || 'Select to move');
			}
		}

		if (colorPicker) {
			colorPicker.disabled = !selectedFolder || !config.canManage;
			colorPicker.value = selectedFolder && selectedFolder.color ? selectedFolder.color : '#2271b1';
		}

		if (moveButton) {
			const rememberedCount = getMoveAttachmentIds().length;
			const label = moveButton.querySelector('.myfiles-move-label');

			moveButton.disabled = state.isMoving || rememberedCount === 0 || state.selected === 'all';
			moveButton.title = state.selected === 'all'
				? (text.cannotMoveToAll || 'Choose a real folder or Uncategorized before moving files.')
				: '';

			if (label) {
				label.textContent = state.isMoving
					? (text.moving || 'Moving...')
					: rememberedCount > 0
					? `${text.moveSelected || 'Move selected here'} (${rememberedCount})`
					: (text.moveSelected || 'Move selected here');
			}
		}
	}

	function renderBranch(tree, children, parentId, level) {
		(children[parentId] || []).forEach((folder) => {
			const folderId = Number(folder.id);
			const hasChildren = (children[folderId] || []).length > 0;

			tree.appendChild(renderRow(folder, level, false, hasChildren));

			if (!state.collapsedFolders.has(String(folder.id))) {
				renderBranch(tree, children, folderId, level + 1);
			}
		});
	}

	function renderRow(folder, level, special, hasChildren = false, searchResult = false) {
		const row = document.createElement('div');
		const id = String(folder.id);
		const collapsed = !special && hasChildren && state.collapsedFolders.has(id);

		row.className = 'myfiles-folder';
		row.dataset.folderId = id;
		row.dataset.special = special ? '1' : '0';
		row.dataset.hasChildren = hasChildren ? '1' : '0';
		row.style.setProperty('--folder-level', String(level));
		row.setAttribute('role', 'treeitem');
		row.setAttribute('tabindex', '0');
		row.setAttribute('aria-selected', state.selected === id ? 'true' : 'false');

		if (!special && hasChildren && !searchResult) {
			row.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
			row.classList.add('has-children');
		}

		if (!special && config.canManage) {
			row.draggable = true;
			row.title = `Folder ID: ${id}`;
		}

		if (!special && folder.color) {
			row.classList.add('has-folder-color');
			row.style.setProperty('--folder-color', folder.color);
		}

		if (!special && folder.favorite) {
			row.classList.add('is-favorite');
		}

		row.innerHTML = [
			renderFolderToggle(id, special, hasChildren && !searchResult, collapsed),
			`<span class="dashicons ${folder.favorite ? 'dashicons-star-filled' : 'dashicons-category'} myfiles-folder__icon"></span>`,
			`<span class="myfiles-folder__name">${escapeHtml(folder.name)}</span>`,
			config.showCounts && folder.count !== null && folder.count !== undefined
				? `<span class="myfiles-folder__count">${escapeHtml(String(folder.count))}</span>`
				: '',
		].join('');

		return row;
	}

	function renderFolderToggle(id, special, hasChildren, collapsed) {
		if (special) {
			return '';
		}

		if (!hasChildren) {
			return '<span class="myfiles-folder__toggle is-empty" aria-hidden="true"></span>';
		}

		const label = collapsed ? (text.expandFolder || 'Expand folder') : (text.collapseFolder || 'Collapse folder');

		return [
			`<button type="button" class="myfiles-folder__toggle" data-folder-toggle="${escapeAttribute(id)}" title="${escapeAttribute(label)}" aria-label="${escapeAttribute(label)}">`,
			'<span class="myfiles-folder__chevron"></span>',
			'</button>',
		].join('');
	}

	function handlePanelClick(event) {
		const toggle = event.target.closest('[data-folder-toggle]');
		const action = event.target.closest('[data-action]');
		const folder = event.target.closest('.myfiles-folder');

		if (toggle && folder && folder.dataset.hasChildren === '1') {
			event.preventDefault();
			event.stopPropagation();
			toggleFolder(folder.dataset.folderId || '');
			return;
		}

		if (action) {
			event.preventDefault();
			runAction(action.dataset.action);
			return;
		}

		if (folder) {
			event.preventDefault();
			selectFolder(folder.dataset.folderId || 'all');
		}
	}

	function handlePanelDoubleClick(event) {
		const folder = event.target.closest('.myfiles-folder');

		if (!folder || folder.dataset.special === '1' || !config.canManage || event.target.closest('[data-folder-toggle]')) {
			return;
		}

		event.preventDefault();
		state.selected = normalizeFolder(folder.dataset.folderId || 'all');
		renderPanels();
		renameFolder();
	}

	function handlePanelKeyDown(event) {
		const folder = event.target.closest('.myfiles-folder');

		if (!folder || folder.dataset.special === '1') {
			return;
		}

		if (event.key === 'ArrowRight') {
			if (folder.dataset.hasChildren !== '1') {
				return;
			}

			event.preventDefault();
			setFolderCollapsed(folder.dataset.folderId || '', false);
		}

		if (event.key === 'ArrowLeft') {
			if (folder.dataset.hasChildren !== '1') {
				return;
			}

			event.preventDefault();
			setFolderCollapsed(folder.dataset.folderId || '', true);
		}

		if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('[data-folder-toggle]')) {
			event.preventDefault();
			selectFolder(folder.dataset.folderId || 'all');
		}
	}

	function handlePanelInput(event) {
		if (!event.target.classList.contains('myfiles-search')) {
			return;
		}

		state.search = event.target.value;
		renderPanels();
	}

	function handlePanelChange(event) {
		if (event.target.classList.contains('myfiles-color-picker')) {
			setFolderColor(event.target.value);
		}
	}

	function runAction(action) {
		switch (action) {
			case 'refresh':
				fetchFolders();
				break;
			case 'new':
				createFolder();
				break;
			case 'rename':
				renameFolder();
				break;
			case 'duplicate':
				duplicateFolder();
				break;
			case 'delete':
				deleteFolder();
				break;
			case 'favorite':
				toggleFavorite();
				break;
			case 'sort-az':
				sortFoldersAlphabetically();
				break;
			case 'quick-move':
				toggleQuickMoveMode();
				break;
			case 'move-selected':
				moveSelectedHere();
				break;
		}
	}

	function selectFolder(folderId) {
		rememberSelectedAttachments();
		state.selected = normalizeFolder(folderId);
		expandAncestors(state.selected);
		updateUrl();
		updateUploaderParams();
		renderPanels();

		if (!refreshMediaCollections() && isUploadListView()) {
			window.location.href = buildUrlWithFolder(state.selected);
		}
	}

	function createFolder() {
		const name = window.prompt(text.folderName || 'Folder name');

		if (!name) {
			return;
		}

		const parent = isNumericFolder(state.selected) ? Number(state.selected) : 0;

		api('/folders', {
			method: 'POST',
			data: { name, parent },
		})
			.then((folder) => {
				state.selected = String(folder.id);
				return fetchFolders();
			})
			.then(() => selectFolder(state.selected))
			.catch(showError);
	}

	function renameFolder() {
		const folder = getSelectedFolder();

		if (!folder) {
			return;
		}

		const name = window.prompt(text.folderName || 'Folder name', folder.name);

		if (!name || name === folder.name) {
			return;
		}

		api(`/folders/${folder.id}`, {
			method: 'PATCH',
			data: { name },
		})
			.then(fetchFolders)
			.catch(showError);
	}

	function duplicateFolder() {
		if (!isNumericFolder(state.selected)) {
			return;
		}

		api(`/folders/${state.selected}/duplicate`, {
			method: 'POST',
			data: {},
		})
			.then((folder) => {
				state.selected = String(folder.id);
				return fetchFolders();
			})
			.catch(showError);
	}

	function deleteFolder() {
		if (!isNumericFolder(state.selected) || !window.confirm(text.deleteConfirm || 'Delete this folder?')) {
			return;
		}

		api(`/folders/${state.selected}`, {
			method: 'DELETE',
			data: { moveTo: 0 },
		})
			.then(() => {
				state.selected = 'all';
				return fetchFolders();
			})
			.then(() => selectFolder('all'))
			.catch(showError);
	}

	function toggleFavorite() {
		const folder = getSelectedFolder();

		if (!folder) {
			return;
		}

		api(`/folders/${folder.id}`, {
			method: 'PATCH',
			data: { favorite: !folder.favorite },
		})
			.then(fetchFolders)
			.catch(showError);
	}

	function setFolderColor(color) {
		const folder = getSelectedFolder();

		if (!folder) {
			return;
		}

		api(`/folders/${folder.id}`, {
			method: 'PATCH',
			data: { color },
		})
			.then(fetchFolders)
			.catch(showError);
	}

	function toggleQuickMoveMode() {
		state.quickMoveMode = !state.quickMoveMode;
		clearMoveSelection();
		clearWordPressSelection();

		document.body.classList.toggle('myfiles-quick-move-mode', state.quickMoveMode);
		renderQuickSelections();
		renderPanels();
	}

	function moveSelectedHere() {
		if (state.selected === 'all') {
			window.alert(text.cannotMoveToAll || 'Choose a real folder or Uncategorized before moving files.');
			return;
		}

		const attachmentIds = getMoveAttachmentIds({ syncState: true });

		if (!attachmentIds.length) {
			window.alert(text.selectFiles || 'Select one or more media files first.');
			return;
		}

		moveAttachments(attachmentIds, state.selected);
	}

	function moveAttachments(attachmentIds, folderId) {
		const target = folderId === 'uncategorized' ? 0 : Number(folderId);

		if (folderId === 'all' || Number.isNaN(target)) {
			window.alert(text.cannotMoveToAll || 'Choose a real folder or Uncategorized before moving files.');
			return;
		}

		if (state.isMoving) {
			return;
		}

		state.isMoving = true;
		renderPanels();
		showStatus(text.moving || 'Moving...', 'info');

		api('/attachments/move', {
			method: 'POST',
			data: {
				attachmentIds,
				folderId: target,
			},
		})
			.then((response) => {
				clearMoveSelection();
				clearWordPressSelection();
				renderQuickSelections();
				return fetchFolders().then(() => response);
			})
			.then((response) => {
				const moved = response && Object.prototype.hasOwnProperty.call(response, 'moved')
					? Number(response.moved)
					: attachmentIds.length;
				const refreshed = refreshMediaCollections();

				showStatus(formatMovedMessage(moved, folderId), moved > 0 ? 'success' : 'info');

				if (!refreshed && document.body.classList.contains('upload-php')) {
					window.setTimeout(() => {
						window.location.href = buildUrlWithFolder(state.selected);
					}, 650);
				}
			})
			.catch(showError)
			.finally(() => {
				state.isMoving = false;
				renderPanels();
			});
	}

	function handleFolderDragStart(event) {
		const folder = event.target.closest('.myfiles-folder');

		if (!folder || folder.dataset.special === '1') {
			return;
		}

		state.draggingFolder = folder.dataset.folderId;
		event.dataTransfer.effectAllowed = 'move';
		event.dataTransfer.setData('application/x-myfiles-folder', state.draggingFolder);
		event.dataTransfer.setData('text/plain', state.draggingFolder);
		beginInternalDrag();
	}

	function handlePanelDragOver(event) {
		const folder = event.target.closest('.myfiles-folder');

		if (!folder) {
			if (isInternalDragEvent(event)) {
				event.preventDefault();
				event.dataTransfer.dropEffect = 'none';
				clearDropIndicators();
			}

			return;
		}

		event.preventDefault();
		event.dataTransfer.dropEffect = 'move';

		clearDropIndicators();

		if (event.dataTransfer.types && Array.from(event.dataTransfer.types).includes('application/x-myfiles-folder')) {
			folder.classList.add(`is-drop-${getFolderDropPosition(event, folder)}`);
		} else {
			folder.classList.add('is-drop-inside');
		}
	}

	function handlePanelDrop(event) {
		const folder = event.target.closest('.myfiles-folder');

		if (!folder) {
			if (isInternalDragEvent(event)) {
				event.preventDefault();
				clearInternalDragState();
			}

			return;
		}

		event.preventDefault();
		const targetFolder = folder.dataset.folderId || 'all';
		const attachmentPayload = event.dataTransfer.getData('application/x-myfiles-attachments');
		const draggedFolder = event.dataTransfer.getData('application/x-myfiles-folder') || state.draggingFolder;
		const dropPosition = getFolderDropPosition(event, folder);

		clearDropIndicators();

		if (attachmentPayload) {
			const ids = attachmentPayload.split(',').map((id) => Number(id)).filter(Boolean);

			if (ids.length) {
				moveAttachments(ids, targetFolder);
			}

			clearInternalDragState();
			return;
		}

		if (draggedFolder && isNumericFolder(draggedFolder) && draggedFolder !== targetFolder) {
			sortDraggedFolder(Number(draggedFolder), targetFolder, dropPosition);
		}

		clearInternalDragState();
	}

	function getFolderDropPosition(event, folder) {
		if (folder.dataset.special === '1') {
			return 'inside';
		}

		const rect = folder.getBoundingClientRect();
		const offset = event.clientY - rect.top;

		if (offset < rect.height * 0.28) {
			return 'before';
		}

		if (offset > rect.height * 0.72) {
			return 'after';
		}

		return 'inside';
	}

	function clearDropIndicators() {
		document.querySelectorAll('.myfiles-folder.is-drop-before, .myfiles-folder.is-drop-after, .myfiles-folder.is-drop-inside').forEach((row) => {
			row.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-inside');
		});
	}

	function bindInternalDragGuards() {
		document.addEventListener('dragenter', suppressInternalUploaderDrag, true);
		document.addEventListener('dragover', suppressInternalUploaderDrag, true);
		document.addEventListener('drop', handleDocumentDrop, true);
		document.addEventListener('dragend', clearInternalDragState, true);
	}

	function beginInternalDrag() {
		state.internalDragActive = true;
		document.body.classList.add('myfiles-internal-drag');
	}

	function clearInternalDragState() {
		state.internalDragActive = false;
		state.draggingFolder = null;
		document.body.classList.remove('myfiles-internal-drag');
		clearDropIndicators();
		clearDragPreview();
	}

	function suppressInternalUploaderDrag(event) {
		if (!isInternalDragEvent(event) || isInsideMyFilesPanel(event.target)) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
	}

	function handleDocumentDrop(event) {
		if (!isInternalDragEvent(event) || isInsideMyFilesPanel(event.target)) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		clearInternalDragState();
	}

	function isInternalDragEvent(event) {
		if (state.internalDragActive) {
			return true;
		}

		const types = event.dataTransfer && event.dataTransfer.types ? Array.from(event.dataTransfer.types) : [];

		return types.includes('application/x-myfiles-folder')
			|| types.includes('application/x-myfiles-attachments');
	}

	function isInsideMyFilesPanel(target) {
		return target instanceof Element && Boolean(target.closest('.myfiles-panel'));
	}

	function sortDraggedFolder(draggedId, targetFolderId, dropPosition) {
		const folders = state.folders.map((folder) => ({
			id: Number(folder.id),
			name: folder.name,
			parent: Number(folder.parent || 0),
			order: Number(folder.order || 0),
		}));
		const dragged = folders.find((folder) => folder.id === draggedId);

		if (!dragged) {
			return;
		}

		if (!isNumericFolder(targetFolderId)) {
			dragged.parent = 0;
			saveFolderModel(placeFolderAtEnd(folders, dragged));
			return;
		}

		const targetId = Number(targetFolderId);
		const target = folders.find((folder) => folder.id === targetId);

		if (!target || target.id === dragged.id || isDescendantFolder(target.id, dragged.id)) {
			return;
		}

		if (dropPosition === 'inside') {
			dragged.parent = target.id;
			saveFolderModel(placeFolderAtEnd(folders, dragged));
			return;
		}

		dragged.parent = target.parent;
		saveFolderModel(placeFolderBesideTarget(folders, dragged, target, dropPosition));
	}

	function placeFolderAtEnd(folders, dragged) {
		const siblings = sortFolderSiblings(folders.filter((folder) => folder.parent === dragged.parent && folder.id !== dragged.id));
		siblings.push(dragged);

		return applySiblingOrder(folders, dragged.parent, siblings);
	}

	function placeFolderBesideTarget(folders, dragged, target, dropPosition) {
		const siblings = sortFolderSiblings(folders.filter((folder) => folder.parent === target.parent && folder.id !== dragged.id));
		const targetIndex = siblings.findIndex((folder) => folder.id === target.id);
		const insertIndex = targetIndex < 0
			? siblings.length
			: targetIndex + (dropPosition === 'after' ? 1 : 0);

		siblings.splice(insertIndex, 0, dragged);

		return applySiblingOrder(folders, target.parent, siblings);
	}

	function sortFolderSiblings(folders) {
		return folders.slice().sort((a, b) => {
			if (a.order === b.order) {
				return a.name.localeCompare(b.name);
			}

			return a.order - b.order;
		});
	}

	function applySiblingOrder(folders, parent, orderedSiblings) {
		const byId = new Map(folders.map((folder) => [folder.id, folder]));

		orderedSiblings.forEach((folder, index) => {
			const current = byId.get(folder.id);

			if (current) {
				current.parent = parent;
				current.order = index;
			}
		});

		return Array.from(byId.values());
	}

	function saveFolderModel(folders) {
		api('/folders/order', {
			method: 'POST',
			data: {
				items: folders.map((folder) => ({
					id: folder.id,
					parent: folder.parent,
					order: folder.order,
				})),
			},
		})
			.then(fetchFolders)
			.catch(showError);
	}

	function sortFoldersAlphabetically() {
		if (!config.canManage || state.isSorting || state.folders.length < 2) {
			return;
		}

		if (!window.confirm(text.sortConfirm || 'Sort all folders alphabetically? This replaces your current custom folder order.')) {
			return;
		}

		const folders = state.folders.map((folder) => ({
			id: Number(folder.id),
			name: String(folder.name || ''),
			parent: Number(folder.parent || 0),
			order: Number(folder.order || 0),
		}));

		if (!folders.length) {
			return;
		}

		const validIds = new Set(folders.map((folder) => folder.id));
		const childrenByParent = new Map();

		folders.forEach((folder) => {
			const parent = folder.parent > 0 && validIds.has(folder.parent) ? folder.parent : 0;

			folder.parent = parent;

			if (!childrenByParent.has(parent)) {
				childrenByParent.set(parent, []);
			}

			childrenByParent.get(parent).push(folder);
		});

		// Sort each parent level independently so the full hierarchy is preserved.
		childrenByParent.forEach((siblings) => {
			siblings.sort(compareFolderNames);
			siblings.forEach((folder, index) => {
				folder.order = index;
			});
		});

		saveSortedFolders(folders);
	}

	function compareFolderNames(a, b) {
		const result = a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });

		if (result !== 0) {
			return result;
		}

		// Stable fallback for identical names.
		return a.id - b.id;
	}

	function saveSortedFolders(folders) {
		state.isSorting = true;
		renderPanels();
		showStatus(text.sorting || 'Sorting folders...', 'info');

		api('/folders/order', {
			method: 'POST',
			data: {
				items: folders.map((folder) => ({
					id: folder.id,
					parent: folder.parent,
					order: folder.order,
				})),
			},
		})
			.then(fetchFolders)
			.then(() => {
				showStatus(text.sortSuccess || 'Folders sorted alphabetically.', 'success');
			})
			.catch(showError)
			.finally(() => {
				state.isSorting = false;
				renderPanels();
			});
	}

	function isDescendantFolder(folderId, possibleAncestorId) {
		let current = state.folders.find((folder) => Number(folder.id) === Number(folderId));

		while (current && Number(current.parent) > 0) {
			if (Number(current.parent) === Number(possibleAncestorId)) {
				return true;
			}

			current = state.folders.find((folder) => Number(folder.id) === Number(current.parent));
		}

		return false;
	}

	function observeAttachmentDraggables() {
		markAttachmentDraggables();
		document.addEventListener('dragstart', handleAttachmentDragStart, true);

		const observer = new MutationObserver(markAttachmentDraggables);
		observer.observe(document.body, { childList: true, subtree: true });
	}

	function bindSelectionMemory() {
		document.addEventListener('change', (event) => {
			if (event.target.matches('#the-list input[name="media[]"]')) {
				rememberSelectedAttachments(true);
			}
		}, true);

		document.addEventListener('click', (event) => {
			if (isNativeMediaControl(event.target)) {
				window.setTimeout(() => rememberSelectedAttachments(true), 120);
				return;
			}

			if (event.target.closest('.attachment, .attachments, #the-list input[name="media[]"], .check-column, .media-toolbar, .media-frame-content')) {
				const attachment = event.target.closest('.attachment[data-id], tr[id^="post-"]');

				if (state.quickMoveMode && attachment && !attachment.closest('.myfiles-panel')) {
					event.preventDefault();
					event.stopImmediatePropagation();
					event.stopPropagation();
					toggleQuickAttachment(attachment);
					return;
				}

				window.setTimeout(() => rememberSelectedAttachments(true), 0);
				window.setTimeout(() => rememberSelectedAttachments(true), 80);
			}
		}, true);

		document.addEventListener('mousedown', (event) => {
			if (!state.quickMoveMode || isNativeMediaControl(event.target)) {
				return;
			}

			const attachment = event.target.closest('.attachment[data-id], tr[id^="post-"]');

			if (attachment && !attachment.closest('.myfiles-panel')) {
				event.stopImmediatePropagation();
				event.stopPropagation();
			}
		}, true);

		document.addEventListener('keyup', (event) => {
			if (event.key === ' ' || event.key === 'Enter') {
				window.setTimeout(() => rememberSelectedAttachments(true), 0);
			}
		}, true);
	}

	function isNativeMediaControl(target) {
		if (!(target instanceof Element) || target.closest('.myfiles-panel')) {
			return false;
		}

		if (target.closest('.attachment[data-id], tr[id^="post-"]')) {
			return false;
		}

		return Boolean(target.closest([
			'.delete-selected-button',
			'.delete-attachment',
			'.media-toolbar',
			'.media-sidebar',
			'.media-modal',
			'.media-frame-menu',
			'.media-frame-router',
			'.button',
			'a',
			'button',
			'input',
			'select',
			'textarea',
		].join(',')));
	}

	function bindUploadRefresh() {
		bindUploaderQueueRefresh();
		bindAjaxUploadRefresh();
		observeMediaGridRefresh();
	}

	function bindUploaderQueueRefresh(attempt = 0) {
		if (!wp.Uploader || !wp.Uploader.queue || typeof wp.Uploader.queue.on !== 'function') {
			if (attempt < 24) {
				window.setTimeout(() => bindUploaderQueueRefresh(attempt + 1), 500);
			}

			return;
		}

		if (state.uploaderQueueBound) {
			return;
		}

		state.uploaderQueueBound = true;
		wp.Uploader.queue.on('add remove reset change change:status', () => {
			handleUploadActivity();
		});
	}

	function bindAjaxUploadRefresh() {
		if (!window.jQuery) {
			return;
		}

		window.jQuery(document).ajaxComplete((event, xhr, settings) => {
			const data = settings && settings.data ? String(settings.data) : '';
			const url = settings && settings.url ? String(settings.url) : '';

			if (url.includes('async-upload.php') || data.includes('action=upload-attachment')) {
				if (xhr && (xhr.status < 200 || xhr.status >= 300)) {
					handleUploadFailed();
				} else {
					handleUploadCompleted();
				}
			}
		});
	}

	function observeMediaGridRefresh() {
		const observer = new MutationObserver(() => {
			const signature = getMediaGridSignature();

			if (signature && signature !== state.lastMediaGridSignature) {
				state.lastMediaGridSignature = signature;

				if (isRecentUploadActivity()) {
					scheduleUploadRefreshSequence();
				}
			}
		});

		observer.observe(document.body, {
			attributes: true,
			attributeFilter: ['aria-checked', 'class', 'data-id'],
			childList: true,
			subtree: true,
		});
		state.lastMediaGridSignature = getMediaGridSignature();
	}

	function scheduleUploadRefreshSequence(options = {}) {
		const delays = options.immediate
			? [250, 1000, 2200, 4200]
			: [700, 1800, 3600];

		state.folderRefreshTimers.forEach((timer) => window.clearTimeout(timer));
		state.folderRefreshTimers = delays.map((delay, index) => {
			return window.setTimeout(() => {
				refreshPostUploadUi(Boolean(options.showRefreshed && index === 1));
			}, delay);
		});
	}

	function refreshPostUploadUi(showRefreshed = false) {
		const mediaRefreshed = refreshMediaCollections();
		const foldersRefreshed = config.showCounts
			? fetchFolders({ silent: true })
			: Promise.resolve();

		foldersRefreshed
			.then(() => {
				if (showRefreshed) {
					showStatus(text.uploadRefreshed || 'Upload complete. Media Library refreshed.', 'success');
				}
			})
			.catch(showError);

		if (!mediaRefreshed && isUploadListView()) {
			scheduleUploadListReload();
		}
	}

	function scheduleUploadListReload() {
		if (state.uploadListReloadTimer) {
			window.clearTimeout(state.uploadListReloadTimer);
		}

		state.uploadListReloadTimer = window.setTimeout(() => {
			if (isUploadListView() && isRecentUploadActivity()) {
				window.location.href = buildUrlWithFolder(state.selected);
			}
		}, 1400);
	}

	function handleUploadStarted() {
		markUploadActivity();
		showStatus(text.uploading || 'Uploading...', 'info');
		scheduleUploadRefreshSequence();
	}

	function handleUploadCompleted() {
		markUploadActivity();
		showStatus(text.uploadComplete || 'Upload complete. Refreshing Media Library...', 'success');
		scheduleUploadRefreshSequence({ immediate: true, showRefreshed: true });
	}

	function handleUploadActivity() {
		markUploadActivity();
		scheduleUploadRefreshSequence();
	}

	function handleUploadFailed() {
		markUploadActivity();
		showStatus(text.uploadFailed || 'Upload failed. Please try again.', 'error');
		scheduleUploadRefreshSequence({ immediate: true });
	}

	function markUploadActivity() {
		state.lastUploadActivityAt = Date.now();
	}

	function isRecentUploadActivity() {
		return state.lastUploadActivityAt > 0 && Date.now() - state.lastUploadActivityAt < 20000;
	}

	function getMediaGridSignature() {
		return Array.from(document.querySelectorAll('.attachments .attachment[data-id], #the-list tr[id^="post-"]'))
			.map((element) => element.dataset.id || element.id)
			.filter(Boolean)
			.join(',');
	}

	function rememberSelectedAttachments(allowClear = false) {
		const ids = getSelectedAttachmentIdsFromDom();

		if (ids.length) {
			state.selectedAttachmentIds = ids;
			state.selectionSource = state.quickMoveMode ? 'quick' : 'native';
			renderPanels();
			return;
		}

		if (allowClear) {
			clearMoveSelection();
			renderPanels();
		}
	}

	function toggleQuickAttachment(element) {
		const id = getAttachmentId(element);

		if (!id) {
			return;
		}

		const current = new Set(state.selectedAttachmentIds.map(Number));

		if (current.has(id)) {
			current.delete(id);
		} else {
			current.add(id);
		}

		state.selectedAttachmentIds = Array.from(current);
		state.selectionSource = state.selectedAttachmentIds.length ? 'quick' : '';
		renderQuickSelections();
		renderPanels();
	}

	function renderQuickSelections() {
		const selected = new Set(state.selectedAttachmentIds.map(Number));

		document.querySelectorAll('.myfiles-quick-selected').forEach((element) => {
			const id = getAttachmentId(element);

			if (!state.quickMoveMode || !selected.has(id)) {
				element.classList.remove('myfiles-quick-selected');
			}
		});

		if (!state.quickMoveMode) {
			return;
		}

		document.querySelectorAll('.attachment[data-id], tr[id^="post-"]').forEach((element) => {
			element.classList.toggle('myfiles-quick-selected', selected.has(getAttachmentId(element)));
		});
	}

	function markAttachmentDraggables() {
		document.querySelectorAll('.attachment[data-id], tr[id^="post-"]').forEach((element) => {
			if (!element.dataset.myfilesDraggable) {
				element.draggable = true;
				element.dataset.myfilesDraggable = '1';
			}
		});
	}

	function handleAttachmentDragStart(event) {
		const element = event.target.closest('.attachment[data-id], tr[id^="post-"]');

		if (!element || element.closest('.myfiles-panel')) {
			return;
		}

		const draggedId = getAttachmentId(element);
		const selected = getMoveAttachmentIds();
		const ids = selected.includes(draggedId) ? selected : [draggedId];

		if (!ids.length || !draggedId) {
			return;
		}

		event.dataTransfer.effectAllowed = 'move';
		event.dataTransfer.setData('application/x-myfiles-attachments', ids.join(','));
		event.dataTransfer.setData('text/plain', ids.join(','));
		setAttachmentDragPreview(event, ids, element);
		beginInternalDrag();
	}

	function setAttachmentDragPreview(event, ids, draggedElement) {
		if (!event.dataTransfer || typeof event.dataTransfer.setDragImage !== 'function') {
			return;
		}

		clearDragPreview();

		const preview = buildAttachmentDragPreview(ids, draggedElement);
		document.body.appendChild(preview);
		state.dragPreview = preview;

		try {
			event.dataTransfer.setDragImage(preview, 28, 28);
		} catch (error) {
			clearDragPreview();
		}
	}

	function buildAttachmentDragPreview(ids, draggedElement) {
		const preview = document.createElement('div');
		const elements = ids
			.map((id) => findAttachmentElementById(id))
			.filter(Boolean);
		const previewElements = (elements.length ? elements : [draggedElement]).slice(0, 3);

		preview.className = 'myfiles-drag-preview';
		preview.setAttribute('aria-hidden', 'true');

		previewElements.forEach((element, index) => {
			const thumb = document.createElement('span');
			const image = element.querySelector('img');

			thumb.className = 'myfiles-drag-preview__thumb';
			thumb.style.setProperty('--myfiles-drag-index', String(index));

			if (image && image.getAttribute('src')) {
				const clone = document.createElement('img');
				clone.src = image.getAttribute('src');
				clone.alt = '';
				thumb.appendChild(clone);
			} else {
				const icon = document.createElement('span');
				icon.className = 'dashicons dashicons-format-image';
				thumb.appendChild(icon);
			}

			preview.appendChild(thumb);
		});

		const badge = document.createElement('span');
		badge.className = 'myfiles-drag-preview__count';
		badge.textContent = String(ids.length);
		preview.appendChild(badge);

		return preview;
	}

	function clearDragPreview() {
		if (state.dragPreview && state.dragPreview.parentNode) {
			state.dragPreview.parentNode.removeChild(state.dragPreview);
		}

		state.dragPreview = null;
	}

	function getMoveAttachmentIds(options = {}) {
		const currentIds = getSelectedAttachmentIdsFromDom();

		if (currentIds.length) {
			if (options.syncState) {
				state.selectedAttachmentIds = currentIds;
				state.selectionSource = state.quickMoveMode ? 'quick' : 'native';
			}

			return currentIds;
		}

		if (state.selectionSource && state.selectedAttachmentIds.length) {
			return state.selectedAttachmentIds.slice();
		}

		return [];
	}

	function getSelectedAttachmentIdsFromDom() {
		const ids = new Set();

		document.querySelectorAll('#the-list input[name="media[]"]:checked').forEach((input) => {
			const value = Number(input.value);
			if (value) {
				ids.add(value);
			}
		});

		if (state.quickMoveMode) {
			document.querySelectorAll('.attachment.myfiles-quick-selected[data-id], .attachments .myfiles-quick-selected[data-id]').forEach((item) => {
				const value = Number(item.dataset.id);
				if (value) {
					ids.add(value);
				}
			});
		}

		if (shouldUseNativeMediaSelection()) {
			document.querySelectorAll([
				'.attachment.selected[data-id]',
				'.attachment[aria-checked="true"][data-id]',
				'.attachments .selected[data-id]',
				'.attachments [aria-checked="true"][data-id]',
			].join(',')).forEach((item) => {
				const value = Number(item.dataset.id);
				if (value) {
					ids.add(value);
				}
			});

			getSelectedAttachmentIdsFromWordPressState().forEach((id) => {
				if (id) {
					ids.add(id);
				}
			});
		}

		return Array.from(ids);
	}

	function shouldUseNativeMediaSelection() {
		return isBulkSelectionMode() || Boolean(document.querySelector('.media-modal .media-frame'));
	}

	function clearMoveSelection() {
		state.selectedAttachmentIds = [];
		state.selectionSource = '';

		document.querySelectorAll('.myfiles-quick-selected').forEach((element) => {
			element.classList.remove('myfiles-quick-selected');
		});
	}

	function getSelectedAttachmentIdsFromWordPressState() {
		const ids = new Set();

		if (!wp.media || !wp.media.frame) {
			return [];
		}

		try {
			const frame = wp.media.frame;
			const stateModel = typeof frame.state === 'function' ? frame.state() : null;
			const selection = stateModel && typeof stateModel.get === 'function' ? stateModel.get('selection') : null;

			addModelIds(selection, ids);

			const library = stateModel && typeof stateModel.get === 'function' ? stateModel.get('library') : null;
			if (library && library.selected) {
				addModelIds(library.selected, ids);
			}

			const content = frame.content && typeof frame.content.get === 'function' ? frame.content.get() : null;
			const collection = content && content.collection ? content.collection : null;

			if (collection && collection.selected) {
				addModelIds(collection.selected, ids);
			}
		} catch (error) {
			return [];
		}

		return Array.from(ids);
	}

	function addModelIds(collection, ids) {
		if (!collection) {
			return;
		}

		const addModel = (model) => {
			const id = Number(model && typeof model.get === 'function' ? model.get('id') : model && model.id);

			if (id) {
				ids.add(id);
			}
		};

		if (typeof collection.each === 'function') {
			collection.each(addModel);
			return;
		}

		if (Array.isArray(collection.models)) {
			collection.models.forEach(addModel);
		}
	}

	function clearWordPressSelection() {
		if (!wp.media || !wp.media.frame) {
			return;
		}

		try {
			const frame = wp.media.frame;
			const stateModel = typeof frame.state === 'function' ? frame.state() : null;
			const selection = stateModel && typeof stateModel.get === 'function' ? stateModel.get('selection') : null;

			if (selection && typeof selection.reset === 'function') {
				selection.reset();
			}
		} catch (error) {
			// Selection clearing is best-effort; moving already succeeded.
		}
	}

	function getAttachmentId(element) {
		if (element.dataset.id) {
			return Number(element.dataset.id);
		}

		const match = (element.id || '').match(/^post-(\d+)$/);
		return match ? Number(match[1]) : 0;
	}

	function findAttachmentElementById(id) {
		const numericId = Number(id);

		if (!numericId) {
			return null;
		}

		return document.querySelector(`.attachment[data-id="${numericId}"], tr#post-${numericId}`);
	}

	function installMediaQueryBridge() {
		if (!wp.media || !wp.media.model || !wp.media.model.Query || wp.media.model.Query.prototype.myfilesPatched) {
			return;
		}

		const originalSync = wp.media.model.Query.prototype.sync;
		wp.media.model.Query.prototype.myfilesPatched = true;

		wp.media.model.Query.prototype.sync = function (method, model, options = {}) {
			options.data = options.data || {};
			options.data.query = options.data.query || {};
			options.data.query.myfiles_folder = state.selected;

			return originalSync.call(this, method, model, options);
		};
	}

	function refreshMediaCollections() {
		let refreshed = false;
		const collections = new Set();

		if (wp.media && wp.media.frame) {
			try {
				const frame = wp.media.frame;
				const view = frame.content && typeof frame.content.get === 'function' ? frame.content.get() : null;
				const stateModel = typeof frame.state === 'function' ? frame.state() : null;
				const stateLibrary = stateModel && typeof stateModel.get === 'function' ? stateModel.get('library') : null;

				if (view && view.collection) {
					collections.add(view.collection);
				}

				if (stateLibrary) {
					collections.add(stateLibrary);
				}

				collections.forEach((collection) => {
					if (refreshMediaCollection(collection)) {
						refreshed = true;
					}
				});
			} catch (error) {
				refreshed = false;
			}
		}

		return refreshed;
	}

	function refreshMediaCollection(collection) {
		if (!collection) {
			return false;
		}

		try {
			if (collection.props && typeof collection.props.set === 'function') {
				collection.props.set({
					myfiles_folder: state.selected,
					ignore: Date.now(),
				});
			}

			if (typeof collection.reset === 'function') {
				collection.reset();
			}

			if (typeof collection.more === 'function') {
				collection.more();
				return true;
			}

			if (typeof collection.fetch === 'function') {
				collection.fetch({ reset: true });
				return true;
			}
		} catch (error) {
			return false;
		}

		return false;
	}

	function updateUploaderParams() {
		if (!wp.Uploader) {
			return;
		}

		const folderParam = getSelectedUploadFolderParam();

		if (wp.Uploader.defaults) {
			wp.Uploader.defaults.multipart_params = applyUploadFolderParam(
				wp.Uploader.defaults.multipart_params || {},
				folderParam
			);
		}

		state.uploaders.forEach((uploader) => {
			if (!uploader || !uploader.uploader || !uploader.uploader.settings) {
				state.uploaders.delete(uploader);
				return;
			}

			uploader.uploader.settings.multipart_params = applyUploadFolderParam(
				uploader.uploader.settings.multipart_params || {},
				folderParam
			);
		});
	}

	function installUploaderParamBridge(attempt = 0) {
		if (!wp.Uploader || typeof wp.Uploader !== 'function') {
			if (attempt < 24) {
				window.setTimeout(() => installUploaderParamBridge(attempt + 1), 250);
			}

			return;
		}

		if (wp.Uploader.myfilesParamBridge) {
			return;
		}

		const OriginalUploader = wp.Uploader;

		function MyFilesUploader(options) {
			const uploader = new OriginalUploader(options);
			trackUploaderInstance(uploader);
			updateUploaderParams();
			return uploader;
		}

		Object.getOwnPropertyNames(OriginalUploader).forEach((key) => {
			if (['arguments', 'caller', 'length', 'name', 'prototype'].includes(key)) {
				return;
			}

			try {
				Object.defineProperty(MyFilesUploader, key, Object.getOwnPropertyDescriptor(OriginalUploader, key));
			} catch (error) {
				MyFilesUploader[key] = OriginalUploader[key];
			}
		});

		try {
			Object.setPrototypeOf(MyFilesUploader, OriginalUploader);
		} catch (error) {
			// Static properties were already copied above.
		}

		MyFilesUploader.prototype = OriginalUploader.prototype;
		MyFilesUploader.myfilesParamBridge = true;
		MyFilesUploader.myfilesOriginal = OriginalUploader;
		wp.Uploader = MyFilesUploader;
	}

	function trackUploaderInstance(uploader) {
		if (!uploader || !uploader.uploader || !uploader.uploader.settings) {
			return;
		}

		state.uploaders.add(uploader);

		if (typeof uploader.uploader.bind !== 'function') {
			return;
		}

		if (!uploader.uploader.myfilesFolderParamBound) {
			uploader.uploader.myfilesFolderParamBound = true;
			uploader.uploader.bind('BeforeUpload', () => {
				updateUploaderParams();
				handleUploadStarted();
			});
		}

		if (!uploader.uploader.myfilesFolderRefreshBound) {
			uploader.uploader.myfilesFolderRefreshBound = true;
			uploader.uploader.bind('FilesAdded', handleUploadStarted);
			uploader.uploader.bind('FileUploaded', handleUploadCompleted);
			uploader.uploader.bind('UploadComplete', handleUploadCompleted);
			uploader.uploader.bind('Error', handleUploadFailed);
			['StateChanged', 'QueueChanged'].forEach((eventName) => {
				uploader.uploader.bind(eventName, handleUploadActivity);
			});
		}
	}

	function getSelectedUploadFolderParam() {
		if (isNumericFolder(state.selected)) {
			return String(Number(state.selected));
		}

		if (state.selected === 'uncategorized') {
			return '0';
		}

		return null;
	}

	function applyUploadFolderParam(params, folderParam) {
		const next = Object.assign({}, params || {});

		if (folderParam === null) {
			delete next.myfiles_folder;
		} else {
			next.myfiles_folder = folderParam;
		}

		return next;
	}

	function updateUrl() {
		if (!window.history || !window.history.replaceState || !document.body.classList.contains('upload-php')) {
			return;
		}

		window.history.replaceState({}, '', buildUrlWithFolder(state.selected));
	}

	function buildUrlWithFolder(folderId) {
		const url = new URL(window.location.href);

		if (!folderId || folderId === 'all') {
			url.searchParams.delete('myfiles_folder');
		} else {
			url.searchParams.set('myfiles_folder', folderId);
		}

		return url.toString();
	}

	function getUrlFolder() {
		return new URL(window.location.href).searchParams.get('myfiles_folder');
	}

	function getSelectedFolder() {
		return state.folders.find((folder) => String(folder.id) === String(state.selected));
	}

	function groupFolders(folders) {
		const folderIds = new Set(folders.map((folder) => Number(folder.id)).filter(Boolean));

		return folders.reduce((grouped, folder) => {
			const rawParent = Number(folder.parent || 0);
			const parent = rawParent > 0 && folderIds.has(rawParent) ? rawParent : 0;

			grouped[parent] = grouped[parent] || [];
			grouped[parent].push(folder);
			return grouped;
		}, {});
	}

	function loadCollapsedFolders() {
		if (!window.localStorage) {
			return new Set();
		}

		try {
			const parsed = JSON.parse(window.localStorage.getItem('myfilesCollapsedFolders') || '[]');
			return new Set(Array.isArray(parsed) ? parsed.map(String) : []);
		} catch (error) {
			return new Set();
		}
	}

	function persistCollapsedFolders() {
		if (!window.localStorage) {
			return;
		}

		try {
			window.localStorage.setItem('myfilesCollapsedFolders', JSON.stringify(Array.from(state.collapsedFolders)));
		} catch (error) {
			// Persisting collapsed state is optional.
		}
	}

	function toggleFolder(folderId) {
		const id = normalizeFolder(folderId);

		if (!isNumericFolder(id)) {
			return;
		}

		setFolderCollapsed(id, !state.collapsedFolders.has(id));
	}

	function setFolderCollapsed(folderId, collapsed) {
		const id = normalizeFolder(folderId);

		if (!isNumericFolder(id)) {
			return;
		}

		if (collapsed) {
			state.collapsedFolders.add(id);
		} else {
			state.collapsedFolders.delete(id);
		}

		persistCollapsedFolders();
		renderPanels();
	}

	function expandAncestors(folderId) {
		const id = normalizeFolder(folderId);

		if (!isNumericFolder(id) || !Array.isArray(state.folders)) {
			return;
		}

		let current = state.folders.find((folder) => String(folder.id) === id);
		const seen = new Set();
		let changed = false;

		while (current && Number(current.parent) > 0 && !seen.has(String(current.id))) {
			seen.add(String(current.id));

			const parent = String(current.parent);

			if (state.collapsedFolders.delete(parent)) {
				changed = true;
			}

			current = state.folders.find((folder) => String(folder.id) === parent);
		}

		if (changed) {
			persistCollapsedFolders();
		}
	}

	function normalizeFolder(folderId) {
		if (folderId === 'uncategorized' || folderId === 'all') {
			return folderId;
		}

		const numeric = Number(folderId);
		return numeric > 0 ? String(numeric) : 'all';
	}

	function isNumericFolder(folderId) {
		return Number(folderId) > 0;
	}

	function isUploadListView() {
		return Boolean(document.querySelector('body.upload-php #posts-filter .wp-list-table'));
	}

	function isBulkSelectionMode() {
		return Boolean(
			document.body.classList.contains('bulk-select-mode') ||
			document.querySelector('.media-frame.mode-select, .media-frame.mode-bulk, .media-frame.mode-grid.mode-select') ||
			document.querySelector('.media-toolbar-secondary .delete-selected-button:not([disabled])')
		);
	}

	function setLoading(isLoading) {
		document.querySelectorAll('.myfiles-status').forEach((status) => {
			status.hidden = !isLoading;
			status.textContent = isLoading ? (text.loading || 'Loading folders...') : '';
		});
	}

	function showError(error) {
		const message = error && error.message ? error.message : (text.error || 'MY Files PRO could not complete that action.');

		showStatus(message, 'error');
	}

	function showStatus(message, type = 'info') {
		document.querySelectorAll('.myfiles-status').forEach((status) => {
			status.hidden = false;
			status.className = `myfiles-status myfiles-status--${type}`;
			status.textContent = message;
		});
	}

	function formatMovedMessage(count, folderId) {
		const folderName = getFolderLabel(folderId);
		const template = count === 1
			? (text.movedOne || 'Moved 1 file to %s.')
			: (text.movedMany || 'Moved %d files to %s.');

		return template.replace('%d', String(count)).replace('%s', folderName);
	}

	function getFolderLabel(folderId) {
		if (folderId === 'uncategorized') {
			return state.special.uncategorized && state.special.uncategorized.name
				? state.special.uncategorized.name
				: 'Uncategorized';
		}

		const folder = state.folders.find((item) => String(item.id) === String(folderId));

		return folder ? folder.name : 'folder';
	}

	function escapeHtml(value) {
		return String(value).replace(/[&<>"']/g, (character) => {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;',
			}[character];
		});
	}

	function escapeAttribute(value) {
		return escapeHtml(value).replace(/`/g, '&#096;');
	}
}());
