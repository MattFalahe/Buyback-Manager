(function($) {
    'use strict';

    let currentAppraisal = null;

    // Appraise button handler
    $(document).on('click', '#appraise-btn', function() {
        const corporationId = $('#corporation-select').val();
        const items = $('#items-input').val().trim();

        if (!corporationId) {
            showError('Please select a corporation');
            return;
        }

        if (!items) {
            showError('Please enter items to appraise');
            return;
        }

        performAppraisal(corporationId, items);
    });

    // Clear button handler
    $(document).on('click', '#clear-btn', function() {
        $('#items-input').val('');
        hideResults();
        hideError();
    });

    // Perform appraisal
    function performAppraisal(corporationId, items) {
        showLoading();
        hideError();
        hideResults();

        $.ajax({
            url: '/buyback-manager/appraisal/appraise',
            method: 'POST',
            data: {
                corporation_id: corporationId,
                items: items,
                _token: $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    currentAppraisal = response;
                    displayResults(response);
                } else {
                    showError(response.message || 'Appraisal failed');
                }
            },
            error: function(xhr) {
                hideLoading();
                showError('Network error occurred. Please try again.');
            }
        });
    }

    // Display results
    function displayResults(appraisal) {
        const resultsDiv = $('#appraisal-results');
        
        // Update summary
        $('#total-value').text(formatISK(appraisal.total_value));
        $('#market-value').text(formatISK(appraisal.total_market_value));
        $('#percentage-of-market').text(appraisal.percentage_of_market.toFixed(2) + '%');
        $('#items-count').text(appraisal.items.length);

        // Build items table
        const tbody = $('#appraisal-items-tbody');
        tbody.empty();

        appraisal.items.forEach(item => {
            const row = $('<tr>');
            
            row.append(`
                <td>
                    <img src="https://images.evetech.net/types/${item.type_id}/icon?size=32" 
                         class="appraisal-item-icon" 
                         alt="${item.type_name}">
                    ${item.type_name}
                </td>
                <td class="text-right">${formatNumber(item.quantity)}</td>
                <td class="text-right">${formatISK(item.market_price)}</td>
                <td class="text-right">${item.percentage.toFixed(2)}%</td>
                <td class="text-right">${formatISK(item.buyback_price)}</td>
                <td class="text-right"><strong>${formatISK(item.total_value)}</strong></td>
            `);
            
            tbody.append(row);
        });

        resultsDiv.addClass('show');
    }

    // Show/hide functions
    function showLoading() {
        $('#appraisal-loading').addClass('show');
    }

    function hideLoading() {
        $('#appraisal-loading').removeClass('show');
    }

    function showError(message) {
        $('#appraisal-error-message').text(message);
        $('#appraisal-error').addClass('show');
    }

    function hideError() {
        $('#appraisal-error').removeClass('show');
    }

    function hideResults() {
        $('#appraisal-results').removeClass('show');
    }

    // Format functions
    function formatISK(value) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(value) + ' ISK';
    }

    function formatNumber(value) {
        return new Intl.NumberFormat('en-US').format(value);
    }

    // Copy appraisal button
    $(document).on('click', '#copy-appraisal', function() {
        if (!currentAppraisal) return;

        let text = 'Buyback Appraisal\n';
        text += '=================\n\n';
        text += `Total Value: ${formatISK(currentAppraisal.total_value)}\n`;
        text += `Market Value: ${formatISK(currentAppraisal.total_market_value)}\n`;
        text += `Percentage: ${currentAppraisal.percentage_of_market.toFixed(2)}%\n\n`;
        text += 'Items:\n';
        
        currentAppraisal.items.forEach(item => {
            text += `${item.type_name} x${item.quantity} = ${formatISK(item.total_value)}\n`;
        });

        window.BuybackManager.copyToClipboard(text);
        
        const btn = $(this);
        const originalHtml = btn.html();
        btn.html('<i class="fa fa-check"></i> Copied!');
        setTimeout(() => {
            btn.html(originalHtml);
        }, 2000);
    });

})(jQuery);
