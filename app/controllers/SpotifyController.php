<?php

namespace app\Controllers;

use SpotifyWebAPI;
use SpotifyWebAPI\SpotifyWebAPIException;


class SpotifyController
{

    public static $redirect_uri = "https://player-backend-qz31.onrender.com/spotify/get_user_access_token";
    public static $token_url = "https://accounts.spotify.com/api/token";
    protected static $client_id;
    protected static $client_secret;
    protected static $scopes = "user-read-private user-read-email"; // Scopes needed for email & profile

    public static function startSession()
    {
        
        self::$client_id = $_ENV['SPOTIFY_CLIENT_ID'] ;
        self::$client_secret = $_ENV['SPOTIFY_CLIENT_SECRET'];

        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.gc_maxlifetime', 3600);
            $session_lifetime = 3600; // 1 hour
            session_set_cookie_params($session_lifetime);
            
            session_start();
        }
    }

    public static function auth()
    {
        self::startSession();

        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        $_SESSION['spotify_original_uri'] = ($path == '/spotify/login')
            ? "https://player-frp1.onrender.com/redirectSpotifyLogin?setCookie=playeRCookieSF&tokenTime=" . ($_SESSION['spotify_token_expiration'] ?? 0)
            : $_SERVER['REQUEST_URI'];

        if (isset($_SESSION['spotify_access_token'], $_SESSION['spotify_refresh_token'], $_SESSION['spotify_token_expiration'])) {
            if (time() < $_SESSION['spotify_token_expiration']) {
                return $_SESSION['spotify_access_token']; // Token is still valid
            }

            // Token expired, refresh it
            try {
                return self::refreshAccessToken($_SESSION['spotify_refresh_token']);
            } catch (SpotifyWebAPI\SpotifyWebAPIException $e) {
                return self::login(); // Refresh failed, re-authenticate
            }
        }

        // No tokens, authenticate the user
        self::login();
    }


    public static function login()
    {

        self::startSession();

        $session = new SpotifyWebAPI\Session(
            self::$client_id,
            self::$client_secret,
            self::$redirect_uri
        );

        $state = $session->generateState();
        $_SESSION['spotify_state'] = $state; // Save the state for validation later

        $options = [
            'scope' => [
                'playlist-read-private',
                'playlist-modify-public',
                'playlist-modify-private',
                'user-read-private',
                'user-read-email',
            ],
            'state' => $state,
        ];

        // Redirect to Spotify's authorization page
        header('Location: ' . $session->getAuthorizeUrl($options));
        die();
    }

    public static function getUserAccessToken()
    {
        self::startSession();

        $session = new SpotifyWebAPI\Session(
            self::$client_id,
            self::$client_secret,
            self::$redirect_uri
        );

        $storedState = $_SESSION['spotify_state'];
        $state = $_GET['state'];

        // Fetch the stored state value from somewhere. A session for example

        if ($state !== $storedState) {
            // The state returned isn't the same as the one we've stored, we shouldn't continue
            die('State mismatch');
        }

        // Request a access token using the code from Spotify
        $session->requestAccessToken($_GET['code']);

        $accessToken = $session->getAccessToken();
        $refreshToken = $session->getRefreshToken();

        // Store the access and refresh tokens somewhere. In a session for example
        $_SESSION['spotify_access_token'] = $accessToken;
        $_SESSION['spotify_refresh_token'] = $refreshToken;
        $_SESSION['spotify_token_expiration'] = time() + 3500;

        if (isset($_SESSION['spotify_original_uri']) && $_SESSION['spotify_original_uri'] == '/spotify/login') {
            $original_uri = $_SESSION['spotify_original_uri'];
            unset($_SESSION['spotify_original_uri']);
            header('Location:' . $original_uri);
        } else {
            header('Location:https://player-frp1.onrender.com/redirectSpotifyLogin?setCookie=playeRCookieSF&tokenTime='.$_SESSION['spotify_token_expiration']);
        }

    }

    private static function refreshAccessToken($refreshToken)
    {
        $session = new SpotifyWebAPI\Session(
            self::$client_id,
            self::$client_secret,
            self::$redirect_uri
        );

        try {
            $session->refreshAccessToken($refreshToken);

            $_SESSION['spotify_access_token'] = $session->getAccessToken();
            $_SESSION['spotify_refresh_token'] = $session->getRefreshToken() ?? $refreshToken; // Use existing refresh token if not updated
            $_SESSION['spotify_token_expiration'] = time() + $session->getExpiresIn();

            return $_SESSION['spotify_access_token'];
        } catch (SpotifyWebAPI\SpotifyWebAPIException $e) {
            return self::login(); // Re-authenticate if refresh fails
        }
    }


    public static function getUser()
    {
        self::startSession();

        $accessToken = self::auth();
        if (!$accessToken) {
            return false;
        }

        $api = new SpotifyWebAPI\SpotifyWebAPI();
        $api->setAccessToken($accessToken);

        return $api;
    }

  
    // Fetch user details and display
    public static function getUserDetails()
    {
        self::startSession();
        $accessToken = $_SESSION['spotify_access_token'] ?? self::auth();

        $api = new SpotifyWebAPI\SpotifyWebAPI();
        $api->setAccessToken($accessToken);

        // Fetch and display user details
        print_r($api->me());
    }

    // Fetch all playlists of the authenticated user
    public static function loadPlaylists()
    {
        self::startSession();

        $accessToken = self::auth();
        if (!$accessToken) {
            return false;
        }

        $api = new SpotifyWebAPI\SpotifyWebAPI();
        $api->setAccessToken($accessToken);

        $response = $api->getMyPlaylists();
    }

    // Fetch details of a specific playlist by ID
    public static function loadPlaylistItem(string $playlistID = null)
    {
        self::startSession();

        if (!isset($_GET['spotify_playlist_id']) || empty($_GET['spotify_playlist_id'])) {
            if ($playlistID == null) {
                echo "Playlist ID empty!";
                return false;
            }else{
                $playlistID = $playlistID;
            }
        } else {
            $playlistID = $_GET['spotify_playlist_id'];
        }

        $accessToken = self::auth();


        if (!$accessToken) {
            return false;
        }

        $api = new SpotifyWebAPI\SpotifyWebAPI();
        $api->setAccessToken($accessToken);

        $response = $api->getPlaylist($playlistID, [
            'fields' => 'tracks.items(track(name,href,artists(name,href)))'
        ]);

        $playlistData = [];

        if (isset($response->tracks->items)) {
            foreach ($response->tracks->items as $item) {
                $trackName = $item->track->name;
                $artistName = $item->track->artists[0]->name;

                $playlistData[] = [
                    'track' => $trackName,
                    'artist' => $artistName
                ];
            }
        }
        return $playlistData;
    }

    public static function createPlaylist(): mixed
    {
        self::startSession();

        // Get query parameters for playlist creation
        $name = $_GET['name'] ?? 'New Playlist by PlayeR!'; //Name of your Playlist
        $collaborative = $_GET['collaborative'] ?? true; //Allow others contribute to the playlist
        $description = $_GET['description'] ?? "Converted by player2"; //Description
        $public = $_GET['public'] ?? true;

        $options = [
            'name' => $name,
            'collaborative' => $collaborative,
            'description' => $description,
            'public' => $public
        ];

        // Get the current authenticated user
        $api = self::getUser();
        $user = $api->me();
        $userId = $user->id;

        try {
            // Create the playlist
            $response = $api->createPlaylist($userId, $options);
            
            return $response->id;
        } catch (SpotifyWebAPIException $e) {
            // Handle error (e.g., log it or return an error response)
            // if ($e->getCode() == 429) {
            //     return json_encode(array("status" => 0 , "message" => $e->getMessage() ));
            // }
            return json_encode(array("status" => 0 , "message" => $e->getMessage() ));

        }
    }
    public static function isSpotifyPlaylistLink($url)
    {
        // $pattern = "/^https:\/\/open\.spotify\.com\/playlist\/([a-zA-Z0-9]{22})(\?.*)?$/";
        // $pattern = "/^https:\/\/open\.spotify\.com\/playlist\/[a-zA-Z0-9]+(\?.*)?$/";
        $pattern = "/^https:\/\/open\.spotify\.com\/playlist\/([a-zA-Z0-9]+)(\?.*)?$/";
        
        return preg_match($pattern, trim($url)) === 1;
    }

    // Function to extract the playlist ID from a valid Spotify playlist link
    public static function extractPlaylistIDFromSpotifyLink($url)
    {
        // Regex to match Spotify playlist URL
        // $pattern = "/^https:\/\/open\.spotify\.com\/playlist\/([a-zA-Z0-9]{22})\?si=[a-zA-Z0-9]{22}$/";
        $pattern = "/^https:\/\/open\.spotify\.com\/playlist\/([a-zA-Z0-9]+)(\?.*)?$/";


        if (preg_match($pattern, $url, $matches)) {
            return $matches[1]; // Return the extracted playlist ID
        } else {
            return false; // Return false if the URL is not valid
        }
    }

    public static function getSpotifyURIsOfRandomPlaylist(array $playlist)
    {
        $spotifyURIs = [];  // Array to store the Spotify URIs
        $numberOfTracks = count($playlist);  // Total number of tracks
        $fails = 0;  // Track number of failures
        $numberOfURIObtained = 0;  // Tracks with obtained URIs
        $maxRetries = 3;  // Max retries for each search
        $left = $numberOfTracks;  // Tracks left to process

        $api = self::getUser();  // Get Spotify API instance

        // Loop through each track in the playlist
        foreach ($playlist as $item) {
            $track = $item['track'];
            $artist = $item['artist'];
            $query = "track:\"$track\" artist:\"$artist\"";  // Search query

            $options = [
                'limit' => 1,
                'offset' => 0,
            ];

            $retries = 0;  // Number of retries for each search
            $success = false;

            // Retry loop
            while ($retries <= $maxRetries && !$success) {
                $retries++;  // Increment retry count
                try {
                    $response = $api->search($query, ['track', 'album'], $options);

                    // If the response has tracks
                    if (isset($response->tracks->items)) {
                        foreach ($response->tracks->items as $trackItem) {
                            $spotifyURIs[] = $trackItem->uri;  // Add track URI to the array
                        }
                    }

                    // Mark as successful and break the loop
                    $success = true;
                    $numberOfURIObtained++;

                    // Update progress
                    $left = $numberOfTracks - ($numberOfURIObtained + $fails);
                    // echo json_encode([
                    //     "progress" => $numberOfURIObtained,
                    //     "left" => $left,
                    //     "fails" => $fails,
                    //     "rate-limited" => 0,
                    //     "uris" => $spotifyURIs
                    // ]); // Progress report
                    sleep(0.6);  // Sleep to prevent rate limiting

                } catch (SpotifyWebAPIException $e) {
                    // Handle rate limit (HTTP 429)
                    if ($e->getCode() == 429) {
                        // echo json_encode([
                        //     "progress" => $numberOfURIObtained,
                        //     "left" => $left,
                        //     "fails" => $fails,
                        //     "rate-limited" => 1
                        // ]); // Progress report
                        sleep(5);  // Sleep longer for rate limit
                    } else {
                        // Handle other errors
                        if ($retries == $maxRetries) {
                            $fails++;  // Increment failure count
                            // echo json_encode([
                            //     "progress" => $numberOfURIObtained,
                            //     "left" => $left,
                            //     "fails" => $fails,
                            //     "rate-limited" => 0
                            // ]); // Progress report
                        }
                    }
                }
            }
        }

        return $spotifyURIs;  // Return the array of Spotify URIs
    }


}

