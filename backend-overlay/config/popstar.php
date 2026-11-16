<?php

return [
    // env() is used only in config: works after php artisan config:cache.
    'sync_api_token' => env('SYNC_API_TOKEN', ''),
    'local_bridge_token' => env('LOCAL_ERP_BRIDGE_TOKEN', ''),
    'local_bridge_url' => env('LOCAL_ERP_BRIDGE_URL', 'http://host.docker.internal:18900'),
    'erp_source_system' => env('ERP_SOURCE_SYSTEM', 'BPLUS'),
    'line_channel_secret' => env('LINE_CHANNEL_SECRET', ''),
    'line_channel_access_token' => env('LINE_CHANNEL_ACCESS_TOKEN', ''),
];
