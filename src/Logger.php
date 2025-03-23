<?php

namespace GooglyBlox\GogClaimPhp;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\HandlerInterface;

class Logger
{
    private MonologLogger $logger;
    private bool $debug;

    public function __construct(array $config)
    {
        $this->debug = $config['debug'] ?? false;
        
        // Create the logger
        $this->logger = new MonologLogger('gog-claimer');
        
        // Format for console output
        $dateFormat = "Y-m-d H:i:s";
        $output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
        $formatter = new LineFormatter($output, $dateFormat);
        
        // Determine where to log
        $logPath = !empty($config['log_file']) ? $config['log_file'] : 'php://stdout';
        
        // Create handler based on log path
        $handler = new StreamHandler($logPath, $this->debug ? MonologLogger::DEBUG : MonologLogger::INFO);
        $handler->setFormatter($formatter);
        
        $this->logger->pushHandler($handler);
    }

    public function debug(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->logger->debug($message, $context);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->logger->notice($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->logger->critical($message, $context);
    }
}