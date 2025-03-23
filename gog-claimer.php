<?php
/**
 * GOG Giveaway Claimer
 * 
 * This script automatically checks for and claims free games from GOG.com giveaways.
 * 
 * @author GooglyBlox
 */

// Ensure proper error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set time limit to ensure we have enough time to complete the process
set_time_limit(120);

// Define class autoloader if Composer's autoloader isn't available
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    spl_autoload_register(function ($class) {
        // Check if the class belongs to our namespace
        if (strpos($class, 'GooglyBlox\\GogClaimPhp\\') === 0) {
            // Convert namespace to path
            $path = str_replace('\\', '/', $class);
            $path = str_replace('GooglyBlox/GogClaimPhp/', '', $path);
            $file = __DIR__ . '/src/' . $path . '.php';
            
            if (file_exists($file)) {
                require $file;
                return true;
            }
        }
        return false;
    });
} else {
    // Load Composer autoloader
    require __DIR__ . '/vendor/autoload.php';
}

// Start timing the execution
$startTime = microtime(true);

// Process command line arguments
$options = getopt('', ['username:', 'password:', 'webhook:', 'debug::', 'log:']);

// Load configuration
$config = include __DIR__ . '/config.php';

// Override config from command line arguments if provided
if (isset($options['username'])) $config['username'] = $options['username'];
if (isset($options['password'])) $config['password'] = $options['password'];
if (isset($options['webhook'])) $config['webhook_url'] = $options['webhook'];
if (isset($options['debug'])) $config['debug'] = true;
if (isset($options['log'])) $config['log_file'] = $options['log'];

// Validate required configuration
if (empty($config['username']) || empty($config['password'])) {
    echo "Error: GOG username and password are required. Please configure them in config.php or provide them as command-line arguments.\n";
    exit(1);
}

// Create and run the claimer
$claimer = new \GooglyBlox\GogClaimPhp\GogClaimer($config);
$result = $claimer->run();

// Calculate execution time
$executionTime = microtime(true) - $startTime;
echo "Execution completed in " . number_format($executionTime, 2) . " seconds.\n";

// Return appropriate exit code
exit($result ? 0 : 1);