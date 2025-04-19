<?php

namespace app\Controllers;

use Google_Client;
use Google_Service_YouTube;
use Google_Service_Exception;
use Google_Service_YouTube_Playlist;
use Google_Service_YouTube_PlaylistSnippet;
use Google_Service_YouTube_PlaylistStatus;


class GoogleController
{
    public static function startSession()
    {
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
    
        $params = [
            'client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? $_SERVER['GOOGLE_CLIENT_ID'],
            'redirect_uri' => 'https://player-backend-qz31.onrender.com/youtube/login',
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/youtube',
            'access_type' => 'offline',
            'prompt' => 'consent'
        ];
    
        $authUrl = "https://accounts.google.com/o/oauth2/auth?" . http_build_query($params);

        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    
        // Store redirect URI dynamically
        $_SESSION['youtube_original_uri'] = ($path == '/youtube/auth')
            ? "https://player-backend-qz31.onrender.com/redirectYoutubeLogin?setCookie=playeRCookieYT"
            : $_SERVER['REQUEST_URI'];

        // $_SESSION['youtube_original_uri'] = ($path == '/youtube/auth')
        //     ? "/youtube/auth"
        //     : $_SERVER['REQUEST_URI'];

    
        header("Location: $authUrl");
        exit;
    }

    public static function login()
    {
        self::startSession();

        if (!isset($_GET['code'])) {
            die('Authorization code missing.');
        }

        $postData = [
            'code' => $_GET['code'],
            'client_id' => $_ENV['GOOGLE_CLIENT_ID'],
            'client_secret' =>  $_ENV['GOOGLE_CLIENT_SECRET'],
            'redirect_uri' => 'https://player-backend-qz31.onrender.com/youtube/login',
            'grant_type' => 'authorization_code'
        ];

        $response = self::makeCurlRequest("https://oauth2.googleapis.com/token", $postData);
        if (!isset($response['access_token'])) {
            die('Failed to get access token.');
        }

        $_SESSION['youtube_access_token'] = $response['access_token'];
        $_SESSION['youtube_token_expiration'] = time() + $response['expires_in'];
        // $_SESSION['youtube_token_expiration'] = time() + 3600;
        

        if (!empty($response['refresh_token'])) {
            $_SESSION['youtube_refresh_token'] = $response['refresh_token'];
        }

        

        if (isset($_SESSION['youtube_original_uri'])  && $_SESSION['youtube_original_uri'] != "/youtube/auth") {
            $original_uri = $_SESSION['youtube_original_uri'];
            unset($_SESSION['youtube_original_uri']);
            header('Location:' . $original_uri);
        } else {
            header('Location:https://player-frp1.onrender.com/redirectYoutubeLogin?setCookie=playeRCookieYT&tokenTime='.$_SESSION['youtube_token_expiration']);
        }

    }

    public static function getUser()
    {
        self::startSession();

        if (!isset($_SESSION['youtube_access_token'])) {
            self::auth();
        }

        // Check if the token is expired
        if (time() >= $_SESSION['youtube_token_expiration']) {
            if (!isset($_SESSION['youtube_refresh_token'])) {
                return self::auth(); // No refresh token, force re-authentication
            }


            $postData = [
                'client_id' => $_ENV['GOOGLE_CLIENT_ID'] ,
                'client_secret' =>  $_ENV['GOOGLE_CLIENT_SECRET'] ,
                'refresh_token' => $_SESSION['youtube_refresh_token'],
                'grant_type' => 'refresh_token',
            ];

            $response = self::makeCurlRequest("https://oauth2.googleapis.com/token", $postData);
            if (!isset($response['access_token'])) {
                return self::auth(); // Failed to refresh, force re-auth
            }

            $_SESSION['youtube_access_token'] = $response['access_token'];
            $_SESSION['youtube_token_expiration'] = time() + $response['expires_in'];

            // Refresh token usually remains the same, but update it if needed
            if (!empty($response['refresh_token'])) {
                $_SESSION['youtube_refresh_token'] = $response['refresh_token'];
            }
        }

        $client = new Google_Client();
        // $client->setAuthConfig($_SERVER['DOCUMENT_ROOT'] . '/client_secret.json');
        $client->setAuthConfig(`{"web":{"client_id":"`.$_ENV['GOOGLE_CLIENT_SECRET'].`","project_id":"playerr-449906","auth_uri":"https://accounts.google.com/o/oauth2/auth","token_uri":"https://oauth2.googleapis.com/token","auth_provider_x509_cert_url":"https://www.googleapis.com/oauth2/v1/certs","client_secret":"`.$_ENV['GOOGLE_CLIENT_ID'].`","javascript_origins":["http://localhost:8000","https://player-backend-qz31.onrender.com"]}}`);
        $client->setAccessToken($_SESSION['youtube_access_token']);

        return $client;
    }

    private static function getChannelId($accessToken)
    {
        $response = self::makeCurlRequest(
            "https://www.googleapis.com/youtube/v3/channels?part=id&mine=true",
            [],
            $accessToken
        );

        return $response['items'][0]['id'] ?? null;
    }

    private static function makeCurlRequest($url, $postData = [], $accessToken = null)
    {
        $ch = curl_init($url);

        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        if ($accessToken) {
            $headers[] = "Authorization: Bearer $accessToken";
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (!empty($postData)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        }

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }






        
    


    public static function isYoutubePlaylistLink($url)
    {
        return preg_match('/^https:\/\/music\.youtube\.com\/playlist\?list=[\w-]+/', $url) === 1;
    }

    public static function extractPlaylistIDFromYoutubeLink(string $playlistURL)
    {
        parse_str(parse_url($playlistURL, PHP_URL_QUERY), $queryParams);
        $playlistId = $queryParams['list'] ?? null;

        if (!$playlistId) {
            return false;
        } else {
            return $playlistId;
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
                echo "Playlist ID empty!";
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
            $track = $item['track'];
            $artist = $item['artist'];

            $retries = 0;
            $success = false;

            // Build the query
            $query = urlencode($track . " " . $artist);

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


                    // // If a video is found, add the video ID to the URIs array
                    // if (isset($searchResponse['items'][0])) {
                    //     $music = $searchResponse['items'][0]['id']['videoId']; // This is the unique identifier
                    //     $youtubeURIs[] = $music;
                    //     $success = true; // Mark as success
                    // } else {
                    //     $fails++; // Increment fails if no video is found
                    // }

                    // Progress update
                    $left = $numberOfTracks - (count($youtubeURIs) + $fails);
                    // echo json_encode([
                    //     "progress" => count($youtubeURIs),
                    //     "left" => $left,
                    //     "fails" => $fails,
                    //     "uris" => $youtubeURIs
                    // ]); // Progress report
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


