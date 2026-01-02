(function($) {
    'use strict';

    // Initialize DataTables
    function initDataTables() {
        if ($.fn.DataTable) {
            $('.buyback-datatable').DataTable({
                pageLength: 25,
                order: [[0, 'desc']],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    paginate: {
                        first: "First",
                        last: "Last",
                        next: "Next",
                        previous: "Previous"
                    }
                }
            });
        }
    }

    // Format ISK values
    function formatISK(value) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(value) + ' ISK';
    }

    // Format numbers
    function formatNumber(value) {
        return new Intl.NumberFormat('en-US').format(value);
    }

    // Copy to clipboard
    function copyToClipboard(text) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    }

    // Contract row click handler
    $(document).on('click', '.contract-row', function() {
        const contractId = $(this).data('contract-id');
        if (contractId) {
            window.location.href = `/buyback-manager/contracts/${contractId}`;
        }
    });

    // Copy contract value button
    $(document).on('click', '.copy-contract-value', function(e) {
        e.stopPropagation();
        const value = $(this).data('value');
        copyToClipboard(value);
        
        // Show feedback
        const btn = $(this);
        const originalHtml = btn.html();
        btn.html('<i class="fa fa-check"></i> Copied!');
        setTimeout(() => {
            btn.html(originalHtml);
        }, 2000);
    });

    // Filter form
    $(document).on('change', '#corporation-filter', function() {
        const form = $(this).closest('form');
        form.submit();
    });

    // Export functionality
    $(document).on('click', '.export-contracts', function() {
        // Implement export logic
        alert('Export functionality coming soon!');
    });

    // Initialize tooltips
    function initTooltips() {
        if ($.fn.tooltip) {
            $('[data-toggle="tooltip"]').tooltip();
        }
    }

    // Initialize on document ready
    $(document).ready(function() {
        initDataTables();
        initTooltips();
    });

    // Export functions for external use
    window.BuybackManager = {
        formatISK: formatISK,
        formatNumber: formatNumber,
        copyToClipboard: copyToClipboard
    };

})(jQuery);
