<?php

return [
    // No permission on the root: Appraisal and My Appraisals need only a
    // login, and SeAT hides a root's entries along with it.
    'buyback-manager' => [
        'name' => 'Buyback Manager',
        'icon' => 'fas fa-exchange-alt',
        'route_segment' => 'buyback-manager',
        'entries' => [
            [
                'name' => 'Appraisal',
                'icon' => 'fas fa-calculator',
                'route' => 'buyback.appraisal.index',
            ],
            [
                'name' => 'My Appraisals',
                'icon' => 'fas fa-tag',
                'route' => 'buyback-manager.appraisals.index',
            ],
            [
                'name' => 'Contracts',
                'icon' => 'fas fa-file-contract',
                'route' => 'buyback-manager.contracts.index',
                'permission' => ['buyback-manager.view', 'buyback-manager.settings', 'buyback-manager.admin'],
            ],
            [
                'name' => 'Statistics',
                'icon' => 'fas fa-chart-line',
                'route' => 'buyback-manager.statistics.index',
                'permission' => ['buyback-manager.view', 'buyback-manager.settings', 'buyback-manager.admin'],
            ],
            [
                'name' => 'Analytics',
                'icon' => 'fas fa-chart-pie',
                'route' => 'buyback-manager.analytics.index',
                'permission' => ['buyback-manager.view', 'buyback-manager.settings', 'buyback-manager.admin'],
            ],
            [
                'name' => 'Settings',
                'icon' => 'fas fa-cogs',
                'route' => 'buyback-manager.settings.index',
                'permission' => ['buyback-manager.settings', 'buyback-manager.admin'],
            ],
            [
                'name' => 'Help & Documentation',
                'icon' => 'fas fa-question-circle',
                'route' => 'buyback-manager.help',
                'permission' => ['buyback-manager.view', 'buyback-manager.settings', 'buyback-manager.admin'],
            ],
        ],
    ],
];
