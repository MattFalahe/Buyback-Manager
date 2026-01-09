<?php

return [
    'buyback-manager' => [
        'permission' => 'buyback-manager.view',
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
                'name' => 'Contracts',
                'icon' => 'fas fa-file-contract',
                'route' => 'buyback-manager.contracts.index',
                'permission' => 'buyback-manager.view',
            ],
            [
                'name' => 'Statistics',
                'icon' => 'fas fa-chart-line',
                'route' => 'buyback-manager.statistics.index',
                'permission' => 'buyback-manager.view',
            ],
            [
                'name' => 'Settings',
                'icon' => 'fas fa-cogs',
                'route' => 'buyback-manager.settings.index',
                'permission' => 'buyback-manager.settings',
            ],
        ],
    ],
];
