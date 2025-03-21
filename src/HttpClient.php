<?php

/**
 * GOG Claim - HTTP Client
 * 
 * @author GooglyBlox
 * @version 1.0
 */

namespace GogAutoClaim;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\GuzzleException;

class HttpClient
{
    /** @var string User agent to use for requests */
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36';

    /** @var Client Guzzle HTTP client */
    private $client;

    /** @var CookieJar Cookie jar for storing cookies */
    private $cookieJar;

    /** @var bool Debug mode */
    private $debug;

    /**
     * Constructor
     * 
     * @param bool $debug Enable debug output
     */
    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
        $this->cookieJar = new CookieJar();

        // Initialize Guzzle client
        $this->client = new Client([
            'cookies' => $this->cookieJar,
            'allow_redirects' => [
                'max'             => 10,
                'strict'          => false,
                'referer'         => true,
                'track_redirects' => true,
            ],
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
            ],
            'timeout' => 30,
            'http_errors' => false,
        ]);
    }

    /**
     * Copy cookies from one domain to another
     * 
     * @param string $fromDomain Source domain
     * @param string $toDomain Target domain
     */
    public function copyCookiesBetweenDomains(string $fromDomain, string $toDomain): void
    {
        $cookies = $this->cookieJar->toArray();

        foreach ($cookies as $cookie) {
            if (strpos($cookie['Domain'], $fromDomain) !== false) {
                $newCookie = new SetCookie([
                    'Domain'  => $toDomain,
                    'Name'    => $cookie['Name'],
                    'Value'   => $cookie['Value'],
                    'Path'    => $cookie['Path'],
                    'Max-Age' => $cookie['Max-Age'] ?? null,
                    'Expires' => $cookie['Expires'] ?? null,
                    'Secure'  => $cookie['Secure'],
                    'HttpOnly' => $cookie['HttpOnly'],
                ]);

                $this->cookieJar->setCookie($newCookie);
            }
        }
    }

    /**
     * Send a HTTP request
     * 
     * @param string $url URL to request
     * @param string $method HTTP method (GET or POST)
     * @param array|string $data Optional POST data
     * @param array $headers Optional additional headers
     * @return array Associative array with 'headers', 'body', and 'status_code'
     */
    public function request(string $url, string $method = 'GET', $data = [], array $headers = []): array
    {
        $options = [
            'headers' => $headers,
        ];

        if ($method === 'POST') {
            if (is_array($data)) {
                $options['form_params'] = $data;
            } else {
                $options['body'] = $data;
            }
        }

        try {
            $response = $this->client->request($method, $url, $options);

            $statusCode = $response->getStatusCode();
            $responseHeaders = $response->getHeaders();
            $responseBody = (string) $response->getBody();

            $headersString = '';
            foreach ($responseHeaders as $name => $values) {
                foreach ($values as $value) {
                    $headersString .= "$name: $value\r\n";
                }
            }

            return [
                'headers' => $headersString,
                'body' => $responseBody,
                'status_code' => $statusCode
            ];
        } catch (GuzzleException $e) {
            return [
                'headers' => '',
                'body' => '',
                'status_code' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get the cookie jar
     * 
     * @return CookieJar
     */
    public function getCookieJar(): CookieJar
    {
        return $this->cookieJar;
    }
}
