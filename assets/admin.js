(function ($) {
	'use strict';

	function pollJob(jobId, $container) {
		if (!$container.length) {
			return;
		}

		$.post(boulkUpAdmin.ajaxUrl, {
			action: 'boulk_up_job_status',
			nonce: boulkUpAdmin.nonce,
			job_id: jobId
		})
			.done(function (response) {
				if (!response.success || !response.data) {
					return;
				}

				var d = response.data;
				updateProgress($container, d);

				// Update history table row if present.
				var $row = $('tr[data-job-id="' + jobId + '"]');
				if ($row.length) {
					$row.find('.boulk-status').attr('class', 'boulk-status boulk-status-' + d.status).text(d.statusLabel);
					$row.find('.boulk-progress-fill').css('width', d.percent + '%');
					$row.find('.boulk-progress-text').text(
						d.progressText || (d.processed + ' / ' + d.total + ' rows')
					);
					$row.find('.boulk-updated-cell').text(d.updated);
					$row.find('.boulk-created-cell').text(d.created);
					$row.find('.boulk-skipped-cell').text(d.skipped);
					$row.find('.boulk-errors-cell').text(d.errors);
					$row.attr('data-status', d.status);
				}

				if (!d.finished) {
					setTimeout(function () {
						pollJob(jobId, $container);
					}, boulkUpAdmin.pollInterval);
				} else {
					$container.attr('data-finished', '1');
				}
			});
	}

	function renderIssueRows($tbody, entries) {
		if (!$tbody.length) {
			return;
		}
		$tbody.empty();
		if (!entries || !entries.length) {
			return;
		}
		entries.forEach(function (entry) {
			$tbody.append(
				'<tr><td>' + escHtml(String(entry.row)) + '</td>' +
				'<td><code>' + escHtml(entry.sku) + '</code></td>' +
				'<td>' + escHtml(entry.message) + '</td></tr>'
			);
		});
	}

	function updateProgress($container, d) {
		$container.find('.boulk-progress-fill').css('width', d.percent + '%');
		$container.find('.boulk-status').attr('class', 'boulk-status boulk-status-' + d.status).text(d.statusLabel);
		$container.find('.boulk-progress-text').text(
			d.progressText || (d.processed + ' / ' + d.total + ' rows (' + d.percent + '%)')
		);

		var $runProgress = $container.find('.boulk-run-progress');
		if (d.runTotal > 0) {
			if (!$runProgress.length) {
				$container.find('.boulk-progress-summary').after(
					'<p class="description boulk-run-progress"></p>'
				);
				$runProgress = $container.find('.boulk-run-progress');
			}
			$runProgress.text(
				'Auto-queue: run ' + d.runCurrent + ' of ' + d.runTotal +
				' (' + d.rowsPerRun.toLocaleString() + ' products per background run).'
			).show();
		} else if ($runProgress.length) {
			$runProgress.hide();
		}

		$container.find('.boulk-stat-updated').text(d.updated);
		if ($container.find('.boulk-stat-created').length) {
			$container.find('.boulk-stat-created').text(d.created);
		}
		$container.find('.boulk-stat-skipped').text(d.skipped);
		$container.find('.boulk-stat-errors').text(d.errors);

		renderIssueRows($container.find('.boulk-error-entries'), d.errorEntries);
		renderIssueRows($container.find('.boulk-skipped-entries'), d.skippedEntries);
	}

	function escHtml(str) {
		return $('<div>').text(str).html();
	}

	function initPolling() {
		var $detail = $('#boulk-job-progress');
		if ($detail.length && $detail.attr('data-finished') !== '1') {
			pollJob($detail.attr('data-job-id'), $detail);
		}

		// Poll running jobs in history table.
		$('tr[data-status="running"], tr[data-status="queued"]').each(function () {
			var jobId = $(this).attr('data-job-id');
			var $row = $(this);
			if (!jobId) {
				return;
			}

			function pollRow() {
				$.post(boulkUpAdmin.ajaxUrl, {
					action: 'boulk_up_job_status',
					nonce: boulkUpAdmin.nonce,
					job_id: jobId
				}).done(function (response) {
					if (!response.success) {
						return;
					}
					var d = response.data;
					$row.find('.boulk-status').attr('class', 'boulk-status boulk-status-' + d.status).text(d.statusLabel);
					$row.find('.boulk-progress-fill').css('width', d.percent + '%');
					$row.find('.boulk-progress-text').text(
						d.progressText || (d.processed + ' / ' + d.total + ' rows')
					);
					$row.find('.boulk-updated-cell').text(d.updated);
					$row.find('.boulk-created-cell').text(d.created);
					$row.find('.boulk-skipped-cell').text(d.skipped);
					$row.find('.boulk-errors-cell').text(d.errors);
					$row.attr('data-status', d.status);

					if (!d.finished) {
						setTimeout(pollRow, boulkUpAdmin.pollInterval);
					}
				});
			}

			pollRow();
		});
	}

	function initProfileDescriptions() {
		var $select = $('#boulk_import_profile');
		var $desc = $('.boulk-profile-desc');
		if (!$select.length || !$desc.length) {
			return;
		}

		var descriptions = $desc.data('profiles');
		if (!descriptions) {
			return;
		}

		$select.on('change', function () {
			var key = $(this).val();
			if (descriptions[key]) {
				$desc.text(descriptions[key]);
			}
		});
	}

	function initFieldSelection() {
		var $groups = $('.boulk-field-groups');
		var $checkboxes = $('.boulk-field-cb');
		if (!$checkboxes.length) {
			return;
		}

		var presets = $groups.data('presets');
		if (typeof presets === 'string') {
			try {
				presets = JSON.parse(presets);
			} catch (e) {
				presets = {};
			}
		}

		function setFields(fieldList) {
			$checkboxes.prop('checked', false);
			if (!fieldList || !fieldList.length) {
				return;
			}
			fieldList.forEach(function (field) {
				$checkboxes.filter('[value="' + field + '"]').prop('checked', true);
			});
		}

		$('.boulk-select-all').on('click', function (e) {
			e.preventDefault();
			$checkboxes.prop('checked', true);
		});

		$('.boulk-select-none').on('click', function (e) {
			e.preventDefault();
			$checkboxes.prop('checked', false);
		});

		$('.boulk-preset-btn').on('click', function (e) {
			e.preventDefault();
			var key = $(this).data('preset');
			if (presets && presets[key]) {
				setFields(presets[key]);
			}
		});

		$('.boulk-up-import-form').on('submit', function () {
			if ($checkboxes.filter(':checked').length === 0) {
				alert('Please select at least one field to update.');
				return false;
			}
		});
	}

	$(document).ready(function () {
		initPolling();
		initProfileDescriptions();
		initFieldSelection();
	});
})(jQuery);
