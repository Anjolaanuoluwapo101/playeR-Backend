<?php
require '../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__,".env");
$dotenv->load();

require '../core/Router.php';
require '../app/controllers/PlayeRController.php';
require '../app/controllers/SpotifyController.php';
require '../app/controllers/GoogleController.php';
require '../app/controllers/GeminiController.php';
require '../app/models/Model.php';
require '../app/models/User.php';

use app\Controllers\SpotifyController;
use app\Controllers\GoogleController;
use app\Controllers\PlayeRController;
use app\Controllers\GeminiController;

header("Access-Control-Allow-Origin: http://localhost:8081"); // Allow all domains (Use specific domains in production)
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS"); // Allowed methods
header("Access-Control-Allow-Credentials:true");
header("Content-Type: application/json");
header("Access-Control-Allow-Headers: Content-Type, Authorization, Access-Control-Allow-Origin,Access-Control-Allow-Origin"); // Allowed headers

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

Router::get('/', function() {
    echo $_ENV['SPOTIFY_CLIENT_ID'];
});

Router::get('/spotify/login', function() {
    SpotifyController::login();
});
Router::get('/spotify/check_user', function() {
    SpotifyController::checkUser();
});
Router::get('/spotify/get_user_access_token', function() {
    SpotifyController::getUserAccessToken();
});
Router::get('/spotify/load_playlists', function() {
    SpotifyController::loadPlaylists();
});
Router::get('/spotify/load_playlist/item', function() {
    SpotifyController::loadPlaylistItem();
});
Router::get('/spotify/create_playlist', function() {
    SpotifyController::createPlaylist();
});



Router::get('/youtube/auth', function() {
    GoogleController::auth();
});
Router::get('/youtube/login', function() {
    GoogleController::login();
});
Router::get('/youtube/check_user', function() {
    GoogleController::checkUser();
});
Router::get('/youtube/load_playlists', function() {
    GoogleController::loadPlaylists();
});
Router::get('/youtube/load_playlist/item', function() {
    GoogleController::loadPlaylistItem();
});
Router::get('/youtube/create_playlist', function() {
    GoogleController::createPlaylist();
});





Router::get('/player/convertYoutubeToSpotify', function() {
    PlayeRController::youtubeToSpotify();
});
Router::get('/player/convertSpotifyToYoutube' , function(){
    PlayeRController::spotifyToYoutube();
});


//Gemini
Router::get('/gemini/ask' , function(){
    //Gemini class not static in nature,so let's initialize it
    $gemini = new GeminiController;
    echo $gemini->generateText("What's your name?");

});

Router::dispatch();