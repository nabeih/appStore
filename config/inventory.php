<?php

return [
    'allow_setup_changes' => env('INVENTORY_ALLOW_SETUP_CHANGES', true),
    'low_stock_threshold' => (int) env('INVENTORY_LOW_STOCK_THRESHOLD', 5),
];
