(function () {
    'use strict';

    function init() {
        var filterForm = document.getElementById('wbdp-filter-form');
        var bulkForm = document.getElementById('wbdp-bulk-form');
        if (!filterForm || !bulkForm || typeof WBDP_DATA === 'undefined') return;

        var searchInput = document.getElementById('wbdp-search');
        var postTypeSelect = document.getElementById('wbdp-post-type');
        var perPageSelect = document.getElementById('wbdp-per-page');
        var spinner = document.getElementById('wbdp-search-spinner');
        var results = document.getElementById('wbdp-results');
        var tbody = document.getElementById('wbdp-results-body');
        var pagination = document.getElementById('wbdp-pagination');
        var total = document.getElementById('wbdp-total');
        var submitButton = document.getElementById('wbdp-draft-selected');
        var clearButton = document.getElementById('wbdp-clear-selected');
        var selectedCount = document.getElementById('wbdp-selected-count');
        var persistentSelection = document.getElementById('wbdp-persistent-selection');
        var bulkPostType = document.getElementById('wbdp-bulk-post-type');
        var bulkSearch = document.getElementById('wbdp-bulk-search');
        var bulkPerPage = document.getElementById('wbdp-bulk-per-page');
        var bulkPaged = document.getElementById('wbdp-bulk-paged');

        var selectedIds = new Set();
        var debounceTimer = null;
        var requestController = null;
        var currentPage = parseInt(bulkPaged.value, 10) || 1;

        function getVisibleCheckboxes() {
            return Array.prototype.slice.call(tbody.querySelectorAll('.wbdp-post-checkbox'));
        }

        function getSelectAll() {
            return document.getElementById('wbdp-select-all');
        }

        function syncHiddenSelection() {
            persistentSelection.innerHTML = '';
            selectedIds.forEach(function (id) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'post_ids[]';
                input.value = id;
                persistentSelection.appendChild(input);
            });
        }

        function refreshState() {
            var checkboxes = getVisibleCheckboxes();
            var visibleSelected = 0;

            checkboxes.forEach(function (checkbox) {
                var id = String(checkbox.value);
                checkbox.checked = selectedIds.has(id);
                if (checkbox.checked) visibleSelected++;
            });

            var count = selectedIds.size;
            submitButton.disabled = count === 0;
            clearButton.disabled = count === 0;
            selectedCount.textContent = count + ' ' + WBDP_DATA.strings.selected;

            var selectAll = getSelectAll();
            if (selectAll) {
                selectAll.checked = checkboxes.length > 0 && visibleSelected === checkboxes.length;
                selectAll.indeterminate = visibleSelected > 0 && visibleSelected < checkboxes.length;
            }

            syncHiddenSelection();
        }

        function bindResultCheckboxes() {
            getVisibleCheckboxes().forEach(function (checkbox) {
                checkbox.addEventListener('change', function () {
                    var id = String(checkbox.value);
                    if (checkbox.checked) {
                        selectedIds.add(id);
                    } else {
                        selectedIds.delete(id);
                    }
                    refreshState();
                });
            });

            var selectAll = getSelectAll();
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    getVisibleCheckboxes().forEach(function (checkbox) {
                        var id = String(checkbox.value);
                        if (selectAll.checked) {
                            selectedIds.add(id);
                        } else {
                            selectedIds.delete(id);
                        }
                    });
                    refreshState();
                });
            }

            refreshState();
        }

        function setLoading(isLoading) {
            if (spinner) spinner.classList.toggle('is-active', isLoading);
            if (results) {
                results.setAttribute('aria-busy', isLoading ? 'true' : 'false');
                results.classList.toggle('is-loading', isLoading);
            }
        }

        function syncBulkFilters(page) {
            bulkPostType.value = postTypeSelect.value;
            bulkSearch.value = searchInput.value;
            bulkPerPage.value = perPageSelect.value;
            bulkPaged.value = page || 1;
        }

        function buildRequest(page) {
            var params = new URLSearchParams();
            params.append('action', 'wbdp_live_search');
            params.append('nonce', WBDP_DATA.nonce);
            params.append('post_type_filter', postTypeSelect.value);
            params.append('s', searchInput.value);
            params.append('per_page', perPageSelect.value);
            params.append('paged', String(page || 1));
            return params;
        }

        function liveSearch(page) {
            currentPage = page || 1;
            syncBulkFilters(currentPage);

            if (requestController && typeof requestController.abort === 'function') {
                requestController.abort();
            }
            requestController = typeof AbortController !== 'undefined' ? new AbortController() : null;

            setLoading(true);

            var options = {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: buildRequest(currentPage).toString()
            };
            if (requestController) options.signal = requestController.signal;

            fetch(WBDP_DATA.ajaxUrl, options)
                .then(function (response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(function (response) {
                    if (!response.success || !response.data) throw new Error('Invalid response');

                    tbody.innerHTML = response.data.rows;
                    total.textContent = response.data.total;
                    pagination.innerHTML = response.data.pagination || '';
                    currentPage = parseInt(response.data.paged, 10) || 1;
                    syncBulkFilters(currentPage);
                    bindResultCheckboxes();
                })
                .catch(function (error) {
                    if (error && error.name === 'AbortError') return;
                    total.textContent = WBDP_DATA.strings.error;
                })
                .finally(function () {
                    setLoading(false);
                });
        }

        function scheduleSearch() {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(function () {
                liveSearch(1);
            }, 250);
        }

        searchInput.addEventListener('input', scheduleSearch);

        postTypeSelect.addEventListener('change', function () {
            // Selection is intentionally reset when switching post types to avoid hidden cross-type selections.
            selectedIds.clear();
            refreshState();
            liveSearch(1);
        });

        perPageSelect.addEventListener('change', function () {
            liveSearch(1);
        });

        filterForm.addEventListener('submit', function (event) {
            event.preventDefault();
            liveSearch(1);
        });

        pagination.addEventListener('click', function (event) {
            var link = event.target.closest('a.page-numbers');
            if (!link) return;
            event.preventDefault();

            var url;
            try {
                url = new URL(link.href, window.location.href);
            } catch (e) {
                return;
            }

            var page = parseInt(url.searchParams.get('paged'), 10);
            if (!page) {
                var text = link.textContent.replace(/\D/g, '');
                page = parseInt(text, 10) || currentPage;
                if (link.classList.contains('next')) page = currentPage + 1;
                if (link.classList.contains('prev')) page = Math.max(1, currentPage - 1);
            }

            liveSearch(page);
        });

        clearButton.addEventListener('click', function () {
            selectedIds.clear();
            refreshState();
        });

        bulkForm.addEventListener('submit', function (event) {
            var count = selectedIds.size;
            if (!count) {
                event.preventDefault();
                return;
            }

            syncHiddenSelection();

            // Visible checkboxes use the same name; disable them so IDs are submitted only once.
            getVisibleCheckboxes().forEach(function (checkbox) {
                checkbox.disabled = true;
            });

            var message = count === 1
                ? WBDP_DATA.strings.confirmSingular
                : WBDP_DATA.strings.confirmPlural.replace('%d', count);

            if (!window.confirm(message)) {
                event.preventDefault();
                getVisibleCheckboxes().forEach(function (checkbox) {
                    checkbox.disabled = false;
                });
            }
        });

        bindResultCheckboxes();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
