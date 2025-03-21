<?php

/**
 * GOG Claim - Main Class
 * 
 * @author GooglyBlox
 * @version 1.0
 */

namespace GogAutoClaim;

class GogAutoClaim
{
    /** @var string GOG login URL */
    private const LOGIN_URL = 'https://login.gog.com/login';

    /** @var string GOG account page URL to verify login status */
    private const ACCOUNT_URL = 'https://www.gog.com/en/account';

    /** @var string GOG homepage URL with giveaway section */
    private const GIVEAWAY_URL = 'https://www.gog.com/en/#giveaway';

    /** @var string GOG giveaway claim URL */
    private const CLAIM_URL = 'https://www.gog.com/en/giveaway/claim';

    /** @var string GOG two-factor authentication URL */
    private const TWO_FACTOR_URL = 'https://login.gog.com/login/two_step';

    /** @var Config Configuration instance */
    private $config;

    /** @var HttpClient HTTP client instance */
    private $httpClient;

    /** @var Webhook Webhook notifier instance */
    private $webhook;

    /** @var bool Whether two-factor authentication is required */
    private $twoFactorRequired = false;

    /**
     * Constructor
     * 
     * @param Config $config Configuration instance
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->httpClient = new HttpClient($config->isDebug());
        $this->webhook = new Webhook($this->httpClient, $config->getWebhookUrl(), $config->isDebug());
    }

    /**
     * Login to GOG.com
     * 
     * @return bool True if login successful, false otherwise
     */
    public function login(): bool
    {
        // First visit the main GOG site to establish cookies
        $this->httpClient->request('https://www.gog.com/');
        $this->addDelay();

        // Get the login page
        $loginPageResponse = $this->httpClient->request(self::LOGIN_URL);

        if ($loginPageResponse['status_code'] !== 200) {
            return false;
        }

        // Extract CSRF token
        preg_match('/<input type="hidden" id="login__token" name="login\[_token\]" value="([^"]+)"/', $loginPageResponse['body'], $matches);

        if (empty($matches[1])) {
            return false;
        }

        $csrfToken = $matches[1];

        // Prepare login data
        $loginData = [
            'login[username]' => $this->config->getUsername(),
            'login[password]' => $this->config->getPassword(),
            'login[login]' => '',
            'login[login_flow]' => 'default',
            'login[_token]' => $csrfToken
        ];

        $loginHeaders = [
            'Origin' => 'https://login.gog.com',
            'Referer' => 'https://login.gog.com/login'
        ];

        // Extract form action from the login page
        preg_match('/<form name="login" method="post" action="([^"]+)"/', $loginPageResponse['body'], $formMatches);
        $formAction = !empty($formMatches[1]) ? $formMatches[1] : '/login_check';
        $loginCheckUrl = 'https://login.gog.com' . $formAction;

        // Perform login
        $loginResponse = $this->httpClient->request($loginCheckUrl, 'POST', $loginData, $loginHeaders);

        // Copy cookies from login.gog.com to www.gog.com and .gog.com
        $this->httpClient->copyCookiesBetweenDomains('login.gog.com', 'www.gog.com');
        $this->httpClient->copyCookiesBetweenDomains('login.gog.com', '.gog.com');

        // Visit the GOG homepage to establish cross-domain cookies
        $this->httpClient->request('https://www.gog.com/');
        $this->addDelay();

        // Try direct cookie-less verification
        $directCheckUrl = 'https://www.gog.com/user/data/info';
        $userInfoResponse = $this->httpClient->request($directCheckUrl);

        // Parse JSON response to check for valid authentication
        $userInfo = json_decode($userInfoResponse['body'], true);

        if (isset($userInfo['isLoggedIn']) && $userInfo['isLoggedIn'] === true) {
            return true;
        }

        // If we can't determine directly, try the account page
        return $this->verifyLogin();
    }

    /**
     * Add a short delay between requests to avoid triggering anti-bot measures
     */
    private function addDelay(): void
    {
        $delay = mt_rand(500000, 1500000);
        usleep($delay); // 0.5-1.5 second delay
    }

