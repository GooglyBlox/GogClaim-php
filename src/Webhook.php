<?php

/**
 * GOG Claim - Webhook Notifier
 * 
 * @author GooglyBlox
 * @version 1.0
 */

namespace GogAutoClaim;

class Webhook
{
    /** @var HttpClient HTTP client for webhook requests */
    private $httpClient;

    /** @var string|null Webhook URL */
    private $webhookUrl;

    /** @var bool Debug mode */
    private $debug;

    /**
     * Constructor
     * 
     * @param HttpClient $httpClient HTTP client instance
     * @param string|null $webhookUrl Webhook URL for notifications
     * @param bool $debug Enable debug output
     */
    public function __construct(HttpClient $httpClient, ?string $webhookUrl = null, bool $debug = false)
    {
        $this->httpClient = $httpClient;
        $this->webhookUrl = $webhookUrl;
        $this->debug = $debug;
    }

    /**
     * Set webhook URL
     * 
     * @param string $url Webhook URL
     * @return self
     */
    public function setWebhookUrl(string $url): self
    {
        $this->webhookUrl = $url;
        return $this;
    }

    /**
     * Send a webhook notification
     * 
     * @param string $gameName Name of the claimed game
     * @param array $additionalData Additional data to include in the notification
     * @return bool True if notification was sent successfully
     */
    public function sendNotification(string $gameName, array $additionalData = []): bool
    {
        if (empty($this->webhookUrl)) {
            return false;
        }

        // Create webhook data payload
        $webhookData = [
            'content' => "GOG Auto Claim: Successfully claimed \"$gameName\"",
            'embeds' => [
                [
                    'title' => 'GOG Free Game Claimed',
                    'description' => "The game \"$gameName\" was automatically claimed and added to your GOG library.",
                    'color' => 3066993, // Green color
                    'timestamp' => date('c')
                ]
            ]
        ];

        // Add any additional data
        if (!empty($additionalData)) {
            $webhookData = array_merge($webhookData, $additionalData);
        }

        $webhookHeaders = [
            'Content-Type: application/json'
        ];

        // Send webhook request
        $webhookResponse = $this->httpClient->request(
            $this->webhookUrl,
            'POST',
            json_encode($webhookData),
            $webhookHeaders
        );

        return $webhookResponse['status_code'] >= 200 && $webhookResponse['status_code'] < 300;
    }

    /**
     * Send a discord webhook notification
     * This is a specialized version of sendNotification for Discord webhooks
     * 
     * @param string $gameName Name of the claimed game
     * @param string $gameUrl URL to the game page
     * @param string $imageUrl URL to the game image
     * @return bool True if notification was sent successfully
     */
    public function sendDiscordNotification(string $gameName, string $gameUrl = '', string $imageUrl = ''): bool
    {
        $additionalData = [];

        // Add Discord-specific data
        $embed = [
            'title' => 'GOG Free Game Claimed',
            'description' => "The game \"$gameName\" was automatically claimed and added to your GOG library.",
            'color' => 3066993,
            'timestamp' => date('c'),
            'footer' => [
                'text' => 'GOG Auto Claim'
            ]
        ];

        // Add game URL if provided
        if (!empty($gameUrl)) {
            $embed['url'] = $gameUrl;
        }

        // Add game image if provided
        if (!empty($imageUrl)) {
            $embed['thumbnail'] = [
                'url' => $imageUrl
            ];
        }

        $additionalData['embeds'] = [$embed];

        return $this->sendNotification($gameName, $additionalData);
    }
}
