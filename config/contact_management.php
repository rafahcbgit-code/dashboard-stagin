<?php

return [
    'import' => [
        'processing_chunk_size' => (int) env('CONTACT_IMPORT_PROCESSING_CHUNK_SIZE', 2000),
        'large_file_read_chunk_size' => (int) env('CONTACT_IMPORT_LARGE_FILE_READ_CHUNK_SIZE', 5000),
        'contact_write_chunk_size' => (int) env('CONTACT_IMPORT_CONTACT_WRITE_CHUNK_SIZE', 500),
        'pivot_write_chunk_size' => (int) env('CONTACT_IMPORT_PIVOT_WRITE_CHUNK_SIZE', 1000),
        'progress_update_interval' => (int) env('CONTACT_IMPORT_PROGRESS_UPDATE_INTERVAL', 10000),
    ],

    'static_custom_attributes' => [
        ['value' => 'source', 'label' => 'Source'],
        ['value' => 'lifecycle_stage', 'label' => 'Lifecycle Stage'],
        ['value' => 'preferred_language', 'label' => 'Preferred Language'],
    ],
];
