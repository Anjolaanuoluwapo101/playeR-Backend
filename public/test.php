<?php
$dir = 'refresh_tokens/';
$file = 'refresh_tokens/test.txt';

// Check if directory is writable
if (is_writable($dir)) {
    echo "Directory is writable.<br>";
} else {
    echo "Directory is NOT writable. Check permissions!<br>";
}

// Check if file can be created
if (file_put_contents($file, "Test file access")) {
    echo "File created successfully.<br>";
} else {
    echo "Failed to create file. Check permissions!<br>";
}
?>