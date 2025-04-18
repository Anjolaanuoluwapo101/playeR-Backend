<?php

namespace app\Controllers;


use app\Controllers\GoogleController;
use app\Controllers\SpotifyController;
use app\Models\User;
use SpotifyWebAPI\SpotifyWebAPIException;

use Google_Service_YouTube;
use Google_Service_Exception;
use Google_Service_YouTube_PlaylistItem;
use Google_Service_YouTube_PlaylistItemSnippet;
use Google_Service_YouTube_ResourceId;

class PlayeRController
{
    private static $googleInstance = null;
    private static $spotifyInstance = null;

    private static function getGoogleInstance()
    {
        if (self::$googleInstance === null) {
            self::$googleInstance = new GoogleController();
        }
        return self::$googleInstance;
    }

    private static function getSpotifyInstance()
    {
        if (self::$spotifyInstance === null) {
            self::$spotifyInstance = new SpotifyController();
        }
        return self::$spotifyInstance;
    }

    public static $maxRetries = 5;  // Max retries count
    public static $fails = 0; //number of search failures
    public static $left = 0; //left,to keep track of progress
    protected static $spotifyURIObtained = [];
    protected static $youtubeURIObtained = [];
    public static $numberOfTracks; //get the number of search items to keep track of progress report
    public static $numberOfURIObtained = 0; //keep track of the number of spotify URI obtained

    public static function index()
    {
        echo "Welcome to the Dashboard!";
    }

    public static function comparePlaylists($originalPlaylistData, $updatedPlaylistData)
    {
        $newTracks = [];
        $deletedTracks = [];

        // Compare new data to original data (find tracks present in updated data but not in original)
        foreach ($updatedPlaylistData as $updatedTrack) {
            $existsInOriginal = false;
            foreach ($originalPlaylistData as $originalTrack) {
                if ($updatedTrack['track'] === $originalTrack['track'] && $updatedTrack['artist'] === $originalTrack['artist']) {
                    $existsInOriginal = true;
                    break;
                }
            }

            // If the track doesn't exist in the original playlist, it's a new track
            if (!$existsInOriginal) {
                $newTracks[] = $updatedTrack;
            }
        }

        // Compare original data to new data (find tracks present in original data but not in updated)
        foreach ($originalPlaylistData as $originalTrack) {
            $existsInUpdated = false;
            foreach ($updatedPlaylistData as $updatedTrack) {
                if ($originalTrack['track'] === $updatedTrack['track'] && $originalTrack['artist'] === $updatedTrack['artist']) {
                    $existsInUpdated = true;
                    break;
                }
            }

            // If the track doesn't exist in the updated playlist, it's a deleted track
            if (!$existsInUpdated) {
                $deletedTracks[] = $originalTrack;
            }
        }

        // Return both new and deleted tracks
        return [
            'newTracks' => $newTracks,
            'deletedTracks' => $deletedTracks
        ];
    }


    public static function getYoutubeURIsOfSpotifyPlaylist(string $playlistID = null)
    {
        $spotifyInstance = self::getSpotifyInstance();
        $googleInstance = self::getGoogleInstance();

        $spotifyPlaylist = $spotifyInstance::loadPlaylistItem($playlistID);
        self::$numberOfTracks = count($spotifyPlaylist);

        $client = $googleInstance::getUser();
        $youtube = new Google_Service_YouTube($client);

        foreach ($spotifyPlaylist as $item) {
            $track = $item['track'];
            $artist = $item['artist'];

            $retries = 0;
            $success = false;

            // Build the query
            $query = urlencode($track . " " . $artist);

            // Perform the search
            while ($retries <= self::$maxRetries && !$success) {
                $retries++; //update retry count first

                try {

                    $searchResponse = $youtube->search->listSearch('id,snippet', [
                        'q' => $query,
                        'maxResults' => 1, // You can change this number to get more results
                        'type' => 'video', // We are searching for videos
                    ]);


                    foreach ($searchResponse['items'] as $item) {
                        $music = $item['id']['videoId']; // This is the unique identifier
                        self::$youtubeURIObtained[] = $music;
                    }

                    // If successful, mark as success and break the loop
                    $success = true;
                    self::$numberOfURIObtained++;

                    self::$left = self::$numberOfTracks - (self::$numberOfURIObtained + self::$fails);

                    // echo json_encode(["progress" => self::$numberOfURIObtained, "left" => self::$left, "fails" => self::$fails, "rate-limited" => 0, "uris" => self::$spotifyURIObtained]); //progress report
                    sleep(0.6); //prevent overloading of the youtube servers,to prevent rate limiting

                } catch (Google_Service_Exception $e) {
                    return 'Error: ' . $e->getMessage();
                } catch (\Exception $e) {
                    return 'Error: ' . $e->getMessage();
                }
            }
        }
        // echo "<pre>" . json_encode(self::$youtubeURIObtained, JSON_PRETTY_PRINT) . "</pre>";
        return self::$youtubeURIObtained;
    }


