<?php

namespace GooglyBlox\GogClaimPhp;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;

class GogClaimer
{
    private $client;
    private $cookieJar;
    private $logger;
    private $config;
    private $gameName = null;
    private $gameUrl = null;
    private $gameImage = null;
    private $testMode = false;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logger = new Logger($config);
        $this->cookieJar = new CookieJar();
        $this->testMode = $config['test_mode'] ?? false;
        
        $this->client = new Client([
            'cookies' => $this->cookieJar,
            'headers' => [
                'User-Agent' => $config['user_agent'],
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => 'https://www.gog.com/',
                'Origin' => 'https://www.gog.com',
            ],
            'http_errors' => false,
            'allow_redirects' => true,
            'timeout' => $config['timeout'] ?? 30,
        ]);
        
        if ($this->testMode) {
            $this->logger->info("TEST MODE ACTIVE: Will process and send webhook even for already claimed giveaways");
        }
    }
    
    public function run(): bool
    {
        try {
            $this->logger->info("Starting GOG giveaway claim process");
            
            if (!$this->login()) {
                $this->logger->error("Login failed. Exiting.");
                return false;
            }
            
            $this->logger->info("Successfully logged in");
            
            if (!$this->checkGiveaway()) {
                $this->logger->info("No active giveaway found. Exiting.");
                return true;
            }
            
            // In test mode, we skip the actual claim if already claimed
            $claimed = $this->testMode ? true : $this->claimGiveaway();
            
            if ($claimed) {
                $message = "Successfully claimed a free game on GOG.com!";
                if ($this->gameName) {
                    $message = "Successfully claimed **{$this->gameName}** on GOG.com!";
                }
                
                // Add test mode indicator to message
                if ($this->testMode) {
                    $message = "[TEST MODE] " . $message;
                }
                
                $this->logger->info($message);
                $this->sendNotification($message);
                return true;
            } else {
                $this->logger->error("Failed to claim the giveaway.");
                return false;
            }
            
        } catch (\Exception $e) {
            $this->logger->error("An error occurred: " . $e->getMessage());
            return false;
        }
    }
    
    private function login(): bool
    {
        $this->logger->debug("Visiting login page");
        $loginUrl = "https://login.gog.com/auth?brand=gog&client_id=46755278331571209&layout=widget&redirect_uri=https%3A%2F%2Fwww.gog.com%2Fon_login_success%3FreturnTo%3D%2Fcheckout%2Fwidget%2F52756712356612660%2F1326849971%253Fprice%253D%252524%252B17.99&response_type=code&widget_game_id=1326849971&widget_h=5174508bfaceeacdf2da528ed092a5ac2e69f704&widget_price=%24%2017.99";
        
        $response = $this->client->get($loginUrl);
        $content = (string) $response->getBody();
        
        preg_match('/name="login\[_token\]" value="([^"]+)"/', $content, $matches);
        if (empty($matches[1])) {
            $this->logger->error("Could not find CSRF token");
            return false;
        }
        
        $csrfToken = $matches[1];
        $this->logger->debug("Got CSRF token: " . $csrfToken);
        
        $formParams = [
            'login[username]' => $this->config['username'],
            'login[password]' => $this->config['password'],
            'login[login]' => '',
            'login[login_flow]' => 'default',
            'login[_token]' => $csrfToken
        ];
        
        $response = $this->client->post('https://login.gog.com/login_check', [
            'form_params' => $formParams,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Referer' => $loginUrl
            ]
        ]);
        
        return $this->verifyLogin();
    }
    
    private function verifyLogin(): bool
    {
        $this->logger->debug("Verifying login status");
        $response = $this->client->get('https://menu.gog.com/v1/account/basic?locale=en-US&currency=USD&country=US');
        $data = json_decode((string) $response->getBody(), true);
        
        if (!isset($data['isLoggedIn']) || $data['isLoggedIn'] !== true) {
            $this->logger->error("Not logged in according to account/basic endpoint");
            return false;
        }
        
        $this->logger->debug("Successfully verified login: logged in as " . ($data['username'] ?? 'Unknown'));
        return true;
    }
    
    private function checkGiveaway(): bool
    {
        $this->logger->debug("Visiting giveaway page");
        $response = $this->client->get('https://www.gog.com/en/#giveaway');
        
        $content = (string) $response->getBody();
        if (strpos($content, 'giveawaySection') === false) {
            $this->logger->debug("No giveaway section found on page");
            return false;
        }
        
        preg_match('/<giveaway[^>]*>(.*?)<\/giveaway>/s', $content, $giveawayMatches);
        $giveawayElement = $giveawayMatches[0] ?? '';
        
        if (!empty($giveawayElement)) {
            $this->logger->debug("Found giveaway element");
            
            preg_match('/class="giveaway__overlay-link"\s+href="([^"]+)"/', $giveawayElement, $urlMatches);
            if (!empty($urlMatches[1])) {
                $this->gameUrl = $urlMatches[1];
                $this->logger->debug("Found game URL from giveaway overlay link: " . $this->gameUrl);
            }
            
            preg_match('/srcset="([^"]+)"/', $giveawayElement, $imageMatches);
            if (!empty($imageMatches[1])) {
                $srcsetParts = explode(',', $imageMatches[1]);
                $imageSrc = trim(explode(' ', $srcsetParts[0])[0]);
                $this->gameImage = $imageSrc;
                $this->logger->debug("Found game image from srcset: " . $this->gameImage);
            }
            
            if (strpos($giveawayElement, 'Success') !== false) {
                preg_match('/Success.*?\s+(.*?)\s+was added/', $giveawayElement, $gameMatches);
                if (!empty($gameMatches[1])) {
                    $this->gameName = trim($gameMatches[1]);
                    $this->logger->debug("Found claimed game name: " . $this->gameName);
                }
            } else {
                preg_match('/Claim\s+(.*?)\s+and/', $giveawayElement, $gameMatches);
                if (!empty($gameMatches[1])) {
                    $this->gameName = trim($gameMatches[1]);
                    $this->logger->debug("Found unclaimed game name: " . $this->gameName);
                }
            }
            
            if (empty($this->gameName)) {
                preg_match('/alt="([^"]+)\s+giveaway"/', $giveawayElement, $altMatches);
                if (!empty($altMatches[1])) {
                    $this->gameName = trim($altMatches[1]);
                    $this->logger->debug("Found game name from alt text: " . $this->gameName);
                }
            }
        }
        
        if (empty($this->gameName) && !empty($this->gameUrl)) {
            $urlParts = explode('/', $this->gameUrl);
            $slug = end($urlParts);
            $this->gameName = str_replace('_', ' ', $slug);
            $this->gameName = ucwords($this->gameName);
            $this->logger->debug("Extracted game name from URL: " . $this->gameName);
        }
        
        if (empty($this->gameImage) && preg_match('/images\.gog-statics\.com\/([a-f0-9]+)/', $content, $idMatches)) {
            $imageId = $idMatches[1];
            $this->gameImage = "https://images.gog-statics.com/{$imageId}_product_tile_cover_big_2x.jpg";
            $this->logger->debug("Constructed game image URL: " . $this->gameImage);
        }
        
        $this->logger->debug("Checking giveaway status API");
        $response = $this->client->get('https://www.gog.com/giveaway/status');
        $data = json_decode((string) $response->getBody(), true);
        
        if (isset($data['message']) && $data['message'] === 'Unauthorized') {
            $this->logger->error("Unauthorized when checking giveaway status");
            return false;
        }
        
        // Check if the game is already claimed, but continue in test mode
        if (isset($data['isClaimed']) && $data['isClaimed'] === true) {
            if ($this->testMode) {
                $this->logger->info("Giveaway already claimed, but continuing due to TEST MODE");
                return true;
            } else {
                $this->logger->info("Giveaway already claimed");
                return false;
            }
        }
        
        if (isset($data['isClaimed']) && $data['isClaimed'] === false) {
            $this->logger->info("Giveaway available and not claimed yet!");
            return true;
        }
        
        return false;
    }
    
    private function claimGiveaway(): bool
    {
        $this->logger->debug("Attempting to claim giveaway");
        $response = $this->client->get('https://www.gog.com/giveaway/claim');
        $data = json_decode((string) $response->getBody(), true);
        
        if (isset($data['status']) && $data['status'] === 'ok') {
            $this->logger->debug("Claim successful according to API");
            return true;
        }
        
        $this->logger->debug("Verifying claim status");
        $response = $this->client->get('https://www.gog.com/giveaway/status');
        $data = json_decode((string) $response->getBody(), true);
        
        if (isset($data['isClaimed']) && $data['isClaimed'] === true) {
            $this->logger->debug("Claim confirmed via status check");
            return true;
        }
        
        return false;
    }
    
    private function sendNotification(string $message): void
    {
        if (empty($this->config['webhook_url'])) {
            $this->logger->debug("No webhook URL configured, skipping notification");
            return;
        }
        
        try {
            $this->logger->debug("Sending webhook notification");
            
            $gameTitle = $this->gameName ?? 'Unknown Game';
            
            if (!empty($this->gameImage)) {
                $this->gameImage = str_replace('_giveaway_', '_product_tile_cover_big_2x.', $this->gameImage);
                $this->gameImage = str_replace('.webp', '.jpg', $this->gameImage);
            }
            
            $embed = [
                'title' => $gameTitle,
                'description' => "**Free game claimed from GOG.com!**",
                'url' => $this->gameUrl ?? 'https://www.gog.com/',
                'color' => 7506394,
                'timestamp' => date('c'),
                'footer' => [
                    'text' => 'GogClaim v1.1.0',
                ],
            ];
            
            if (!empty($this->gameImage)) {
                $embed['image'] = [
                    'url' => $this->gameImage
                ];
            }
            
            $payload = [
                'username' => 'GogClaim',
                'content' => $message,
                'embeds' => [$embed]
            ];
            
            // Send the webhook
            $response = $this->client->post($this->config['webhook_url'], [
                'json' => $payload
            ]);
            
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $this->logger->debug("Webhook notification sent successfully");
            } else {
                $this->logger->error("Failed to send webhook notification: " . $response->getStatusCode());
            }
        } catch (\Exception $e) {
            $this->logger->error("Error sending webhook notification: " . $e->getMessage());
        }
    }
}
