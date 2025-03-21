<?php

/**
 * GOG Claim - Configuration Handler
 * 
 * @author GooglyBlox
 * @version 1.0
 */

namespace GogAutoClaim;

class Config
{
    /** @var string GOG username/email */
    private $username;

    /** @var string GOG password */
    private $password;

    /** @var string|null Webhook URL for notifications */
    private $webhookUrl;

    /** @var bool Enable debug output */
    private $debug;

    /** @var array Raw configuration data */
    private $configData;

    /**
     * Constructor
     * 
     * @param string $configFile Path to configuration file
     * @param array $cliOptions Command-line options (overrides config file)
     * @throws \Exception If required configuration is missing
     */
    public function __construct(string $configFile = null, array $cliOptions = [])
    {
        // Load configuration
        $this->configData = $this->loadConfig($configFile);

        // Override with CLI options if provided
        $this->applyCliOptions($cliOptions);

        // Override with environment variables if set
        $this->applyEnvironmentVariables();

        // Validate configuration
        $this->validateConfig();
    }

    /**
     * Load configuration from file
     * 
     * @param string|null $configFile Path to configuration file
     * @return array Configuration data
     */
    private function loadConfig(?string $configFile): array
    {
        $config = [];

        if ($configFile && file_exists($configFile)) {
            $fileConfig = require $configFile;

            if (is_array($fileConfig)) {
                $config = $fileConfig;
            }
        }

        return $config;
    }

    /**
     * Apply command-line options to configuration
     * 
     * @param array $options Command-line options
     */
    private function applyCliOptions(array $options): void
    {
        if (isset($options['username'])) {
            $this->configData['username'] = $options['username'];
        }

        if (isset($options['password'])) {
            $this->configData['password'] = $options['password'];
        }

        if (isset($options['webhook'])) {
            $this->configData['webhook_url'] = $options['webhook'];
        }

        if (isset($options['debug'])) {
            $this->configData['debug'] = filter_var($options['debug'], FILTER_VALIDATE_BOOLEAN);
        }
    }

    /**
     * Apply environment variables to configuration
     */
    private function applyEnvironmentVariables(): void
    {
        // Check for username in environment
        $envUsername = getenv('GOG_USERNAME');
        if ($envUsername !== false) {
            $this->configData['username'] = $envUsername;
        }

        // Check for password in environment
        $envPassword = getenv('GOG_PASSWORD');
        if ($envPassword !== false) {
            $this->configData['password'] = $envPassword;
        }

        // Check for webhook URL in environment
        $envWebhook = getenv('GOG_WEBHOOK_URL');
        if ($envWebhook !== false) {
            $this->configData['webhook_url'] = $envWebhook;
        }

        // Check for debug mode in environment
        $envDebug = getenv('GOG_DEBUG');
        if ($envDebug !== false) {
            $this->configData['debug'] = filter_var($envDebug, FILTER_VALIDATE_BOOLEAN);
        }
    }

    /**
     * Validate configuration
     * 
     * @throws \Exception If required configuration is missing
     */
    private function validateConfig(): void
    {
        // Check for required fields
        if (empty($this->configData['username'])) {
            throw new \Exception('GOG username is required. Use --username option, set GOG_USERNAME environment variable, or configure in config.php');
        }

        if (empty($this->configData['password'])) {
            throw new \Exception('GOG password is required. Use --password option, set GOG_PASSWORD environment variable, or configure in config.php');
        }

        // Set properties for easy access
        $this->username = $this->configData['username'];
        $this->password = $this->configData['password'];
        $this->webhookUrl = $this->configData['webhook_url'] ?? null;
        $this->debug = !empty($this->configData['debug']);
    }

    /**
     * Get username
     * 
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Get password
     * 
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Get webhook URL
     * 
     * @return string|null
     */
    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    /**
     * Check if debug mode is enabled
     * 
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Get all config data as array
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'password' => $this->password,
            'webhook_url' => $this->webhookUrl,
            'debug' => $this->debug
        ];
    }
}
