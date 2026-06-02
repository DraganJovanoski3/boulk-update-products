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
					$row.find('.boulk-progress-text').text(d.processed + ' / ' + d.total + ' rows');
					$row.find('.boulk-updated-cell').text(d.updated);
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

	function updateProgress($container, d) {
		$container.find('.boulk-progress-fill').css('width', d.percent + '%');
		$container.find('.boulk-status').attr('class', 'boulk-status boulk-status-' + d.status).text(d.statusLabel);
		$container.find('.boulk-progress-text').text(
			d.processed + ' / ' + d.total + ' rows (' + d.percent + '%)'
		);
		$container.find('.boulk-stat-updated').text(d.updated);
		$container.find('.boulk-stat-skipped').text(d.skipped);
		$container.find('.boulk-stat-errors').text(d.errors);

		if (d.logEntries && d.logEntries.length) {
			var $tbody = $container.find('.boulk-log-entries');
			if ($tbody.length) {
				$tbody.empty();
				d.logEntries.forEach(function (entry) {
					$tbody.append(
						'<tr><td>' + escHtml(String(entry.row)) + '</td>' +
						'<td>' + escHtml(entry.sku) + '</td>' +
						'<td>' + escHtml(entry.status) + '</td>' +
						'<td>' + escHtml(entry.message) + '</td></tr>'
					);
				});
			}
		}
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
					$row.find('.boulk-progress-text').text(d.processed + ' / ' + d.total + ' rows');
					$row.find('.boulk-updated-cell').text(d.updated);
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

	$(document).ready(function () {
		initPolling();
		initProfileDescriptions();
	});
})(jQuery);
