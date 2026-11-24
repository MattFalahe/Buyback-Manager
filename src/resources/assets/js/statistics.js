(function($) {
    'use strict';

    let dailyChart = null;

    // Initialize charts
    function initCharts() {
        const chartData = window.chartData || {};
        
        if (chartData.daily && chartData.daily.length > 0) {
            createDailyChart(chartData.daily);
        }
    }

    // Create daily value chart
    function createDailyChart(data) {
        const ctx = document.getElementById('daily-chart');
        if (!ctx) return;

        const labels = data.map(item => item.date);
        const values = data.map(item => parseFloat(item.value));
        const counts = data.map(item => parseInt(item.count));

        dailyChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total Value (ISK)',
                    data: values,
                    borderColor: '#00a65a',
                    backgroundColor: 'rgba(0, 166, 90, 0.1)',
                    tension: 0.4,
                    yAxisID: 'y'
                }, {
                    label: 'Contracts',
                    data: counts,
                    borderColor: '#3c8dbc',
                    backgroundColor: 'rgba(60, 141, 188, 0.1)',
                    tension: 0.4,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.datasetIndex === 0) {
                                    label += formatISK(context.parsed.y);
                                } else {
                                    label += context.parsed.y;
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        ticks: {
                            callback: function(value) {
                                return formatISK(value);
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });
    }

    // Format ISK
    function formatISK(value) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(value);
    }

    // Date range picker handler
    $(document).on('change', '#date-from, #date-to', function() {
        $('#filter-form').submit();
    });

    // Corporation filter
    $(document).on('change', '#corporation-filter', function() {
        $('#filter-form').submit();
    });

    // Initialize on document ready
    $(document).ready(function() {
        initCharts();
    });

})(jQuery);