    public static function getSpotifyURIsOfYoutubePlaylist(string $playlistId)
    {
        $googleInstance = self::getGoogleInstance();
        $spotifyInstance = self::getSpotifyInstance();

        $youtubePlaylist = $googleInstance::loadPlaylistItem($playlistId); //fetches an array of associative arrays,each assoc arrays  of the track and  artists
        self::$numberOfTracks = count($youtubePlaylist);

        $api = $spotifyInstance::getUser(); // Create the API instance

        // Loop through each track in the playlist
        foreach ($youtubePlaylist as $item) {
            $track = $item['track'];
            $artist = $item['artist'];
            $query = "track:\"$track\" artist:\"$artist\"";
            $options = [
                'limit' => 1,
                'offset' => 0,
            ];

            $retries = 0; // number of retries for each search..
            $success = false;

            while ($retries <= self::$maxRetries && !$success) {
                $retries++; //update retry count first
                try {

                    $response = $api->search($query, ['track', 'album'], $options);

                    if (isset($response->tracks->items)) {
                        foreach ($response->tracks->items as $item) {
                            self::$spotifyURIObtained[] = $item->uri;
                        }
                    }

                    // If successful, mark as success and break the loop
                    $success = true;
                    self::$numberOfURIObtained++;

                    self::$left = self::$numberOfTracks - (self::$numberOfURIObtained + self::$fails);

                    // echo json_encode(["progress" => self::$numberOfURIObtained, "left" => self::$left, "fails" => self::$fails, "rate-limited" => 0, "uris" => self::$spotifyURIObtained]); //progress report
                    sleep(0.6); //prevent overloading of the spotify servers,to prevent rate limiting

                } catch (SpotifyWebAPIException $e) {
                    // Check for rate limit (HTTP 429)
                    if ($e->getCode() == 429) {
                        //if it is a rate limit error...
                        // echo json_encode(["progress" => self::$numberOfURIObtained, "left" => self::$left, "fails" => self::$fails, "rate-limited" => 1]); //progress report
                        sleep(5); //sleep for longer period to deal with chronic rate limit
                    } else {
                        // Handle other errors (non-rate limit errors)
                        //this probrably means that other errors was encounted
                        if ($retries == self::$maxRetries) {
                            //if retry limit for a single search has been hit,
                            //count that as a fail and move on
                            // $fails++;
                            // echo json_encode(["progress" => self::$numberOfURIObtained, "left" => self::$left, "fails" => self::$fails, "rate-limited" => 0]); //progress report
                            continue;
                        }
                    }
                }
            }
        }

        //once for each loop done $spotifyURI should be populated already...with a list of uris
        return self::$spotifyURIObtained;
    }

    public static function addTracksToSpotifyPlaylist($playlistID, $trackUris, $position = null)
    {
        $spotifyInstance = self::getSpotifyInstance();
        $spotifyInstance::startSession();

        $api = $spotifyInstance::getUser(); // Get Spotify API instance

        $options = [];
        if($position != null){
            $options['position'] = $position;
        }

        try {
            // Add tracks to the playlist
            $response = $api->addPlaylistTracks($playlistID, $trackUris, $options);
            // Return the new snapshot ID
            return $response;
            if ($response) {
                return true;
            }
        } catch (SpotifyWebAPIException $e) {
            return false;
            // return json_encode(array("status" => 0 , "message" => "Spotify playlist created but tracks not added!" ));
        }
    }

