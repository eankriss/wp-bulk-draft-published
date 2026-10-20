(function () {
    'use strict';

    function byId(id) {
        return document.getElementById(id);
    }

    function initManualSelection() {
        var form = byId('wbdp-bulk-form');
        if (!form) return;

        var selectAll = byId('wbdp-select-all');
        var checkboxes = Array.prototype.slice.call(form.querySelectorAll('.wbdp-post-checkbox'));
        var submitButton = byId('wbdp-draft-selected');
        var selectedCount = byId('wbdp-selected-count');

        function refreshState() {
            var checked = checkboxes.filter(function (checkbox) { return checkbox.checked; }).length;

            if (submitButton) submitButton.disabled = checked === 0;
            if (selectedCount) selectedCount.textContent = checked + ' selected';

            if (selectAll) {
                selectAll.checked = checkboxes.length > 0 && checked === checkboxes.length;
                selectAll.indeterminate = checked > 0 && checked < checkboxes.length;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checkboxes.forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
                refreshState();
            });
        }

        checkboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', refreshState);
        });

        form.addEventListener('submit', function (event) {
            var checked = checkboxes.filter(function (checkbox) { return checkbox.checked; }).length;
            if (!checked) {
                event.preventDefault();
                return;
            }

            if (!window.confirm('Move ' + checked + ' selected published item' + (checked === 1 ? '' : 's') + ' to Draft?')) {
                event.preventDefault();
            }
        });

        refreshState();
    }

    function initFileImport() {
        var previewButton = byId('wbdp-preview-import');
        var fileInput = byId('wbdp-import-file');

        if (!previewButton || !fileInput || typeof WBDP_IMPORT === 'undefined') {
            return;
        }

        var postType = byId('wbdp-import-post-type');
        var matchMethod = byId('wbdp-import-match');
        var statusFilter = byId('wbdp-import-status-filter');
        var loading = byId('wbdp-import-loading');
        var results = byId('wbdp-import-results');
        var body = byId('wbdp-import-body');
        var selectReady = byId('wbdp-import-select-ready');
        var clearSelection = byId('wbdp-import-clear');
        var checkAll = byId('wbdp-import-check-all');
        var submit = byId('wbdp-import-draft-selected');
        var selectedCount = byId('wbdp-import-selected-count');
        var search = byId('wbdp-import-search');
        var form = byId('wbdp-import-draft-form');
        var formPostType = byId('wbdp-import-form-post-type');

        var rows = [];

        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function resultLabel(row) {
            if (row.result === 'ready') return '<span class="wbdp-status wbdp-status-ready">READY</span>';
            if (row.result === 'not_published') return '<span class="wbdp-status wbdp-status-skip">NOT PUBLISHED</span>';
            if (row.result === 'wrong_cpt') return '<span class="wbdp-status wbdp-status-error">WRONG CPT</span>';
            if (row.result === 'ambiguous') return '<span class="wbdp-status wbdp-status-error">AMBIGUOUS</span>';
            return '<span class="wbdp-status wbdp-status-error">NOT FOUND</span>';
        }

        function visibleRows() {
            var query = (search.value || '').trim().toLowerCase();
            if (!query) return rows;

            return rows.filter(function (row) {
                return [
                    row.file_id,
                    row.file_title,
                    row.file_slug,
                    row.file_cpt,
                    row.post_id,
                    row.post_title,
                    row.post_slug,
                    row.post_status,
                    row.match_method,
                    row.result
                ].join(' ').toLowerCase().indexOf(query) !== -1;
            });
        }

        function refreshSelectionState() {
            var checks = Array.prototype.slice.call(body.querySelectorAll('.wbdp-import-checkbox'));
            var checked = checks.filter(function (cb) { return cb.checked; }).length;
            var ready = checks.filter(function (cb) { return !cb.disabled; });
            var readyChecked = ready.filter(function (cb) { return cb.checked; }).length;

            submit.disabled = checked === 0;
            selectedCount.textContent = checked + ' selected';

            checkAll.checked = ready.length > 0 && readyChecked === ready.length;
            checkAll.indeterminate = readyChecked > 0 && readyChecked < ready.length;
        }

        function renderRows() {
            var data = visibleRows();
            var html = '';

            data.forEach(function (row) {
                var wpItem = row.post_id
                    ? '<strong>#' + escapeHtml(row.post_id) + ' ' + escapeHtml(row.post_title || '(no title)') + '</strong>' +
                      (row.edit_link ? '<br><a href="' + escapeHtml(row.edit_link) + '" target="_blank" rel="noopener">Edit ↗</a>' : '')
                    : '—';

                html += '<tr data-search="' + escapeHtml([
                    row.file_id, row.file_title, row.file_slug, row.post_title, row.post_slug
                ].join(' ').toLowerCase()) + '">';
                html += '<th scope="row" class="check-column">';
                html += row.actionable
                    ? '<input class="wbdp-import-checkbox" type="checkbox" name="post_ids[]" value="' + escapeHtml(row.post_id) + '" checked>'
                    : '<input type="checkbox" disabled>';
                html += '</th>';
                html += '<td>' + resultLabel(row) + '</td>';
                html += '<td>' + escapeHtml(row.row) + '</td>';
                html += '<td>' + escapeHtml(row.file_id || '—') + '</td>';
                html += '<td><strong>' + escapeHtml(row.file_title || '—') + '</strong></td>';
                html += '<td>' + escapeHtml(row.file_slug || '—') + '</td>';
                html += '<td>' + wpItem + '</td>';
                html += '<td>' + escapeHtml(row.post_status || '—') + '</td>';
                html += '<td>' + escapeHtml(row.match_method || '—') + '</td>';
                html += '</tr>';
            });

            body.innerHTML = html || '<tr><td colspan="9">No preview rows match the current search.</td></tr>';

            Array.prototype.slice.call(body.querySelectorAll('.wbdp-import-checkbox')).forEach(function (cb) {
                cb.addEventListener('change', refreshSelectionState);
            });

            refreshSelectionState();
        }

        function setLoading(isLoading) {
            loading.hidden = !isLoading;
            previewButton.disabled = isLoading;
        }

        previewButton.addEventListener('click', function () {
            var file = fileInput.files && fileInput.files[0];

            if (!file) {
                window.alert('Choose a CSV or XLSX file first.');
                return;
            }

            var data = new FormData();
            data.append('action', 'wbdp_import_preview');
            data.append('nonce', WBDP_IMPORT.nonce);
            data.append('post_type', postType.value);
            data.append('match_method', matchMethod.value);
            data.append('status_filter', statusFilter.value);
            data.append('file', file);

            setLoading(true);
            results.hidden = true;

            fetch(WBDP_IMPORT.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (json) {
                if (!json.success) {
                    throw new Error(json.data && json.data.message ? json.data.message : 'Import preview failed.');
                }

                rows = json.data.rows || [];

                byId('wbdp-stat-file').textContent = json.data.stats.file_rows || 0;
                byId('wbdp-stat-ready').textContent = json.data.stats.ready || 0;
                byId('wbdp-stat-skipped').textContent = json.data.stats.skipped || 0;
                byId('wbdp-stat-missing').textContent = json.data.stats.missing || 0;

                formPostType.value = postType.value;
                search.value = '';
                results.hidden = false;
                renderRows();
            })
            .catch(function (error) {
                window.alert(error.message || String(error));
            })
            .finally(function () {
                setLoading(false);
            });
        });

        selectReady.addEventListener('click', function () {
            Array.prototype.slice.call(body.querySelectorAll('.wbdp-import-checkbox')).forEach(function (cb) {
                if (!cb.disabled) cb.checked = true;
            });
            refreshSelectionState();
        });

        clearSelection.addEventListener('click', function () {
            Array.prototype.slice.call(body.querySelectorAll('.wbdp-import-checkbox')).forEach(function (cb) {
                cb.checked = false;
            });
            refreshSelectionState();
        });

        checkAll.addEventListener('change', function () {
            Array.prototype.slice.call(body.querySelectorAll('.wbdp-import-checkbox')).forEach(function (cb) {
                if (!cb.disabled) cb.checked = checkAll.checked;
            });
            refreshSelectionState();
        });

        search.addEventListener('input', renderRows);

        form.addEventListener('submit', function (event) {
            var checked = body.querySelectorAll('.wbdp-import-checkbox:checked').length;

            if (!checked) {
                event.preventDefault();
                return;
            }

            if (!window.confirm('Move ' + checked + ' matched published item' + (checked === 1 ? '' : 's') + ' to Draft?')) {
                event.preventDefault();
            }
        });
    }

    function init() {
        initManualSelection();
        initFileImport();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
