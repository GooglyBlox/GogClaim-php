# GogClaim-PHP

A PHP script to automatically claim free games on GOG.com. This tool can be run via cron jobs to regularly check for and claim free game giveaways without manual intervention.

## Requirements

- PHP 7.4 or higher
- PHP extensions: curl, json
- Composer (recommended for dependency management)

## Installation

### Using Composer (recommended)

```bash
# Clone the repository
git clone https://github.com/GooglyBlox/GogClaim-php.git
cd GogClaim-php

# Install dependencies
composer install
```

## Configuration

Modify the `config.php` file based on the included template:

```php
<?php
return [
    // GOG.com account credentials
    'username' => 'your-gog-email@example.com',
    'password' => 'your-gog-password',
    
    // Optional webhook URL for notifications (Discord, Slack, etc.)
    'webhook_url' => 'https://discord.com/api/webhooks/your-webhook-url',
];
```

Alternatively, you can provide credentials via:

- Environment variables: `GOG_USERNAME`, `GOG_PASSWORD`, `GOG_WEBHOOK_URL`
- Command-line options: `--username`, `--password`, `--webhook`

## Usage

### Basic Usage

```bash
php gog-claim.php
```

### With Command-line Options

```bash
php gog-claim.php --username="your-gog-email@example.com" --password="your-gog-password" --webhook="https://discord.com/api/webhooks/your-webhook-url"
```

### Automated Usage with Cron

Add a cron job to run the script periodically:

```
# Check for free games every day at 2:00 AM
0 2 * * * /usr/bin/php /path/to/GogClaim-php/gog-claim.php
```

## Notes

- This script does not currently support accounts with two-factor authentication enabled.
- For security reasons, it's recommended to create a separate GOG account just for claiming free games.

## License

This project is licensed under the MIT License.