    public static function addTracksToYoutubePlaylist($playlistID, $videoIDs)
    {
        $googleInstance = self::getGoogleInstance();
        $client = $googleInstance::getUser();

        $youtube = new Google_Service_YouTube($client);

        foreach ($videoIDs as $videoID) {

            try {


                // Create the resource for adding to the playlist
                $resource = new Google_Service_YouTube_PlaylistItem();
                $snippet = new Google_Service_YouTube_PlaylistItemSnippet();

                // Set the playlist ID
                $snippet->setPlaylistId($playlistID);

                // Create the resourceId for the video and set it correctly
                $resourceId = new Google_Service_YouTube_ResourceId();
                $resourceId->setKind('youtube#video');
                $resourceId->setVideoId($videoID);

                // Set the resourceId on the snippet
                $snippet->setResourceId($resourceId);

                // Add the snippet to the resource
                $resource->setSnippet($snippet);


                // Insert the video into the playlist
                $response = $youtube->playlistItems->insert('snippet', $resource);

                sleep(1); //prevent rate limiting

            } catch (Google_Service_Exception $e) {
                echo $e->getMessage();
                return false;
            } catch (\Exception $e) {
                echo $e->getMessage();
                return false;
            }
        }
        return true;
    }

    public static function spotifyToYoutube()
    {
        $spotifyInstance = self::getSpotifyInstance();
        $googleInstance = self::getGoogleInstance();

        // Validate and get Spotify Playlist ID
        if (empty($_GET['param'])) {
            echo json_encode(["status" => 0, "message" => "Invalid Spotify Parameter!"]);
            return;
        }
        $spotifyPlaylistLink = $_GET['param'];
        if ($spotifyInstance::isSpotifyPlaylistLink($spotifyPlaylistLink)) {
            $spotifyPlaylistID = $spotifyInstance::extractPlaylistIDFromSpotifyLink($spotifyPlaylistLink);
            // Optionally, echo the ID (if needed for debugging)
            // echo $spotifyPlaylistID;
        } else {
            $spotifyPlaylistID = $spotifyPlaylistLink;
        }

        // Process Existing YouTube Playlist if provided
        if (!empty($_GET['existing_param'])) {
            // Determine if the parameter is a valid YouTube URL; if so, extract the ID.
            if ($googleInstance::isYoutubePlaylistLink($_GET['existing_param'])) {
                $existingYoutubePlaylist = $googleInstance::extractPlaylistIDFromYoutubeLink($_GET['existing_param']);
            } else {
                $existingYoutubePlaylist = $_GET['existing_param'];
            }

            // Load both playlists for comparison
            $originalPlaylistData = $googleInstance::loadPlaylistItem($existingYoutubePlaylist);
            $updatedPlaylistData = $spotifyInstance::loadPlaylistItem($spotifyPlaylistID);

            $response = self::comparePlaylists($originalPlaylistData, $updatedPlaylistData);
            $deletedTracks = $response['deletedTracks'] ?? null;
            $newTracks = $response['newTracks'] ?? null;

            $_SESSION['output']['Tracks Deleted'] = $deletedTracks ?? [];
            $_SESSION['output']['New Tracks Detected'] = $newTracks ?? [];

            // If there are tracks to delete, try to delete them on YouTube
            if (!empty($deletedTracks)) {
                try {
                    $deletedURIs = $googleInstance::getYoutubeURIsOfRandomPlaylist($deletedTracks);
                    $response = $googleInstance::deleteTracksFromPlaylist($deletedURIs);
                } catch (SpotifyWebAPIException $e) {
                    $_SESSION['output']['result'] = ["status" => 0, "message" => $e->getMessage()];
                    echo json_encode($_SESSION['output']);
                    return;
                }
            }
            // Get URIs for new tracks (or empty array if none)
            $youtubeURIs = !empty($newTracks)
                ? $googleInstance::getYoutubeURIsOfRandomPlaylist($newTracks)
                : [];
        } else {
            // No existing YouTube playlist provided: create a new playlist.
            try {
                $updatedPlaylistData = $spotifyInstance::loadPlaylistItem($spotifyPlaylistID);
                $_SESSION['output']['Tracks Deleted'] = $deletedTracks ?? [];
                $_SESSION['output']['New Tracks Detected'] = $updatedPlaylistData ?? [];

                $existingYoutubePlaylist = $googleInstance::createPlaylist();
                $youtubeURIs = self::getYoutubeURIsOfSpotifyPlaylist($spotifyPlaylistID);
            } catch (\Exception $e) {
                $_SESSION['output']['result'] = ["status" => 0, "message" => $e->getMessage()];
                echo json_encode($_SESSION['output']);
                return;
            }
        }

        try {
            // Add tracks to the YouTube playlist
            $response = self::addTracksToYoutubePlaylist($existingYoutubePlaylist, $youtubeURIs);
            if ($response == false) {
                $_SESSION['output']['result'] = ["status" => 0, "message" => "Youtube playlist created but tracks not added!"];
                echo json_encode($_SESSION['output']);
            } else {
                $_SESSION['output']['result'] = ["status" => 1, "message" => "Youtube Playlist Created and Updated"];
                header("Content-Type: application/json");
                echo json_encode($_SESSION['output']);
            }
        } catch (\Exception $e) {
            $_SESSION['output']['result'] = ["status" => 0, "message" => $e->getMessage()];
            echo json_encode($_SESSION['output']);
        }
    }

