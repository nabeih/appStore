<?php

return [
    'visual_enabled' => env('APP_ENV') !== 'testing' && (bool) env('VISUAL_SEARCH_ENABLED', false),
    'indexing_enabled' => env('APP_ENV') !== 'testing' && (bool) env('VISUAL_SEARCH_INDEXING', false),
    'model_version' => 'clip-b32-int8-583fd111-clip-preprocess-v1',
    'runtime_url' => env('VISUAL_SEARCH_RUNTIME_URL', 'http://127.0.0.1:8765'),
    'token_path' => storage_path('app/private/visual-search/runtime-token'),
    'python' => storage_path('app/private/visual-search/venv/'.(PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python')),
];
