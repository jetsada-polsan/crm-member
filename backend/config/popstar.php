<?php

return [
    // env() is used only in config: works after php artisan config:cache.
    'sync_api_token' => env('SYNC_API_TOKEN', ''),
    'local_bridge_token' => env('LOCAL_ERP_BRIDGE_TOKEN', ''),
    'local_bridge_url' => env('LOCAL_ERP_BRIDGE_URL', 'http://host.docker.internal:18900'),
    'erp_source_system' => env('ERP_SOURCE_SYSTEM', 'BPLUS'),
    // Office Bplus SQL Server, probed before scheduled bridge syncs (popstar:sync-erp).
    'office_sql_host' => env('OFFICE_SQL_HOST', '192.168.88.200'),
    'office_sql_port' => (int) env('OFFICE_SQL_PORT', 1433),
    'line_channel_secret' => env('LINE_CHANNEL_SECRET', ''),
    'line_channel_access_token' => env('LINE_CHANNEL_ACCESS_TOKEN', ''),
    'member_portal_url' => env('MEMBER_PORTAL_URL', 'https://crm.popstarmember.com/m/'),
];
