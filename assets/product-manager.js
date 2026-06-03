(function ($) {
	'use strict';

	var cfg = window.boulkUpProducts || {};
	var state = {
		page: 1,
		chunk: 0,
		perPage: 1000,
		search: '',
		total: 0,
		selected: {},
		loading: false,
		appendMode: false,
		viewMode: 'all'
	};

	var dupState = {
		page: 1,
		search: '',
		groups: []
	};

	var $root, $tbody, $spinner, $range, $capped, $progress;
	var $dupTbody, $dupSpinner, $dupSummary, $dupRange;

	function init() {
		$root = $('#boulk-product-manager');
		if (!$root.length) {
			return;
		}

		$tbody = $('#boulk-pm-tbody');
		$spinner = $('#boulk-pm-spinner');
		$range = $('#boulk-pm-range');
		$capped = $('#boulk-pm-capped-notice');
		$progress = $('#boulk-pm-progress');
		$dupTbody = $('#boulk-pm-dup-tbody');
		$dupSpinner = $('#boulk-pm-dup-spinner');
		$dupSummary = $('#boulk-pm-dup-summary');
		$dupRange = $('#boulk-pm-dup-range');

		var savedView = localStorage.getItem('boulk_pm_view');
		if (savedView === 'duplicates') {
			state.viewMode = 'duplicates';
		}

		var savedPerPage = localStorage.getItem('boulk_pm_per_page');
		if (savedPerPage) {
			$('#boulk-pm-per-page').val(savedPerPage);
			state.perPage = savedPerPage === 'all' ? 'all' : parseInt(savedPerPage, 10) || 1000;
		} else {
			state.perPage = parseInt($('#boulk-pm-per-page').val(), 10) || 1000;
		}

		bindEvents();
		setViewMode(state.viewMode, true);
	}

	function setViewMode(mode, skipSave) {
		state.viewMode = mode === 'duplicates' ? 'duplicates' : 'all';
		if (!skipSave) {
			localStorage.setItem('boulk_pm_view', state.viewMode);
		}

		$('.boulk-pm-view-tabs .nav-tab').removeClass('nav-tab-active');
		$('.boulk-pm-view-tabs .nav-tab[data-view="' + state.viewMode + '"]').addClass('nav-tab-active');

		if (state.viewMode === 'duplicates') {
			$('#boulk-pm-all-panel').hide();
			$('#boulk-pm-dup-panel').show();
			loadDuplicates(false);
		} else {
			$('#boulk-pm-dup-panel').hide();
			$('#boulk-pm-all-panel').show();
			loadProducts(false);
		}
	}

	function bindEvents() {
		$('.boulk-pm-view-tabs .nav-tab').on('click', function (e) {
			e.preventDefault();
			var view = $(this).data('view');
			if (view === state.viewMode) {
				return;
			}
			state.selected = {};
			updateSelectionCount();
			updateDupSelectionCount();
			setViewMode(view, false);
		});

		$('#boulk-pm-dup-scan').on('click', function () {
			dupState.search = $('#boulk-pm-dup-search').val().trim();
			dupState.page = 1;
			loadDuplicates(false);
		});

		$('#boulk-pm-dup-search').on('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				$('#boulk-pm-dup-scan').trigger('click');
			}
		});

		$('#boulk-pm-dup-prev').on('click', function () {
			if (dupState.page > 1) {
				dupState.page--;
				loadDuplicates(false);
			}
		});

		$('#boulk-pm-dup-next').on('click', function () {
			dupState.page++;
			loadDuplicates(false);
		});

		$('#boulk-pm-dup-select-extras').on('click', selectDupExtrasOnPage);
		$('#boulk-pm-dup-select-all-extras').on('click', selectAllDupExtras);
		$('#boulk-pm-dup-clear').on('click', clearDupSelection);

		$('#boulk-pm-dup-delete').on('click', function () {
			var ids = getSelectedIds();
			if (!ids.length) {
				window.alert(cfg.i18n.noSelection);
				return;
			}
			if (!window.confirm(cfg.i18n.confirmDelete)) {
				return;
			}
			runBulkDelete(ids);
		});

		$dupTbody.on('change', '.boulk-pm-row-cb', function () {
			var id = parseInt($(this).val(), 10);
			if ($(this).prop('checked')) {
				state.selected[id] = true;
			} else {
				delete state.selected[id];
			}
			$(this).closest('tr').toggleClass('is-selected', $(this).prop('checked'));
			updateDupSelectionCount();
		});

		$('#boulk-pm-per-page').on('change', function () {
			var val = $(this).val();
			localStorage.setItem('boulk_pm_per_page', val);
			state.perPage = val === 'all' ? 'all' : parseInt(val, 10);
			state.page = 1;
			state.chunk = 0;
			loadProducts(false);
		});

		$('#boulk-pm-reload').on('click', function () {
			state.search = $('#boulk-pm-search').val().trim();
			state.page = 1;
			state.chunk = 0;
			loadProducts(false);
		});

		$('#boulk-pm-search').on('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				$('#boulk-pm-reload').trigger('click');
			}
		});

		$('#boulk-pm-prev').on('click', function () {
			if (state.page > 1) {
				state.page--;
				loadProducts(false);
			}
		});

		$('#boulk-pm-next').on('click', function () {
			state.page++;
			loadProducts(false);
		});

		$('#boulk-pm-next-chunk').on('click', function () {
			state.chunk++;
			loadProducts(true);
		});

		$('#boulk-pm-check-all').on('change', function () {
			var checked = $(this).prop('checked');
			$tbody.find('.boulk-pm-row-cb').each(function () {
				var id = parseInt($(this).val(), 10);
				$(this).prop('checked', checked);
				if (checked) {
					state.selected[id] = true;
				} else {
					delete state.selected[id];
				}
				$(this).closest('tr').toggleClass('is-selected', checked);
			});
			updateSelectionCount();
		});

		$('#boulk-pm-select-page').on('click', function () {
			$('#boulk-pm-check-all').prop('checked', true).trigger('change');
		});

		$('#boulk-pm-select-all').on('click', selectAllMatching);

		$('#boulk-pm-clear-selection').on('click', function () {
			state.selected = {};
			$('#boulk-pm-check-all').prop('checked', false);
			$tbody.find('.boulk-pm-row-cb').prop('checked', false);
			$tbody.find('tr').removeClass('is-selected');
			updateSelectionCount();
		});

		$('#boulk-pm-bulk-update').on('click', openModal);
		$('#boulk-pm-modal-cancel, .boulk-pm-modal-backdrop').on('click', closeModal);
		$('#boulk-pm-modal-apply').on('click', applyBulkUpdate);

		$('#boulk-pm-bulk-delete').on('click', function () {
			var ids = getSelectedIds();
			if (!ids.length) {
				window.alert(cfg.i18n.noSelection);
				return;
			}
			if (!window.confirm(cfg.i18n.confirmDelete)) {
				return;
			}
			runBulkDelete(ids);
		});

		$tbody.on('change', '.boulk-pm-row-cb', function () {
			var id = parseInt($(this).val(), 10);
			if ($(this).prop('checked')) {
				state.selected[id] = true;
			} else {
				delete state.selected[id];
				$('#boulk-pm-check-all').prop('checked', false);
			}
			$(this).closest('tr').toggleClass('is-selected', $(this).prop('checked'));
			updateSelectionCount();
		});
	}

	function setLoading(on) {
		state.loading = on;
		$root.toggleClass('is-busy', on);
		if (state.viewMode === 'duplicates') {
			$dupSpinner.toggleClass('is-active', on);
		} else {
			$spinner.toggleClass('is-active', on);
		}
	}

	function loadDuplicates() {
		setLoading(true);

		$.post(cfg.ajaxUrl, {
			action: 'boulk_up_products_duplicates_list',
			nonce: cfg.nonce,
			page: dupState.page,
			search: dupState.search
		})
			.done(function (response) {
				if (!response.success || !response.data) {
					window.alert(cfg.i18n.loadError);
					return;
				}
				renderDuplicates(response.data);
			})
			.fail(function () {
				window.alert(cfg.i18n.loadError);
			})
			.always(function () {
				setLoading(false);
			});
	}

	function renderDuplicates(data) {
		dupState.groups = data.groups || [];
		$dupTbody.empty();

		if (!dupState.groups.length) {
			$dupTbody.html(
				'<tr><td colspan="9">' + escHtml(cfg.i18n.noDuplicates) + '</td></tr>'
			);
		} else {
			dupState.groups.forEach(function (group) {
				var header = cfg.i18n.dupGroupHeader
					.replace('%1$s', group.sku || '')
					.replace('%2$s', group.regular_price || '—')
					.replace('%3$d', group.count || 0);
				$dupTbody.append(
					'<tr class="boulk-pm-dup-group"><td colspan="9"><strong>' + escHtml(header) + '</strong></td></tr>'
				);

				(group.products || []).forEach(function (row) {
					var checked = !!state.selected[row.id];
					var keeperBadge = row.is_keeper
						? ' <span class="boulk-pm-keeper">' + escHtml(cfg.i18n.keeper) + '</span>'
						: '';
					$dupTbody.append(
						'<tr class="' + (checked ? 'is-selected' : '') + (row.is_keeper ? ' is-keeper' : '') + '">' +
						'<th scope="row" class="check-column">' +
						'<input type="checkbox" class="boulk-pm-row-cb" value="' + row.id + '"' +
						(row.is_keeper ? ' disabled' : '') +
						(checked ? ' checked' : '') + ' /></th>' +
						'<td>' + row.id + keeperBadge + '</td>' +
						'<td><code>' + escHtml(row.sku || '') + '</code></td>' +
						'<td class="col-name" title="' + escAttr(row.name) + '">' + escHtml(row.name) + '</td>' +
						'<td>' + escHtml(row.regular_price || '') + '</td>' +
						'<td>' + escHtml(row.sale_price || '') + '</td>' +
						'<td>' + escHtml(formatDate(row.created)) + '</td>' +
						'<td>' + escHtml(row.status || '') + '</td>' +
						'<td><a href="' + escAttr(row.edit_url) + '">' + escHtml('Edit') + '</a></td>' +
						'</tr>'
					);
				});
			});
		}

		var summary = cfg.i18n.dupSummary
			.replace('%1$s', (data.total_groups || 0).toLocaleString())
			.replace('%2$s', (data.duplicate_products || 0).toLocaleString());
		$dupSummary.text(summary);

		var rangeText = cfg.i18n.dupShowing
			.replace('%1$s', data.from || 0)
			.replace('%2$s', data.to || 0)
			.replace('%3$s', (data.total_groups || 0).toLocaleString());
		$dupRange.text(rangeText);

		$('#boulk-pm-dup-prev').toggle(dupState.page > 1);
		$('#boulk-pm-dup-next').toggle(!!data.has_more);
		updateDupSelectionCount();
	}

	function selectDupExtrasOnPage() {
		dupState.groups.forEach(function (group) {
			(group.products || []).forEach(function (row) {
				if (!row.is_keeper) {
					state.selected[row.id] = true;
				}
			});
		});
		syncDupCheckboxes();
	}

	function selectAllDupExtras() {
		setLoading(true);
		$('#boulk-pm-dup-selection-count').text(cfg.i18n.selectingAll);

		$.post(cfg.ajaxUrl, {
			action: 'boulk_up_products_duplicate_extra_ids',
			nonce: cfg.nonce,
			search: dupState.search
		})
			.done(function (response) {
				if (!response.success || !response.data || !response.data.ids) {
					window.alert(cfg.i18n.loadError);
					return;
				}
				response.data.ids.forEach(function (id) {
					state.selected[id] = true;
				});
				syncDupCheckboxes();
			})
			.fail(function () {
				window.alert(cfg.i18n.loadError);
			})
			.always(function () {
				setLoading(false);
			});
	}

	function syncDupCheckboxes() {
		$dupTbody.find('.boulk-pm-row-cb').each(function () {
			var id = parseInt($(this).val(), 10);
			var checked = !!state.selected[id];
			$(this).prop('checked', checked);
			$(this).closest('tr').toggleClass('is-selected', checked);
		});
		updateDupSelectionCount();
	}

	function clearDupSelection() {
		state.selected = {};
		syncDupCheckboxes();
	}

	function updateDupSelectionCount() {
		var n = getSelectedIds().length;
		var text = cfg.i18n.selected.replace('%d', n.toLocaleString());
		$('#boulk-pm-dup-selection-count').text(text);
	}

	function formatDate(dateStr) {
		if (!dateStr) {
			return '';
		}
		var d = new Date(dateStr.replace(' ', 'T'));
		if (isNaN(d.getTime())) {
			return dateStr;
		}
		return d.toLocaleString();
	}

	function reloadCurrentView() {
		state.page = 1;
		state.chunk = 0;
		dupState.page = 1;
		if (state.viewMode === 'duplicates') {
			loadDuplicates(false);
		} else {
			loadProducts(false);
		}
	}

	function loadProducts(append) {
		state.appendMode = !!append;
		if (!append) {
			$('#boulk-pm-check-all').prop('checked', false);
		}
		setLoading(true);

		$.post(cfg.ajaxUrl, {
			action: 'boulk_up_products_list',
			nonce: cfg.nonce,
			page: state.page,
			per_page: state.perPage === 'all' ? 'all' : state.perPage,
			search: state.search,
			chunk: state.chunk
		})
			.done(function (response) {
				if (!response.success || !response.data) {
					window.alert(cfg.i18n.loadError);
					return;
				}
				renderList(response.data, append);
			})
			.fail(function () {
				window.alert(cfg.i18n.loadError);
			})
			.always(function () {
				setLoading(false);
			});
	}

	function renderList(data, append) {
		state.total = data.total || 0;

		if (!append) {
			$tbody.empty();
		}

		if (!data.rows || !data.rows.length) {
			if (!append) {
				$tbody.html(
					'<tr><td colspan="9">' + escHtml(cfg.i18n.noProducts) + '</td></tr>'
				);
			}
		} else {
			data.rows.forEach(function (row) {
				var checked = !!state.selected[row.id];
				$tbody.append(
					'<tr class="' + (checked ? 'is-selected' : '') + '">' +
					'<th scope="row" class="check-column">' +
					'<input type="checkbox" class="boulk-pm-row-cb" value="' + row.id + '"' +
					(checked ? ' checked' : '') + ' /></th>' +
					'<td>' + row.id + '</td>' +
					'<td><code>' + escHtml(row.sku || '') + '</code></td>' +
					'<td class="col-name" title="' + escAttr(row.name) + '">' + escHtml(row.name) + '</td>' +
					'<td>' + escHtml(row.regular_price || '') + '</td>' +
					'<td>' + escHtml(row.sale_price || '') + '</td>' +
					'<td>' + escHtml(row.stock_status || '') + '</td>' +
					'<td>' + escHtml(row.status || '') + '</td>' +
					'<td><a href="' + escAttr(row.edit_url) + '">' + escHtml('Edit') + '</a></td>' +
					'</tr>'
				);
			});
		}

		var rangeText = cfg.i18n.showing
			.replace('%1$s', data.from || 0)
			.replace('%2$s', data.to || 0)
			.replace('%3$s', (data.total || 0).toLocaleString());
		$range.text(rangeText);

		if (data.capped) {
			$capped.text(cfg.i18n.cappedNotice).show();
		} else {
			$capped.hide();
		}

		var isAll = state.perPage === 'all' || data.per_page === -1;
		$('#boulk-pm-prev, #boulk-pm-next').hide();
		$('#boulk-pm-next-chunk').toggle(isAll && data.has_more);

		if (!isAll) {
			$('#boulk-pm-prev').toggle(state.page > 1);
			$('#boulk-pm-next').toggle(!!data.has_more);
		}
	}

	function selectAllMatching() {
		setLoading(true);
		$('#boulk-pm-selection-count').text(cfg.i18n.selectingAll);

		$.post(cfg.ajaxUrl, {
			action: 'boulk_up_products_select_all_ids',
			nonce: cfg.nonce,
			search: state.search
		})
			.done(function (response) {
				if (!response.success || !response.data || !response.data.ids) {
					window.alert(cfg.i18n.loadError);
					return;
				}
				response.data.ids.forEach(function (id) {
					state.selected[id] = true;
				});
				$tbody.find('.boulk-pm-row-cb').each(function () {
					var id = parseInt($(this).val(), 10);
					if (state.selected[id]) {
						$(this).prop('checked', true);
						$(this).closest('tr').addClass('is-selected');
					}
				});
				updateSelectionCount();
			})
			.fail(function () {
				window.alert(cfg.i18n.loadError);
			})
			.always(function () {
				setLoading(false);
			});
	}

	function getSelectedIds() {
		return Object.keys(state.selected).map(function (k) {
			return parseInt(k, 10);
		}).filter(function (id) {
			return id > 0;
		});
	}

	function updateSelectionCount() {
		var n = getSelectedIds().length;
		var text = cfg.i18n.selected.replace('%d', n.toLocaleString());
		$('#boulk-pm-selection-count').text(text);
	}

	function openModal() {
		if (!getSelectedIds().length) {
			window.alert(cfg.i18n.noSelection);
			return;
		}
		$('#boulk-pm-modal').show();
	}

	function closeModal() {
		$('#boulk-pm-modal').hide();
	}

	function applyBulkUpdate() {
		var ids = getSelectedIds();
		if (!ids.length) {
			window.alert(cfg.i18n.noSelection);
			return;
		}
		closeModal();
		runBulkUpdate(ids);
	}

	function runBulkUpdate(ids) {
		postBulkUpdate(ids);
	}

	function postBulkUpdate(ids) {
		setLoading(true);
		showProgress(true, cfg.i18n.processing, 0);

		$.post(cfg.ajaxUrl, {
			action: 'boulk_up_products_bulk_update',
			nonce: cfg.nonce,
			ids: ids,
			regular_price: $('#boulk-pm-regular-price').val(),
			sale_price: $('#boulk-pm-sale-price').val(),
			stock_status: $('#boulk-pm-stock-status').val()
		})
			.done(function (response) {
				if (!response.success) {
					window.alert(response.data && response.data.message ? response.data.message : cfg.i18n.loadError);
					setLoading(false);
					return;
				}
				var d = response.data;
				if (d.queued && d.job_id) {
					pollBulkJob(d.job_id, function (finalData) {
						setLoading(false);
						finishAction(
							cfg.i18n.updateDone.replace('%1$d', finalData.succeeded).replace('%2$d', finalData.failed)
						);
					});
				} else {
					setLoading(false);
					finishAction(
						cfg.i18n.updateDone.replace('%1$d', d.succeeded || 0).replace('%2$d', d.failed || 0)
					);
				}
			})
			.fail(function () {
				window.alert(cfg.i18n.loadError);
				setLoading(false);
			});
	}

	function runBulkDelete(ids) {
		if (ids.length > cfg.syncThreshold) {
			postBulkDelete(ids);
			return;
		}

		var batch = cfg.deleteBatch || 100;
		var offset = 0;
		var succeeded = 0;
		var failed = 0;

		function nextBatch() {
			var slice = ids.slice(offset, offset + batch);
			if (!slice.length) {
				finishAction(
					cfg.i18n.deleteDone.replace('%1$d', succeeded).replace('%2$d', failed)
				);
				return;
			}
			setLoading(true);
			showProgress(true, cfg.i18n.processing, Math.round((offset / ids.length) * 100));

			$.post(cfg.ajaxUrl, {
				action: 'boulk_up_products_bulk_delete',
				nonce: cfg.nonce,
				ids: slice
			})
				.done(function (response) {
					if (!response.success) {
						window.alert(response.data && response.data.message ? response.data.message : cfg.i18n.loadError);
						return;
					}
					var d = response.data;
					succeeded += d.succeeded || 0;
					failed += d.failed || 0;
					offset += slice.length;
					nextBatch();
				})
				.fail(function () {
					window.alert(cfg.i18n.loadError);
				})
				.always(function () {
					if (offset >= ids.length) {
						setLoading(false);
					}
				});
		}

		nextBatch();
	}

	function postBulkDelete(ids) {
		setLoading(true);
		showProgress(true, cfg.i18n.processing, 0);

		$.post(cfg.ajaxUrl, {
			action: 'boulk_up_products_bulk_delete',
			nonce: cfg.nonce,
			ids: ids
		})
			.done(function (response) {
				if (!response.success) {
					window.alert(response.data && response.data.message ? response.data.message : cfg.i18n.loadError);
					return;
				}
				var d = response.data;
				if (d.queued && d.job_id) {
					pollBulkJob(d.job_id, function (finalData) {
						setLoading(false);
						finishAction(
							cfg.i18n.deleteDone.replace('%1$d', finalData.succeeded).replace('%2$d', finalData.failed)
						);
					});
				} else {
					setLoading(false);
					finishAction(
						cfg.i18n.deleteDone.replace('%1$d', d.succeeded || 0).replace('%2$d', d.failed || 0)
					);
				}
			})
			.fail(function () {
				window.alert(cfg.i18n.loadError);
				setLoading(false);
			});
	}

	function pollBulkJob(jobId, onComplete) {
		function tick() {
			$.post(cfg.ajaxUrl, {
				action: 'boulk_up_bulk_action_status',
				nonce: cfg.nonce,
				job_id: jobId
			})
				.done(function (response) {
					if (!response.success || !response.data) {
						return;
					}
					var d = response.data;
					showProgress(true, cfg.i18n.processing, d.percent || 0);
					$progress.find('.boulk-progress-text').text(
						d.processed + ' / ' + d.total + ' (' + (d.percent || 0) + '%)'
					);

					if (d.finished) {
						showProgress(true, cfg.i18n.complete, 100);
						if (onComplete) {
							onComplete(d);
						}
						setTimeout(function () {
							showProgress(false);
						}, 2000);
					} else {
						setTimeout(tick, cfg.pollInterval || 2000);
					}
				});
		}
		tick();
	}

	function showProgress(show, label, percent) {
		if (!show) {
			$progress.hide();
			return;
		}
		$progress.show();
		$progress.find('.boulk-progress-fill').css('width', (percent || 0) + '%');
		$progress.find('.boulk-progress-text').text(label || '');
	}

	function finishAction(message) {
		showProgress(false);
		state.selected = {};
		updateSelectionCount();
		updateDupSelectionCount();
		window.alert(message);
		reloadCurrentView();
	}

	function escHtml(str) {
		return $('<div>').text(str == null ? '' : String(str)).html();
	}

	function escAttr(str) {
		return escHtml(str).replace(/"/g, '&quot;');
	}

	$(init);
})(jQuery);
