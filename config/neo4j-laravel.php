<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Neo4j query log channel (optional)
    |--------------------------------------------------------------------------
    |
    | When set to a non-empty string that matches a key in config/logging.php
    | "channels", each in-memory query log entry is written with
    | Log::channel(<name>)->debug(...) after the HTTP request or console
    | command finishes. When empty, entries use the application default logger
    | (Log::debug without a named channel).
    |
    */
    'query_log_channel' => env('NEO4J_QUERY_CHANNEL', ''),

    /*
    |--------------------------------------------------------------------------
    | Allow destructive schema operations
    |--------------------------------------------------------------------------
    |
    | Controls data-deleting Schema APIs on Neo4j:
    | drop() / dropIfExists() (DETACH DELETE label nodes), dropAllTables()
    | (full wipe — also used by migrate:fresh), and dropDatabaseIfExists().
    |
    | Schema-only helpers (dropSchema, dropIndex, dropConstraint) are never
    | blocked by this flag.
    |
    | null / unset → allow unless APP_ENV is production
    | true / false → explicit allow or deny (via NEO4J_ALLOW_DESTRUCTIVE_SCHEMA)
    |
    | Per-connection override: set allow_destructive_schema on the connection
    | array in config/database.php (takes precedence over this package key).
    |
    */
    'allow_destructive_schema' => env('NEO4J_ALLOW_DESTRUCTIVE_SCHEMA'),
];
