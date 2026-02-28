/**
 * SolwedPlugins - Portal Solwed JavaScript
 * Plugin search, filter, and action handlers for the Portal Solwed tab
 */

// Global filter state
var currentStatusFilter = 'all';
var currentSourceFilter = 'all';

$(document).ready(function() {
    initializeSearchFunctionality();
    initializeFilterFunctionality();
    initializeSourceFilterFunctionality();
    initializeActionHandlers();
});

/**
 * Initialize plugin search functionality
 */
function initializeSearchFunctionality() {
    $('#pluginSearch').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        $('.plugin-item').filter(function() {
            var pluginName = $(this).data('name');
            $(this).toggle(pluginName.indexOf(value) > -1);
        });
    });
}

/**
 * Apply combined filters (status + source)
 */
function applyFilters() {
    $('.plugin-item').each(function() {
        var $item = $(this);
        var status = $item.data('status');
        var source = $item.data('source');

        var statusMatch = (currentStatusFilter === 'all') || (status === currentStatusFilter);
        var sourceMatch = (currentSourceFilter === 'all') || (source === currentSourceFilter);

        $item.toggle(statusMatch && sourceMatch);
    });
}

/**
 * Initialize status filter button functionality
 */
function initializeFilterFunctionality() {
    $('.filter-buttons button').on('click', function() {
        currentStatusFilter = $(this).data('filter');

        // Update active button
        $('.filter-buttons button').removeClass('active');
        $(this).addClass('active');

        // Apply combined filters
        applyFilters();
    });
}

/**
 * Initialize source filter button functionality
 */
function initializeSourceFilterFunctionality() {
    $('.source-filter-buttons button').on('click', function() {
        currentSourceFilter = $(this).data('source-filter');

        // Update active button
        $('.source-filter-buttons button').removeClass('active');
        $(this).addClass('active');

        // Apply combined filters
        applyFilters();
    });
}

/**
 * Initialize action handlers for install/update buttons with confirmation dialogs
 */
function initializeActionHandlers() {
    $('#pluginContainer').on('click', 'a[data-confirm-message]', function(e) {
        e.preventDefault();
        const $button = $(this);
        const url = $button.attr('href');
        const message = $button.data('confirm-message');

        // Use native confirm dialog
        if (confirm(message)) {
            // Disable button to prevent double-clicks
            $button.prop('disabled', true);

            // Store original button HTML
            const originalHtml = $button.html();

            // Show loading state in button
            $button.html('<i class="fas fa-spinner fa-spin"></i> ' + getLoadingText($button));

            // Show spinner animation if available
            if (typeof animateSpinner === 'function') {
                animateSpinner('add');
            }

            // Redirect to the action URL
            window.location.href = url;
        }
    });
}

/**
 * Get appropriate loading text based on button action
 */
function getLoadingText($button) {
    if ($button.hasClass('btn-warning') || $button.text().toLowerCase().includes('actualizar')) {
        return 'Actualizando...';
    } else if ($button.hasClass('btn-primary') || $button.hasClass('btn-danger')) {
        return 'Instalando...';
    }
    return 'Procesando...';
}
