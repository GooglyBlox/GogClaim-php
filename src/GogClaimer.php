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
            'verify' => false, // WARNING: Disabling SSL verification is insecure. Remove if possible.
            // If you need it due to local environment issues, keep it, but be aware of the risks.
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
                $this->logger->error("Login process could not be completed. Exiting.");
                return false;
            }

            $this->logger->info("Successfully logged in");

            if (!$this->checkGiveaway()) {
                $this->logger->info("No active giveaway found, or already claimed (and not in test mode). Exiting.");
                return true;
            }

            $shouldAttemptClaim = !$this->testMode;
            if ($this->testMode) {
                $statusData = $this->getGiveawayStatusData();
                if (isset($statusData['isClaimed']) && $statusData['isClaimed'] === true) {
                    $shouldAttemptClaim = false; 
                    $this->logger->debug("Test mode: Giveaway already claimed, simulating success.");
                } else {
                    // Test mode, but not claimed - still allow actual claim for testing purposes if desired
                    // Or force simulation: $shouldAttemptClaim = false;
                    $this->logger->debug("Test mode: Giveaway not claimed, will proceed with claim/simulation.");
                }
            }


            $claimed = $shouldAttemptClaim ? $this->claimGiveaway() : true; 

            if ($claimed) {
                // Ensure game details are available for notification, especially in test mode simulation
                if (is_null($this->gameName) || is_null($this->gameUrl) || is_null($this->gameImage)) {
                    $this->logger->debug("Game details missing, re-checking page/API for notification.");
                    $this->checkGiveawayDetailsFromPage();
                    $statusData = $this->getGiveawayStatusData();
                    $this->parseGameDetailsFromApiData($statusData);
                }

                $message = "Successfully claimed a free game on GOG.com!";
                if ($this->gameName) {
                    $message = "Successfully claimed **{$this->gameName}** on GOG.com!";
                }

                if ($this->testMode) {
                    $simulated = !$shouldAttemptClaim && (isset($statusData['isClaimed']) && $statusData['isClaimed'] === true);
                    $message = "[TEST MODE" . ($simulated ? " Simulation" : "") . "] " . $message . ($simulated ? " (Game was already claimed)" : "");
                }

                $this->logger->info($message);
                $this->sendNotification($message);
                return true;
            } else {
                $this->logger->error("Failed to claim the giveaway after checking.");
                return false;
            }
        } catch (\Exception $e) {
            $this->logger->error("An unexpected error occurred during run: " . $e->getMessage());
            $this->logger->debug("Trace: " . $e->getTraceAsString());
            return false;
        }
    }

    private function login(): bool
    {
        $this->logger->debug("Visiting login page to get token and check state");
        $loginUrl = "https://login.gog.com/auth?brand=gog&client_id=46755278331571209&layout=widget&redirect_uri=https%3A%2F%2Fwww.gog.com%2Fon_login_success&response_type=code";

        try {
            $response = $this->client->get($loginUrl, ['allow_redirects' => true]);
            $content = (string) $response->getBody();
            $redirectHistory = $response->getHeader('X-Guzzle-Redirect-History');
            $finalUrl = !empty($redirectHistory) ? end($redirectHistory) : $loginUrl;
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to fetch login page: " . $e->getMessage());
            return false;
        }

        preg_match('/name="login\[_token\]" value="([^"]+)"/', $content, $matches);
        $csrfToken = $matches[1] ?? null;

        if ($csrfToken) {
            $this->logger->debug("Found CSRF token. Proceeding with standard login.");
            $formParams = [
                'login[username]' => $this->config['username'],
                'login[password]' => $this->config['password'],
                'login[login]' => '',
                'login[login_flow]' => 'default',
                'login[_token]' => $csrfToken
            ];

            $this->logger->debug("Submitting login credentials");
            $postUrl = 'https://login.gog.com/login_check';
            try {
                $response = $this->client->post($postUrl, [
                    'form_params' => $formParams,
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Referer' => $finalUrl
                    ],
                    'allow_redirects' => true
                ]);
            } catch (GuzzleException $e) {
                $this->logger->error("Failed to submit login form: " . $e->getMessage());
                return false;
            }

            return $this->verifyLogin();
        } else {
            $this->logger->debug("CSRF token not found on login page ({$finalUrl}). Checking for CAPTCHA presence.");

            $captchaDivPattern = '/<div[^>]+class="[^"]*g-recaptcha[^"]*"[^>]*>/i';
            $captchaScriptPattern = '/www\.google\.com\/recaptcha|www\.recaptcha\.net/i';

            if (preg_match($captchaDivPattern, $content) || preg_match($captchaScriptPattern, $content)) {
                $this->logger->warning("CSRF token missing AND CAPTCHA elements detected on login page ({$finalUrl}). Aborting this attempt.");
                return false;
            } else {
                $this->logger->error("Could not find CSRF token on login page ({$finalUrl}) and no CAPTCHA detected. Login page structure might have changed or another error occurred.");
                $this->logger->debug("Login page content snippet (first 1500 chars):\n" . substr(strip_tags($content), 0, 1500));
                return false;
            }
        }
    }


    private function verifyLogin(): bool
    {
        $this->logger->debug("Verifying login status via account endpoint");
        try {
            $response = $this->client->get('https://menu.gog.com/v1/account/basic?locale=en-US');
            $data = json_decode((string) $response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error("Login verification failed. Account endpoint returned status: " . $response->getStatusCode());
                $this->logger->debug("Account basic response: " . json_encode($data));
                return false;
            }

            if (isset($data['isLoggedIn']) && $data['isLoggedIn'] === true) {
                $this->logger->debug("Successfully verified login: logged in as " . ($data['username'] ?? 'Unknown'));
                return true;
            }

            $this->logger->error("Login verification failed. Account endpoint indicates 'isLoggedIn' is not true.");
            $this->logger->debug("Account basic response: " . json_encode($data));
            $this->checkEssentialCookies();
            return false;
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to connect to account verification endpoint: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->logger->error("Error during login verification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Helper function to check for essential GOG cookies after login attempt.
     */
    private function checkEssentialCookies(): void
    {
        $essentialCookies = ['gog_lc', 'gog_session'];
        $found = [];
        $missing = [];

        foreach ($essentialCookies as $cookieName) {
            $cookie = $this->cookieJar->getCookieByName($cookieName);
            if ($cookie) {
                $found[] = $cookieName;
            } else {
                $missing[] = $cookieName;
            }
        }

        if (!empty($found)) {
            $this->logger->debug("Found cookies: " . implode(', ', $found));
        }
        if (!empty($missing)) {
            $this->logger->debug("Missing essential cookies after login attempt: " . implode(', ', $missing));
        }
    }


    /**
     * Tries to parse game details from the main GOG page HTML.
     */
    private function checkGiveawayDetailsFromPage(): void
    {
        $this->logger->debug("Checking GOG main page HTML for giveaway details.");
        try {
            $response = $this->client->get('https://www.gog.com/en');
            $content = (string) $response->getBody();

            if (strpos($content, 'giveawaySection') !== false || strpos($content, 'giveaway--active') !== false) {
                $this->logger->debug("Giveaway section potentially found in HTML. Parsing.");
                preg_match('/<giveaway[^>]*>(.*?)<\/giveaway>/s', $content, $giveawayMatches);
                $giveawayElement = $giveawayMatches[0] ?? '';

                if (!empty($giveawayElement)) {
                    if (is_null($this->gameName) && preg_match('/data-game-title="([^"]+)"/i', $giveawayElement, $matches)) {
                        $this->gameName = trim($matches[1]);
                    }
                    if (is_null($this->gameName) && preg_match('/alt="([^"]+)\s+giveaway"/i', $giveawayElement, $matches)) {
                        $this->gameName = trim($matches[1]);
                    }

                    if (is_null($this->gameUrl) && preg_match('/class="giveaway__overlay-link"[^>]+href="([^"]+)"/i', $giveawayElement, $matches)) {
                        $this->gameUrl = $matches[1];
                        if ($this->gameUrl && !preg_match('/^https?:/', $this->gameUrl)) {
                            $this->gameUrl = 'https://www.gog.com' . $this->gameUrl;
                        }
                    }

                    if (is_null($this->gameImage) && preg_match('/<img[^>]+srcset="([^"]+)"/i', $giveawayElement, $matches)) {
                        $srcset = $matches[1];
                        $sources = explode(',', $srcset);
                        $bestImage = '';
                        foreach (array_reverse($sources) as $source) {
                            $parts = preg_split('/\s+/', trim($source));
                            if (count($parts) >= 1) {
                                $url = $parts[0];
                                if ($url && preg_match('/\.jpe?g|\.png/i', $url)) {
                                    $bestImage = $url;
                                    break;
                                }
                            }
                        }
                        if ($bestImage) {
                            $this->gameImage = $bestImage;
                        }
                    }
                    if (is_null($this->gameImage) && preg_match('/<img[^>]+src="([^"]+)"/i', $giveawayElement, $matches)) {
                        $this->gameImage = $matches[1];
                    }

                    $this->logger->debug("Parsed from HTML - Name: {$this->gameName}, URL: {$this->gameUrl}, Image: {$this->gameImage}");
                } else {
                    $this->logger->debug("Could not extract <giveaway> element content.");
                }
            } else {
                $this->logger->debug("No giveaway section signature found in main page HTML.");
            }
        } catch (GuzzleException $e) {
            $this->logger->warning("Could not fetch main page HTML to get giveaway details: " . $e->getMessage());
        }
    }

    /**
     * Fetches and returns the data from the giveaway status API. Returns null on error.
     */
    private function getGiveawayStatusData(): ?array
    {
        $this->logger->debug("Fetching giveaway status API data");
        try {
            $response = $this->client->get('https://www.gog.com/giveaway/status');
            $data = json_decode((string) $response->getBody(), true);

            if ($response->getStatusCode() === 401 || (isset($data['message']) && $data['message'] === 'Unauthorized')) {
                $this->logger->error("Unauthorized checking giveaway status API. Login session likely invalid.");
                return null;
            }
            if ($response->getStatusCode() >= 400) {
                $this->logger->error("Error checking giveaway status API. Status: " . $response->getStatusCode());
                $this->logger->debug("API Response: " . (string)$response->getBody());
                return null;
            }
            if (!is_array($data)) {
                $this->logger->debug("Giveaway status API returned non-array data. Assuming no giveaway. Body: " . (string)$response->getBody());
                return null;
            }

            return $data;
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to connect to giveaway status API: " . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            $this->logger->error("Error processing giveaway status API response: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Parses game details from API data array, filling missing properties.
     */
    private function parseGameDetailsFromApiData(?array $data): void
    {
        if (is_null($data)) return;

        if (is_null($this->gameName) && !empty($data['name'])) {
            $this->gameName = $data['name'];
            $this->logger->debug("Got game name from API: " . $this->gameName);
        }
        if (is_null($this->gameImage) && !empty($data['image'])) {
            $this->gameImage = $data['image'];
            $this->gameImage = preg_replace('/\/([a-f0-9]+)(\.[a-z]+)$/i', '/$1_product_tile$2', $this->gameImage);
            $this->logger->debug("Got game image from API: " . $this->gameImage);
        }
        if (is_null($this->gameUrl) && !empty($data['url'])) {
            $this->gameUrl = $data['url'];
            if ($this->gameUrl && !preg_match('/^https?:/', $this->gameUrl)) {
                $this->gameUrl = 'https://www.gog.com' . $this->gameUrl;
            }
            $this->logger->debug("Got game URL from API: " . $this->gameUrl);
        }
    }


    private function checkGiveaway(): bool
    {
        // 1. Try getting details from HTML first (often better image/url)
        $this->checkGiveawayDetailsFromPage();

        // 2. Get data from the status API
        $data = $this->getGiveawayStatusData();
        if (is_null($data)) {
            // Error occurred or API indicated no giveaway
            $this->logger->info("No valid giveaway status from API.");
            return false;
        }

        // 3. Use API data to supplement or confirm details
        $this->parseGameDetailsFromApiData($data);

        // 4. Check the claim status from API response
        if (isset($data['isClaimed'])) {
            $gameTitle = $this->gameName ?? $data['name'] ?? 'Unknown';
            if ($data['isClaimed'] === true) {
                if ($this->testMode) {
                    $this->logger->info("Giveaway '{$gameTitle}' already claimed, but continuing due to TEST MODE");
                    return true;
                } else {
                    $this->logger->info("Giveaway '{$gameTitle}' already claimed.");
                    return false;
                }
            } else {
                // isClaimed is false - giveaway active and unclaimed
                $this->logger->info("Active giveaway found: '{$gameTitle}'. Ready to claim.");
                return true;
            }
        } else {
            // No 'isClaimed' key likely means no active giveaway
            $this->logger->info("No 'isClaimed' status in API response. Assuming no active giveaway.");
            $this->logger->debug("API Response: " . json_encode($data));
            return false;
        }
    }


    private function claimGiveaway(): bool
    {
        $this->logger->debug("Attempting to claim giveaway via API");
        try {
            $response = $this->client->get('https://www.gog.com/giveaway/claim');
            $data = json_decode((string) $response->getBody(), true);

            // Check response status code first
            if ($response->getStatusCode() >= 400) {
                $this->logger->error("Error calling claim API. Status Code: " . $response->getStatusCode());
                $this->logger->debug("Claim API Response: " . (string)$response->getBody());
                return $this->verifyClaimStatusAfterAttempt();
            }

            // Check response body for success indicators
            if (isset($data['status']) && $data['status'] === 'ok') {
                $this->logger->debug("Claim API returned status 'ok'.");
                return true;
            }
            // GOG sometimes returns 200 OK with empty/null body on success
            if (is_null($data) && $response->getStatusCode() == 200) {
                $this->logger->debug("Claim API returned 200 OK with empty/null body, assuming success.");
                return true;
            }

            $this->logger->warning("Claim API response was not explicitly successful. Verifying status again.");
            $this->logger->debug("Claim API Response: " . (string)$response->getBody());
            return $this->verifyClaimStatusAfterAttempt();
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to connect to claim API: " . $e->getMessage());
            return $this->verifyClaimStatusAfterAttempt();
        } catch (\Exception $e) {
            $this->logger->error("Error processing claim API response: " . $e->getMessage());
            return $this->verifyClaimStatusAfterAttempt();
        }
    }

    private function verifyClaimStatusAfterAttempt(): bool
    {
        $this->logger->debug("Re-checking giveaway status after claim attempt.");
        sleep(3);
        $data = $this->getGiveawayStatusData();

        if (!is_null($data) && isset($data['isClaimed']) && $data['isClaimed'] === true) {
            $this->logger->debug("Claim confirmed via status check after attempt.");
            return true;
        }

        // Log failure details
        $this->logger->error("Claim verification failed. Status check after attempt did not confirm claim.");
        if (!is_null($data)) {
            $this->logger->debug("Post-claim status API Response: " . json_encode($data));
        } else {
            $this->logger->debug("Post-claim status API check failed or returned null.");
        }
        // Check if we are still logged in
        if (!$this->verifyLogin()) {
            $this->logger->warning("Detected not logged in during post-claim verification. Session might have expired.");
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
            $this->logger->debug("Preparing webhook notification");

            $gameTitle = $this->gameName ?? 'Unknown Game';

            $imageUrl = $this->gameImage;
            if (!empty($imageUrl)) {
                $imageUrl = preg_replace('/\/([a-f0-9]+)(?:_product_tile(?:_cover(?:_big)?(?:_2x)?)?|_giveaway_)?(\.(?:webp|png|jpe?g))/i', '/$1_product_card_v2_mobile_slider_639.jpg', $imageUrl);

                if (!preg_match('/\.jpg$/i', $imageUrl)) {
                    $imageUrl = str_replace('.webp', '.jpg', $imageUrl);
                }

                if (strpos($imageUrl, '//') === 0) {
                    $imageUrl = 'https:' . $imageUrl;
                } elseif (!preg_match('/^https?:/', $imageUrl)) {
                    $this->logger->warning("Could not determine protocol for image URL: " . $imageUrl);
                    $imageUrl = null;
                }
            }

            $embed = [
                'title' => $gameTitle,
                'description' => "**Free game claimed from GOG.com!**",
                'url' => $this->gameUrl ?: 'https://www.gog.com/',
                'color' => 7506394,
                'timestamp' => date('c'),
                'footer' => [
                    'text' => 'GogClaim v1.1.0',
                ],
            ];

            if (!empty($imageUrl)) {
                $embed['image'] = ['url' => $imageUrl];
                $this->logger->debug("Using image URL for embed: " . $imageUrl);
            } else {
                $this->logger->debug("No valid game image URL available for embed.");
            }

            $payload = [
                'username' => 'GogClaim Bot',
                'content' => $message,
                'embeds' => [$embed]
            ];

            $this->logger->info("Sending webhook notification to configured URL");
            $response = $this->client->post($this->config['webhook_url'], [
                'json' => $payload,
                'timeout' => 15
            ]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $this->logger->debug("Webhook notification sent successfully (Status: " . $response->getStatusCode() . ")");
            } else {
                $responseBody = (string) $response->getBody();
                $this->logger->error("Failed to send webhook notification. Status: " . $response->getStatusCode() . " Response: " . $responseBody);
            }
        } catch (GuzzleException $e) {
            $this->logger->error("GuzzleException sending webhook notification: " . $e->getMessage());
        } catch (\Exception $e) {
            $this->logger->error("Exception sending webhook notification: " . $e->getMessage());
        }
    }
}
