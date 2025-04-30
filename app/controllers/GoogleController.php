<?php

namespace app\Controllers;

use Google_Client;
use Google_Service_YouTube;
use Google_Service_Exception;
use Google_Service_YouTube_Playlist;
use Google_Service_YouTube_PlaylistSnippet;
use Google_Service_YouTube_PlaylistStatus;

use app\Controllers\PlayeRController;

class GoogleController
{
    public static function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            // ini_set('session.gc_maxlifetime', 3600);
            session_start();
        }
    }

    public static function auth()
    {
        self::startSession();

        // Check if user has a valid token in session
        if (!empty($_SESSION['youtube_token'])) {
            // var_dump($_SESSION['youtube_token']);
            $client = new Google_Client();
            $client->setClientId($_ENV['GOOGLE_CLIENT_ID'] ?? $_SERVER['GOOGLE_CLIENT_ID']);
            $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET'] ?? $_SERVER['GOOGLE_CLIENT_SECRET']);
            $client->setRedirectUri('https://player-backend-qz31.onrender.com/youtube/login');
            $client->addScope('https://www.googleapis.com/auth/youtube');
            $client->setAccessType('offline');
            $client->setPrompt('consent');

            $client->setAccessToken($_SESSION['youtube_token']['access_token']);
            if ($client->isAccessTokenExpired()) {
            // if ((intval($_SESSION['youtube_token']['created']) + intval($_SESSION['youtube_token']['expires_in'])) < time() ) {
                if (isset($_SESSION['youtube_token']['refresh_token'])) {
                    $refreshToken = $_SESSION['youtube_token']['refresh_token'];
                    $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
                    
                    if (!isset($newToken['error'])) {
                        // Merge new token with refresh token if missing
                        if (!isset($newToken['refresh_token'])) {
                            $newToken['refresh_token'] = $refreshToken;
                        }
                        $_SESSION['youtube_token'] = $newToken;
                        $client->setAccessToken($newToken['access_token']);
                        return header('Location:https://player-frp1.onrender.com/redirectYoutubeLogin?setCookie=playeRCookieYT&tokenTime=' . ($_SESSION['youtube_token']['created']  + $_SESSION['youtube_token']['expires_in']));
                        

                    }
                }
            } else {
                // Token is valid, no need to redirect
                return header('Location:https://player-frp1.onrender.com/redirectYoutubeLogin?setCookie=playeRCookieYT&tokenTime=' . ($_SESSION['youtube_token']['created'] + $_SESSION['youtube_token']['expires_in']));
            }
        }

        // If no valid token, proceed with auth URL redirect
        $client = new Google_Client();
        $client->setClientId($_ENV['GOOGLE_CLIENT_ID'] ?? $_SERVER['GOOGLE_CLIENT_ID']);
        $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET'] ?? $_SERVER['GOOGLE_CLIENT_SECRET']);
        $client->setRedirectUri('https://player-backend-qz31.onrender.com/youtube/login');
        $client->addScope('https://www.googleapis.com/auth/youtube');
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $authUrl = $client->createAuthUrl();

        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Store redirect URI dynamically
        $_SESSION['youtube_original_uri'] = ($path == '/youtube/auth')
            ? "https://player-frp1.onrender.com/redirectYoutubeLogin?setCookie=playeRCookieYT&tokenTime="
            : $_SERVER['REQUEST_URI'];

        header("Location: $authUrl");
        exit;
    }

    public static function login()
    {
        self::startSession();

        if (!isset($_GET['code'])) {
            die('Authorization code missing.');
        }

        $client = new Google_Client();
        $client->setClientId($_ENV['GOOGLE_CLIENT_ID'] ?? $_SERVER['GOOGLE_CLIENT_ID']);
        $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET'] ?? $_SERVER['GOOGLE_CLIENT_SECRET']);
        $client->setRedirectUri('https://player-backend-qz31.onrender.com/youtube/login');
        // $client->setRedirectUri('http://localhost:8001/youtube/login');
        

        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

        if (isset($token['error'])) {
            die('Failed to get access token: ' . $token['error_description']);
        }

        $_SESSION['youtube_token'] = $token;

        if (isset($_SESSION['youtube_original_uri']) && $_SESSION['youtube_original_uri'] != "/youtube/auth") {
            $original_uri = $_SESSION['youtube_original_uri'];
            unset($_SESSION['youtube_original_uri']);
            // var_dump($token);

            return header('Location:'. $original_uri .  ($_SESSION['youtube_token']['created'] + $_SESSION['youtube_token']['expires_in']));
        } else {
            return header('Location:https://player-frp1.onrender.com/redirectYoutubeLogin?setCookie=playeRCookieYT&tokenTime=' . ($_SESSION['youtube_token']['created'] + $_SESSION['youtube_token']['expires_in']));
        }
    }

    public static function getUser()
    {
        self::startSession();

        if (!isset($_SESSION['youtube_token'])) {
            self::auth();
            exit;
        }
        $client = new Google_Client();
        $client->setClientId($_ENV['GOOGLE_CLIENT_ID'] ?? $_SERVER['GOOGLE_CLIENT_ID']);
        $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET'] ?? $_SERVER['GOOGLE_CLIENT_SECRET']);
        $client->setRedirectUri('https://player-backend-qz31.onrender.com/youtube/login');
        $client->addScope('https://www.googleapis.com/auth/youtube');
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $client->setAccessToken($_SESSION['youtube_token']['access_token']);


        if ($client->isAccessTokenExpired()) {
            if (isset($_SESSION['youtube_token']['refresh_token'])) {
                $refreshToken = $_SESSION['youtube_token']['refresh_token'];
                $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

                if (isset($newToken['error'])) {
                    // Refresh failed, force re-authentication
                    return self::auth();
                    // exit;
                }

                // Merge new token with refresh token if missing
                if (!isset($newToken['refresh_token'])) {
                    $newToken['refresh_token'] = $refreshToken;
                }

                $_SESSION['youtube_token'] = $newToken;
                $client->setAccessToken($newToken['access_token']);
            } else {
                // No refresh token, force re-authentication
                return self::auth();
                // exit;
            }
        }

        return $client;
    }


    public static function isYoutubePlaylistLink($url)
    {
        // return preg_match('/^https:\/\/music\.youtube\.com\/playlist\?list=[\w-]+/', $url) === 1;

        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        // Check if both 'list' and 'si' exist
        if (isset($params['list']) && isset($params['si'])) {
            return true;
        } else {
            return false;
        }
    }

    // public static function extractPlaylistIDFromYoutubeLink(string $playlistURL)
    // {
    //     parse_str(parse_url($playlistURL, PHP_URL_QUERY), $queryParams);
    //     $playlistId = $queryParams['list'] ?? null;

    //     if (!$playlistId) {
    //         return false;
    //     } else {
    //         return $playlistId;
    //     }
    // }

    public static function extractPlaylistIDFromYoutubeLink($playlistURL)
    {
        // Regex to match Spotify playlist URL
        // $pattern = "/^https:\/\/open\.spotify\.com\/playlist\/([a-zA-Z0-9]{22})\?si=[a-zA-Z0-9]{22}$/";
        // $pattern = "/^https:\/\/open\.spotify\.com\/playlist\/([a-zA-Z0-9]+)(\?.*)?$/";
        $pattern = '/list=([^&]+)/';

        if (preg_match($pattern, $playlistURL, $matches)) {
            return $matches[1]; // Return the extracted playlist ID
        } else {
            return false; // Return false if the URL is not valid
        }
    }

    public static function loadPlaylists()
    {
        $client = self::getUser();
        

        $youtube = new Google_Service_YouTube($client);

        try {
            $playlistsResponse = $youtube->playlists->listPlaylists('id,snippet', [
                'mine' => true,
                'maxResults' => 10
            ]);

            // foreach ($playlistsResponse['items'] as $playlist) {
            //     echo "Playlist: " . $playlist['snippet']['title'] . " (ID: " . $playlist['id'] . ")<br>";
            // }
        } catch (\Exception $e) {
            die('Error fetching playlists: ' . $e->getMessage());
        }
    }

    public static function loadPlaylistItem(string $playlistID = null)
    {
        if (!isset($_GET['youtube_playlist_id']) || empty($_GET['youtube_playlist_id'])) {
            if ($playlistID == null) {
                echo "Playlist ID empty! \n Please provide a valid playlist ID.";
                return false;
            }
        } else {
            $playlistID = $_GET['youtube_playlist_id'];
        }

        $client = self::getUser();
        $youtube = new Google_Service_YouTube($client);

        try {
            $params = [
                'playlistId' => $playlistID,
                'part' => 'snippet,contentDetails',
                'maxResults' => 100 // Adjust as needed
            ];

            $response = $youtube->playlistItems->listPlaylistItems('snippet,contentDetails', $params);

            $playlistData = [];

            if (isset($response['items'])) {
                foreach ($response['items'] as $item) {
                    $artist = preg_replace("/\s*- Topic$/i", "", $item['snippet']['videoOwnerChannelTitle']);
                    $playlistData[] = [
                        'track' => $item['snippet']['title'],
                        'artist' => $artist
                    ];
                }
            }

            return $playlistData;
        } catch (Google_Service_Exception $e) {
            return 'Error: ' . $e->getMessage();
        } catch (\Exception $e) {
            return 'General Error: ' . $e->getMessage();
        }
    }

    public static function createPlaylist()
    {
        self::startSession();

        $name = $_GET['name'] ?? 'new_playlist';
        $description = $_GET['description'] ?? 'created by playeR2';

        // Get the authenticated user client
        $client = self::getUser();

        // Initialize the YouTube service
        $youtube = new Google_Service_YouTube($client);

        try {
            // Create a new playlist
            $playlist = new Google_Service_YouTube_Playlist();
            $playlistSnippet = new Google_Service_YouTube_PlaylistSnippet();
            $playlistStatus = new Google_Service_YouTube_PlaylistStatus();

            // Set the snippet details for the playlist (title and description)
            $playlistSnippet->setTitle($name);
            $playlistSnippet->setDescription($description);

            // Set the status (public, private, or unlisted)
            $playlistStatus->setPrivacyStatus('private'); // Can be 'private', 'public', or 'unlisted'

            // Apply the snippet and status to the playlist
            $playlist->setSnippet($playlistSnippet);
            $playlist->setStatus($playlistStatus);

            // Insert the playlist using the YouTube service
            $playlistResponse = $youtube->playlists->insert('snippet,status', $playlist);

            // Return the playlist ID
            return $playlistResponse->getId();
        } catch (Google_Service_Exception $e) {
            return 'Error: ' . $e->getMessage();
        } catch (\Exception $e) {
            return 'General Error: ' . $e->getMessage();
        }
    }

    public static function deleteTracksFromPlaylist(array $playlistItemIds)
    {
        // echo "<pre>" . json_encode($playlistItemIds, JSON_PRETTY_PRINT) . "</pre>";

        // Get the authenticated user and the YouTube client
        $client = GoogleController::getUser();
        $youtube = new Google_Service_YouTube($client);

        // Initialize tracking variables
        $totalItems = count($playlistItemIds);
        $deletedCount = 0;
        $failedCount = 0;
        $rateLimited = 0;

        // Set the max retries and rate limit delay
        $maxRetries = 3; // Set the maximum number of retries per item
        $rateLimitDelay = 0.6; // Set the delay to avoid rate limiting (in seconds)

        foreach ($playlistItemIds as $playlistItemId) {
            $retries = 0;
            $success = false;

            while ($retries <= $maxRetries && !$success) {
                $retries++; // Update retry count

                try {
                    // Attempt to delete the playlist item
                    $youtube->playlistItems->delete($playlistItemId);

                    // Track the number of successful deletions
                    $deletedCount++;
                    $success = true; // Mark the item as successfully deleted
                } catch (Google_Service_Exception $e) {
                    // If we hit a Google API error, track it as a failure
                    $failedCount++;
                    // echo 'Error: ' . $e->getMessage() . " for Playlist Item ID: $playlistItemId\n";
                } catch (\Exception $e) {
                    // If we hit any other error, track it as a failure
                    $failedCount++;
                    // echo 'Error: ' . $e->getMessage() . " for Playlist Item ID: $playlistItemId\n";
                }

                // Provide a progress update after each deletion attempt
                $progress = [
                    'deleted' => $deletedCount,
                    'failed' => $failedCount,
                    'lefttt' => $totalItems - ($deletedCount + $failedCount),
                    'rate_limited' => $rateLimited,
                ];

                sleep($rateLimitDelay); // Wait before the next request to avoid rate-limiting
            }
        }

        // Final result after attempting all deletions
        return [
            'total' => $totalItems,
            'deleted' => $deletedCount,
            'failed' => $failedCount,
            'rate_limited' => $rateLimited,
        ];
    }

    public static function getYoutubeURIsOfRandomPlaylist(array $playlist)
    {
        $numberOfTracks = count($playlist); // Count the number of tracks
        $youtubeURIs = []; // Store the obtained YouTube URIs
        $fails = 0; // Track failed attempts
        $maxRetries = 3; // Define max retries in case of failure
        $left = $numberOfTracks; // Tracks left to process

        $client = GoogleController::getUser();
        $youtube = new Google_Service_YouTube($client);

        foreach ($playlist as $item) {
            $track = PlayeRController::sanitizeTrackName($item['track']);
            $artist = $item['artist'];

            $retries = 0;
            $success = false;

            // Build the query
            $query = urlencode($artist . " " . $track);

            // Perform the search
            while ($retries < $maxRetries && !$success) {
                $retries++; // Increment retry count

                try {
                    $searchResponse = $youtube->search->listSearch('id,snippet', [
                        'q' => $query,
                        'maxResults' => 1, // You can change this number to get more results
                        'type' => 'video', // We are searching for videos
                    ]);

                    if (isset($searchResponse['items'])) {
                        foreach ($searchResponse['items'] as $item) {
                            $music = $item['id']['videoId']; // This is the unique identifier
                            $youtubeURIs[] = $music;
                            $success = true; // Mark as success
                        }
                    } else {
                        $fails++; // Increment fails if no video is found
                    }

                    $left = $numberOfTracks - (count($youtubeURIs) + $fails);
                    sleep(0.6); // Prevent overloading YouTube servers and avoid rate-limiting

                } catch (Google_Service_Exception $e) {
                    $fails++; // Increment fails on Google service error
                    break;
                } catch (\Exception $e) {
                    $fails++; // Increment fails on general error
                    break;
                }
            }
        }

        return $youtubeURIs; // Return the list of YouTube URIs
    }
}
