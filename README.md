# GOG Claim PHP

Automated GOG giveaway claimer that checks for and claims free games from GOG.com giveaways. Designed to run as a cron job for automated claiming.

## Requirements

- PHP 7.4 or higher
- Composer (optional, for dependency management)

## Installation

1. Clone the repository:
```bash
git clone https://github.com/GooglyBlox/GogClaim-php.git
cd GogClaim-php
```

2. Install dependencies (optional):
```bash
composer install
```

## Configuration

Configure via environment variables or edit `config.php`:

```bash
export GOG_USERNAME="your_username"
export GOG_PASSWORD="your_password"
export WEBHOOK_URL="https://discord.com/api/webhooks/..." # Optional
export DEBUG="true" # Optional
export TEST_MODE="true" # Optional
```

## Usage

Run the claimer:
```bash
php gog-claimer.php
```

Or with command line arguments:
```bash
php gog-claimer.php --username=your_username --password=your_password --webhook=webhook_url --debug
```

### Cron Job Setup

Add to your crontab to run automatically (example runs every 6 hours):
```bash
0 */6 * * * /usr/bin/php /path/to/GogClaim-php/gog-claimer.php
```

## License

This project is licensed under the [MIT License](https://github.com/GooglyBlox/GogClaim-php/blob/main/LICENSE).