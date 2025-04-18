<?php

namespace app\Controllers;

class GeminiController {
    protected $apiKey;
    private $baseUrl;


    public function __construct() {
        $this->baseUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateText";
        // echo  getenv('GEMINI_BASE_URL');
        $this->apiKey = "AIzaSyDXjNENzrJXe8pCJXqSHN-gyZ_Xtsa1E6Y";
    }

    // Helper function to send the request to the Gemini API
    private function sendRequest($data) {
        $url = $this->baseUrl . "?key=" . $this->apiKey;

        $jsonData = json_encode($data);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    // Function to generate text based on a prompt
    public function generateText($prompt) {
        // $data = [
        //     "contents" => [
        //         [
        //             "parts" => [
        //                 ["text" => $prompt]
        //             ]
        //         ]
        //     ]
        // ];

        $data = [
            "prompt" => [
                "text" => $prompt
            ]
        ];

        $response = $this->sendRequest($data);

        // Check if the response contains valid data
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return $response['candidates'][0]['content']['parts'][0]['text'];
        } else {
            // Handle error or invalid response
            return "Error: " . json_encode($response);
        }
    }
}
