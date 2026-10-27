<?php

return [
    'enabled' => true,
    'policy_version' => '1.0',
    'fallback' => true,
    'runtime' => [
        'dns_server' => null,
        'dns_port' => 53,
        'admission_path' => null,
        'device' => null,
        'inode' => null,
        'topology' => null,
        'ca_file' => null,
    ],
    'limits' => [
        'input_max_bytes' => null,
        'total_timeout_ms' => 20000,
        'connect_timeout_ms' => 3000,
        'request_timeout_ms' => 8000,
        'max_requests' => 13,
        'max_redirects' => 0,
        'max_retries' => 0,
        'max_search_pages' => null,
        'max_section_pages' => null,
        'page_size' => null,
        'max_decoded_bytes_per_response' => 2097152,
        'max_decoded_bytes_total' => 8388608,
        'max_nodes_total' => null,
        'outbound_concurrency_limit' => 1,
        'finalization_reserve_ms' => 250,
    ],
];
