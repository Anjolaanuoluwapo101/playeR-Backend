<?php

namespace app\Controllers;

class AudiomackController
{
    // Static properties for storing credentials and tokens
    private static $consumerKey = 'YOUR_CONSUMER_KEY';
    private static $consumerSecret = 'YOUR_CONSUMER_SECRET';
    private static $callbackUrl = 'YOUR_CALLBACK_URL'; // e.g., 'https://yourapp.com/callback'

    private static $oauthToken;
    private static $oauthTokenSecret;

    // Step 1: Request Unauthorized Request Token
    public static function requestToken()
    {
        $url = 'https://api.audiomack.com/v1/request_token'; // Audiomack request token endpoint

        // Prepare OAuth parameters
        $oauthParams = [
            'oauth_callback' => self::$callbackUrl,
        ];

        // Generate the OAuth signature and make the request
        $oauthHeaders = self::generateOAuthHeaders($url, $oauthParams, 'POST');

        // Send the request
        $response = self::sendRequest($url, $oauthHeaders, 'POST');

        parse_str($response, $tokens);

        if (isset($tokens['oauth_token']) && isset($tokens['oauth_token_secret'])) {
            // Store the token and token secret for later
            self::$oauthToken = $tokens['oauth_token'];
            self::$oauthTokenSecret = $tokens['oauth_token_secret'];

            // Redirect user to Audiomack authorization page
            $authorizationUrl = 'https://audiomack.com/oauth/authenticate?oauth_token=' . self::$oauthToken;
            header('Location: ' . $authorizationUrl);
            exit;
        } else {
            echo 'Error: Could not obtain request token.';
        }
    }

    // Step 2: Obtain User Authorization (Redirect to Audiomack's authentication page)
    public static function authenticate()
    {
        // This step is handled in the requestToken method by redirecting to the authorization URL
        // The user needs to authorize the app at the Audiomack login screen
    }

    // Step 3: Handle the Callback and Exchange Request Token for Access Token
    public static function callback()
    {
        if (isset($_GET['oauth_token']) && isset($_GET['oauth_verifier'])) {
            $oauthToken = $_GET['oauth_token'];
            $oauthVerifier = $_GET['oauth_verifier'];

            // Check if the OAuth token matches the one stored
            if (self::$oauthToken != $oauthToken) {
                die('Error: Invalid OAuth token.');
            }

            // Now we can exchange the request token for an access token
            $url = 'https://api.audiomack.com/v1/access_token'; // Audiomack access token endpoint

            $oauthParams = [
                'oauth_token' => $oauthToken,
                'oauth_verifier' => $oauthVerifier
            ];

            // Generate OAuth headers for the access token request
            $oauthHeaders = self::generateOAuthHeaders($url, $oauthParams, 'POST');

            // Send the request to obtain the access token
            $response = self::sendRequest($url, $oauthHeaders, 'POST');

            parse_str($response, $tokens);

            if (isset($tokens['oauth_token']) && isset($tokens['oauth_token_secret'])) {
                // Save the access token and secret in the session or database
                $_SESSION['oauth_token'] = $tokens['oauth_token'];
                $_SESSION['oauth_token_secret'] = $tokens['oauth_token_secret'];

                echo 'Successfully authenticated with Audiomack!';
            } else {
                echo 'Error: Could not obtain access token.';
            }
        } else {
            echo 'Error: Missing OAuth parameters.';
        }
    }

    // Helper function to generate OAuth headers
    private static function generateOAuthHeaders($url, $params, $method)
    {
        // Default OAuth parameters
        $oauth = [
            'oauth_consumer_key' => self::$consumerKey,
            'oauth_token' => self::$oauthToken,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => time(),
            'oauth_nonce' => md5(mt_rand()),
            'oauth_version' => '1.0'
        ];

        // Merge OAuth parameters with the given parameters
        $oauth = array_merge($oauth, $params);

        // Generate the signature
        $baseString = self::buildBaseString($url, $method, $oauth);
        $oauth['oauth_signature'] = self::generateSignature($baseString);

        // Build the OAuth header
        $header = 'OAuth ' . http_build_query($oauth, '', ', ');
        return ['Authorization: ' . $header];
    }

    // Helper function to build the base string for signature
    private static function buildBaseString($url, $method, $params)
    {
        ksort($params);
        $paramString = http_build_query($params, '', '&');
        return strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode($paramString);
    }

    // Helper function to generate the OAuth signature
    private static function generateSignature($baseString)
    {
        $key = rawurlencode(self::$consumerSecret) . '&' . rawurlencode(self::$oauthTokenSecret);
        return base64_encode(hash_hmac('sha1', $baseString, $key, true));
    }

    // Helper function to send HTTP requests
    private static function sendRequest($url, $headers, $method)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }
}
