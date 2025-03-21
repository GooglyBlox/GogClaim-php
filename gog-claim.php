<?php
/**
 * GOG Claim
 * 
 * A PHP script to automatically claim free games on GOG.com.
 * This script is designed to be run via a cron job.
 * 
 * @author GooglyBlox
 * @version 1.0
 */

// Include autoloader if available, otherwise include files directly
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Direct includes if autoloader not available
    require_once __DIR__ . '/src/Config.php';
    require_once __DIR__ . '/src/HttpClient.php';
    require_once __DIR__ . '/src/Webhook.php';
    require_once __DIR__ . '/src/GogAutoClaim.php';
}

use GogAutoClaim\Config;
use GogAutoClaim\GogAutoClaim;

// Only execute if running from command line
if (php_sapi_name() === 'cli') {
    try {
        // Parse CLI options
        $options = getopt('', ['username:', 'password:', 'webhook::', 'debug::', 'config::']);
        
        // Check if a config file was specified, otherwise use default
        $configFile = $options['config'] ?? __DIR__ . '/config.php';
        
        // Check if config file exists
        $configExists = file_exists($configFile);
        
        // Check if credentials are provided via CLI or environment
        $hasCliCredentials = isset($options['username']) && isset($options['password']);
        $hasEnvCredentials = getenv('GOG_USERNAME') !== false && getenv('GOG_PASSWORD') !== false;
        
        // Warn if no config file and no credentials provided
        if (!$configExists && !$hasCliCredentials && !$hasEnvCredentials && $configFile === __DIR__ . '/config.php') {
            echo "Configuration file not found! Please create a config.php file with your credentials." . PHP_EOL;
            echo "Or provide credentials via command line arguments (--username, --password) or environment variables (GOG_USERNAME, GOG_PASSWORD)." . PHP_EOL;
        }
        
        // Load configuration
        $config = new Config($configFile, $options);
        
        // Create the auto-claim instance
        $autoClaimer = new GogAutoClaim($config);
        
        // Run the process
        $result = $autoClaimer->run();
        
        exit($result ? 0 : 1);
    } catch (\Exception $e) {
        echo 'Error: ' . $e->getMessage() . PHP_EOL;
        exit(1);
    }
} else {
    // Not running from CLI
    echo "This script is designed to be run from the command line.";
}