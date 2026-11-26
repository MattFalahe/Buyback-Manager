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

    // Contract row click handler. A button or menu inside the row does its
    // own thing; Bootstrap's dropdowns listen on the document, so they
    // cannot simply stop the click from reaching the row.
    $(document).on('click', '.contract-row', function(e) {
        if ($(e.target).closest('a, button, .dropdown-menu, .bb-oig').length) {
            return;
        }
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

    // Open a contract in the EVE client of one of the user's characters.
    // EVE answers the same whether or not the client is running, so the
    // reply only says the request went out.
    $(document).on('click', '.bb-open-in-game', function(e) {
        e.preventDefault();
        const $el = $(this);
        const $wrap = $el.closest('.bb-oig');
        const $result = $wrap.find('.bb-oig-result');
        $result.removeClass('text-success text-danger').text('Sending...');

        $.ajax({
            url: $el.data('url'),
            method: 'POST',
            dataType: 'json',
            data: {
                _token: $wrap.data('token') || $('meta[name=csrf-token]').attr('content'),
                character_id: $el.data('character')
            }
        }).done(function(res) {
            $result.addClass('text-success').text(res.message || 'Sent.');
        }).fail(function(xhr) {
            const res = xhr.responseJSON || {};
            $result.addClass('text-danger').text(res.message || ('The request did not go through (HTTP ' + xhr.status + ').'));
        });
    });

    // Filter form
    $(document).on('change', '#corporation-filter', function() {
        const form = $(this).closest('form');
        form.submit();
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
