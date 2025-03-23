<?php

/**
 * Configuration file for GOG Claim PHP
 */

return [
    // GOG credentials
    'username' => getenv('GOG_USERNAME') ?: '',
    'password' => getenv('GOG_PASSWORD') ?: '',
    
    // Webhook URL for notifications (optional)
    'webhook_url' => getenv('WEBHOOK_URL') ?: '',
    
    // Debug mode (set to true to enable verbose logging)
    'debug' => getenv('DEBUG') === 'true' ?: false,
    
    // Test mode (set to true to process and send webhook even for already claimed giveaways)
    'test_mode' => getenv('TEST_MODE') === 'true' ?: false,
    
    // Log file path (leave empty to log to stdout)
    'log_file' => getenv('LOG_FILE') ?: '',
    
    // User agent to use for requests
    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    
    // Timeout for HTTP requests in seconds
    'timeout' => 30,
    
    // Maximum number of retry attempts for failed requests
    'max_retries' => 3,
];