    /**
     * Verify login by checking if we can access the account page
     * 
     * @return bool True if verified, false otherwise
     */
    private function verifyLogin(): bool
    {
        $accountResponse = $this->httpClient->request(self::ACCOUNT_URL);

        // Check for redirection to login page
        if (strpos($accountResponse['headers'], 'location: /en##openlogin') !== false) {
            return false;
        }

        // Look for indicators of being logged in
        $isLoggedIn = false;

        // Check for common elements in the logged-in page
        $loggedInIndicators = [
            'accountMenu',
            'account-management',
            'accountDropdown',
            'user-profile',
            'logout',
            'myAccount',
            'js-user-menu',
            $this->config->getUsername() // Check if username appears on the page
        ];

        foreach ($loggedInIndicators as $indicator) {
            if (strpos($accountResponse['body'], $indicator) !== false) {
                $isLoggedIn = true;
                break;
            }
        }

        return $isLoggedIn;
    }

    /**
     * Check for giveaway and claim it if available
     * 
     * @return bool True if a game was claimed, false otherwise
     */
    public function checkAndClaimGiveaway(): bool
    {
        // Get the giveaway page
        $giveawayPageResponse = $this->httpClient->request(self::GIVEAWAY_URL);

        if ($giveawayPageResponse['status_code'] !== 200) {
            return false;
        }

        // Check if giveaway section exists
        if (strpos($giveawayPageResponse['body'], 'giveawaySection') === false) {
            return false;
        }

        // Check if giveaway is already claimed
        if (strpos($giveawayPageResponse['body'], 'giveaway__content--claimed') !== false) {
            return false;
        }

        // Extract game name
        preg_match('/Claim ([^<(]+)/', $giveawayPageResponse['body'], $gameMatches);
        $gameName = !empty($gameMatches[1]) ? trim($gameMatches[1]) : 'Unknown Game';

        // Extract the game URL from the link for notification purposes
        preg_match('/href="(https:\/\/www\.gog\.com\/en\/game\/([^"]+))"/', $giveawayPageResponse['body'], $urlMatches);
        $gameUrl = $urlMatches[1] ?? '';

        // Extract game image URL if available
        preg_match('/srcset="([^"]+_giveaway_465w\.(?:webp|jpg))"/', $giveawayPageResponse['body'], $imageMatches);
        $gameImageUrl = $imageMatches[1] ?? '';

        // Set referer header to avoid potential issues
        $headers = [
            'Referer: ' . self::GIVEAWAY_URL
        ];

        // Simply visit the claim URL to claim the giveaway
        $claimResponse = $this->httpClient->request(self::CLAIM_URL, 'GET', [], $headers);

        // Check if claim was successful based on the response
        $responseJson = json_decode($claimResponse['body'], true);

        if ($responseJson && isset($responseJson['message'])) {
            if ($responseJson['message'] === 'Already claimed') {
                return false;
            } elseif ($responseJson['message'] === 'Unauthorized') {
                return false;
            }
        }

        // If we didn't get 'Already claimed' or 'Unauthorized', consider it a success

        // Send webhook notification if configured
        if ($this->config->getWebhookUrl()) {
            $this->webhook->sendDiscordNotification($gameName, $gameUrl, $gameImageUrl);
        }

        return true;
    }

    /**
     * Run the complete process
     * 
     * @return bool True if process completed successfully
     */
    public function run(): bool
    {
        // Step 1: Login
        if (!$this->login()) {
            if ($this->twoFactorRequired) {
                echo 'Two-factor authentication is required for this account' . PHP_EOL;
                echo 'To use this script, you need to:' . PHP_EOL;
                echo '1. Either disable two-factor authentication on your GOG account' . PHP_EOL;
                echo '2. Or modify this script to handle the two-factor authentication flow' . PHP_EOL;
            } else {
                echo 'Login failed, aborting' . PHP_EOL;
            }
            return false;
        }

        // Step 2: Check and claim giveaway
        $claimed = $this->checkAndClaimGiveaway();

        if ($claimed) {
            echo 'Successfully claimed a free game!' . PHP_EOL;
        } else {
            echo 'No new games to claim at this time.' . PHP_EOL;
        }

        return $claimed;
    }
}
