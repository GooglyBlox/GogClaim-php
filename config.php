<?php

/**
 * GOG Claim - Default Configuration
 * 
 * Default configuration file for the GOG Claim script.
 * Values here can be overridden by environment variables or command-line options.
 * 
 * @author GooglyBlox
 * @version 1.0
 */

return [
    // GOG.com account credentials
    // These can be overridden with GOG_USERNAME and GOG_PASSWORD environment variables
    // or with --username and --password command-line options
    'username' => '',
    'password' => '',

    // Optional webhook URL for notifications (Discord, Slack, etc.)
    // Can be overridden with GOG_WEBHOOK_URL environment variable
    // or with --webhook command-line option
    'webhook_url' => '',
];