    public static function youtubeToSpotify()
    {
        $googleInstance = self::getGoogleInstance();
        $spotifyInstance = self::getSpotifyInstance();

        // Validate and get YouTube Playlist ID
        if (empty($_GET['param'])) {
            echo json_encode(["status" => 0, "message" => "Invalid Youtube Parameter!"]);
            return;
        }
        $youtubePlaylistLink = $_GET['param'];
        if ($googleInstance::isYoutubePlaylistLink($youtubePlaylistLink)) {
            $youtubePlaylistID = $googleInstance::extractPlaylistIDFromYoutubeLink($youtubePlaylistLink);
        } else {
            $youtubePlaylistID = $youtubePlaylistLink;
        }

        // Process Existing Spotify Playlist if provided
        if (!empty($_GET['existing_param'])) {
            if ($spotifyInstance::isSpotifyPlaylistLink($_GET['existing_param'])) {
                $existingSpotifyPlaylist = $spotifyInstance::extractPlaylistIDFromSpotifyLink($_GET['existing_param']);
            } else {
                $existingSpotifyPlaylist = $_GET['existing_param'];
            }

            // Load playlists for comparison
            $originalPlaylistData = $spotifyInstance::loadPlaylistItem($existingSpotifyPlaylist);
            $updatedPlaylistData = $googleInstance::loadPlaylistItem($youtubePlaylistID);

            $response = self::comparePlaylists($originalPlaylistData, $updatedPlaylistData);
            $deletedTracks = $response['deletedTracks'] ?? null;
            $newTracks = $response['newTracks'] ?? null;

            $_SESSION['output']['Tracks Deleted'] = $deletedTracks ?? [];
            $_SESSION['output']['New Tracks Detected'] = $newTracks ?? [];

            // Delete tracks from Spotify if necessary
            if (!empty($deletedTracks)) {
                try {
                    $deletedTracksURIs = $spotifyInstance::getSpotifyURIsOfRandomPlaylist($deletedTracks);
                    $deletedURIs = [
                        "tracks" => array_map(function ($trackUri) {
                            return ["uri" => $trackUri];
                        }, $deletedTracksURIs)
                    ];

                    $api = $spotifyInstance::getUser();
                    $response = $api->deletePlaylistTracks($existingSpotifyPlaylist, $deletedURIs);
                } catch (SpotifyWebAPIException $e) {
                    $_SESSION['output']['result'] = ["status" => 0, "message" => $e->getMessage()];
                    echo json_encode($_SESSION['output']);
                    return;
                }
            }
            $spotifyURIs = !empty($newTracks)
                ? $spotifyInstance::getSpotifyURIsOfRandomPlaylist($newTracks)
                : [];
        } else {
            // No existing Spotify playlist provided: create a new one.
            $updatedPlaylistData = $googleInstance::loadPlaylistItem($youtubePlaylistID);
            $_SESSION['output']['Tracks Deleted'] = $deletedTracks ?? [];
            $_SESSION['output']['New Tracks Detected'] = $updatedPlaylistData ?? [];

            $existingSpotifyPlaylist = $spotifyInstance::createPlaylist();
            $spotifyURIs = self::getSpotifyURIsOfYoutubePlaylist($youtubePlaylistID);
        }

        try {
            // Add tracks to the Spotify playlist
            $response = self::addTracksToSpotifyPlaylist($existingSpotifyPlaylist, $spotifyURIs);
            if ($response == false) {
                $_SESSION['output']['result'] = ["status" => 0, "message" => "Spotify playlist created but tracks not added!"];
                echo json_encode($_SESSION['output']);
            } else {
                $_SESSION['output']['result'] = ["status" => 1, "message" => "Spotify Playlist Created and Updated"];
                echo json_encode($_SESSION['output']);
            }
        } catch (SpotifyWebAPIException $e) {
            $_SESSION['output']['result'] = ["status" => 0, "message" => $e->getMessage()];
            echo json_encode($_SESSION['output']);
        }
    }
}
