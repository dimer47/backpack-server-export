@if ($crud->hasAccess('serverExport'))
@push('after_styles')
<style>
#server-export-group {
    margin-right: 0.25rem;
}
.server-export-dropdown {
    min-width: auto;
    padding: 0.25rem 0;
}
.server-export-dropdown .dropdown-item {
    padding: 0.35rem 1rem;
    font-size: 0.875rem;
    white-space: nowrap;
}
</style>
@endpush
@push('after_scripts')
<script>
(function() {
    'use strict';

    var serverExportFormats = @json($crud->get('serverExport.formats') ?? ['xlsx', 'csv']);
    var serverExportRoute = "{{ url($crud->get('serverExport.route')) }}/server-export";
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}';

    var formatIcons = {
        'xlsx': 'la-file-excel',
        'csv': 'la-file-csv',
        'md': 'la-file-alt'
    };

    var formatLabels = {
        'xlsx': '{{ trans("backpack-server-export::server-export.format_xlsx") }}',
        'csv': '{{ trans("backpack-server-export::server-export.format_csv") }}',
        'md': '{{ trans("backpack-server-export::server-export.format_md") }}'
    };

    function injectServerExportButtons() {
        // Find the DataTables buttons container
        var dtButtonsContainer = document.querySelector('.dt-buttons');
        if (!dtButtonsContainer) {
            // Retry after a short delay (DataTables might not be initialized yet)
            setTimeout(injectServerExportButtons, 500);
            return;
        }

        // Check if already injected
        if (document.getElementById('server-export-group')) {
            return;
        }

        // Create dropdown button group (visually identical to DT but isolated from DT JS)
        var group = document.createElement('div');
        group.id = 'server-export-group';
        group.className = 'btn-group';
        group.innerHTML = '<button type="button" class="btn btn-sm buttons-collection dropdown-toggle" ' +
            'data-bs-toggle="dropdown" aria-expanded="false" id="server-export-btn">' +
            '<span><i class="la la-download"></i> ' +
            '{{ trans("backpack-server-export::server-export.button_label") }}</span>' +
            '</button>' +
            '<div class="dropdown-menu server-export-dropdown" id="server-export-menu"></div>';

        // Insert before the ColVis button (last btn-group) to get order:
        // "Exporter la vue actuelle" | "Export complet" | "Visibilité colonnes"
        var colVisBtn = dtButtonsContainer.querySelector('.buttons-colvis, .buttons-columnVisibility');
        if (colVisBtn) {
            // ColVis is wrapped in a btn-group div
            var colVisGroup = colVisBtn.closest('.btn-group') || colVisBtn.parentElement;
            dtButtonsContainer.insertBefore(group, colVisGroup);
        } else {
            dtButtonsContainer.appendChild(group);
        }

        // Populate dropdown menu
        var menu = document.getElementById('server-export-menu');
        serverExportFormats.forEach(function(fmt) {
            var icon = formatIcons[fmt] || 'la-file';
            var label = formatLabels[fmt] || fmt.toUpperCase();
            var a = document.createElement('a');
            a.className = 'dropdown-item server-export-item';
            a.href = '#';
            a.setAttribute('data-format', fmt);
            a.innerHTML = '<span><i class="la ' + icon + ' me-1"></i>' + label + '</span>';
            menu.appendChild(a);
        });

        // Bind click events
        document.querySelectorAll('.server-export-item').forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                triggerServerExport(this.getAttribute('data-format'));
            });
        });
    }

    function triggerServerExport(format) {
        var btn = document.getElementById('server-export-btn');
        var originalHtml = btn.innerHTML;

        // Disable button
        btn.disabled = true;
        btn.innerHTML = '<i class="la la-spinner la-spin"></i> {{ trans("backpack-server-export::server-export.generating") }}';

        // Get DataTable instance
        var table = null;
        if (window.crud && window.crud.table) {
            table = window.crud.table;
        } else if (typeof $.fn.dataTable !== 'undefined') {
            var tables = $.fn.dataTable.tables({api: true});
            if (tables.count()) {
                table = tables.table(0);
            }
        }

        // Collect filters from the DataTable AJAX URL (most reliable source)
        // This URL contains all active Backpack filters as query params
        var filters = {};
        var filterSource = '';

        if (table) {
            var ajaxUrl = table.ajax.url();
            if (ajaxUrl) {
                filterSource = ajaxUrl;
            }
        }

        // Fallback to page URL if no AJAX URL
        if (!filterSource) {
            filterSource = window.location.href;
        }

        // Parse the URL preserving array params (e.g. state[]=draft&state[]=printed)
        if (filterSource) {
            try {
                var urlObj = new URL(filterSource, window.location.origin);
                urlObj.searchParams.forEach(function(value, key) {
                    // Handle array params (key ends with [] or same key appears multiple times)
                    if (key in filters) {
                        // Already exists - convert to array
                        if (!Array.isArray(filters[key])) {
                            filters[key] = [filters[key]];
                        }
                        filters[key].push(value);
                    } else {
                        filters[key] = value;
                    }
                });
            } catch(e) {}
        }

        var search = '';
        var order = [];
        var visibleColumns = [];

        if (table) {
            // Get search term
            search = table.search() || '';

            // Get order (array of [colIndex, direction])
            var dtOrder = table.order();
            if (dtOrder && dtOrder.length) {
                dtOrder.forEach(function(o) {
                    order.push({column: o[0], dir: o[1]});
                });
            }

            // Get visible columns (respecting ColVis state)
            table.columns().every(function(idx) {
                var header = this.header();
                var $th = $(header);
                var isVisible = this.visible();
                var isExportable = $th.attr('data-visible-in-export') !== 'false';
                var isForceExport = $th.attr('data-force-export') === 'true';
                var columnName = $th.attr('data-column-name') || '';

                if (columnName && ((isVisible && isExportable) || (isForceExport && isExportable))) {
                    visibleColumns.push(columnName);
                }
            });
        }

        // POST request
        $.ajax({
            url: serverExportRoute + '?' + $.param(filters),
            type: 'POST',
            headers: {'X-CSRF-TOKEN': csrfToken},
            data: JSON.stringify({format: format, search: search, order: order, visible_columns: visibleColumns}),
            contentType: 'application/json',
            success: function(result) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;

                if (result.mode === 'sync') {
                    // Direct download
                    window.location.href = result.download_url;
                    if (typeof Noty !== 'undefined') {
                        new Noty({type: 'success', text: result.message || '{{ trans("backpack-server-export::server-export.export_ready_generic") }}'}).show();
                    }
                } else {
                    // Async: notify user
                    if (typeof Noty !== 'undefined') {
                        new Noty({type: 'info', text: result.message || '{{ trans("backpack-server-export::server-export.export_started") }}'}).show();
                    }

                    // Trigger background tasks refresh if available
                    if (typeof fetchBackgroundTasks === 'function') {
                        fetchBackgroundTasks();
                    }
                }
            },
            error: function(xhr) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;

                var msg = '{{ trans("backpack-server-export::server-export.error") }}';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                if (typeof Noty !== 'undefined') {
                    new Noty({type: 'error', text: msg}).show();
                }
            }
        });
    }

    // Wait for DOM ready and DataTables init
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(injectServerExportButtons, 300);
        });
    } else {
        setTimeout(injectServerExportButtons, 300);
    }
})();
</script>
@endpush
@endif
