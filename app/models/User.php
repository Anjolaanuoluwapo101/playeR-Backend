<?php

namespace app\Models;

use app\Models\Model;

class User extends Model
{

    // Method to create a table for the User model (SQLite3 & MySQL compatibility)
    public function create()
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS records (
                id VARCHAR(255),
                email VARCHAR(255),
                blob_field BLOB
            );
        ";
        $this->query($sql);
    }

    public function idExists($id)
    {
        $sql = "SELECT COUNT(*) FROM records WHERE id = :id";
        $stmt = $this->query($sql, ['id' => $id]);
        return $stmt->fetchColumn() > 0;
    }

    // Method to get the blob field using the id
    public function getBlob($id)
    {
        $sql = "SELECT blob_field FROM records WHERE id = :id";
        $stmt = $this->query($sql, ['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Check if the blob is null or empty
        if ($row && ($row['blob_field'] === null || empty($row['blob_field']))) {
            return false; // Return false if blob is empty or null
        }

        if ($row == false) {
            return false;
        }

        return json_decode($row['blob_field'], true); // Decode the JSON data
    }

    // Method to store a new blob if the field is empty
    public function storeBlob($id, $email = null, $data)
    {
        // Encode the array into JSON format
        $jsonData = json_encode($data);



        // Check if the blob field is already populated
        $sql = "SELECT blob_field FROM records WHERE id = :id";
        $stmt = $this->query($sql, ['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row && ($row['blob_field'] === null || empty($row['blob_field']))) {
            // If blob is empty or null, insert the new blob data
            $sql = "INSERT INTO records (id, email, blob_field) VALUES (:id, :email, :blob_field)";
            $this->query($sql, ['id' => $id, 'email' => $email, 'blob_field' => $jsonData]);
        }
    }

    // Method to update an existing blob field by adding new tracks/artists
    public function updateBlob($id, $newData)
    {
        // Retrieve the current blob data
        try {

            // Initialize arrays for the lists
            $newItems = [];
            $removedItems = [];

            //dummy email
            $email = 'anjolaakinsoyinu@gmail.com';

            $currentBlob = $this->getBlob($id);
        } catch (\Exception $e) {
            $currentBlob = false;
        }

        // If there's no existing blob or it's empty, store the new data
        if ($currentBlob == false) {
            $this->storeBlob($id, $email, $newData);

            return [
                'newItems' => $newItems,
                'removedItems' => $removedItems
            ];
        }

        // Separate the newData and existing data
        foreach ($newData as $newTrack) {
            $exists = false;
            foreach ($currentBlob as $existingTrack) {
                // Check if the track already exists in the blob
                if ($existingTrack['track'] === $newTrack['track'] && $existingTrack['artist'] === $newTrack['artist']) {
                    $exists = true;
                    break;
                }
            }

            // Add to the newItems list if it's not already present in currentBlob
            if (!$exists) {
                $newItems[] = $newTrack;
            }
        }

        // Identify removed items from the currentBlob (i.e., not in newData)
        foreach ($currentBlob as $existingTrack) {
            $existsInNewData = false;
            foreach ($newData as $newTrack) {
                if ($newTrack['track'] === $existingTrack['track'] && $newTrack['artist'] === $existingTrack['artist']) {
                    $existsInNewData = true;
                    break;
                }
            }

            // Add to removedItems list if it's not in newData
            if (!$existsInNewData) {
                $removedItems[] = $existingTrack;
            }
        }

        // Merge new data with existing data, ensuring no duplicates
        $updatedBlob = array_merge($currentBlob, $newItems);

        // Encode the updated blob back to JSON
        $updatedBlobJson = json_encode($updatedBlob);

        // Update the blob field in the database
        $sql = "UPDATE records SET blob_field = :blob_field WHERE id = :id";
        $this->query($sql, ['id' => $id, 'blob_field' => $updatedBlobJson]);

        // Return the lists of new and removed items
        return [
            'newItems' => $newItems,
            'removedItems' => $removedItems
        ];
    }

}
