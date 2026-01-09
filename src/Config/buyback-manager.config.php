<?php

return [
    'version' => '1.0.0',
    
    // Default settings
    'defaults' => [
        'base_percentage' => 90.0,
        'price_source' => 'jita',
        'jita_region_id' => 10000002,
    ],
    
    // Price update frequency (in minutes)
    'price_update_frequency' => 240, // 4 hours
    
    // Contract sync frequency (in minutes)
    'contract_sync_frequency' => 15,
    
    // Batch size for price updates
    'price_batch_size' => 100,
];
