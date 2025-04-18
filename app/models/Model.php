<?php

namespace app\Models;

class Model {
    protected $db;

    // Constructor to set the database connection
    public function __construct($dbType, $host = null, $dbname = null, $username = null, $password = null) {
        if ($dbType === 'sqlite') {
            $this->db = new \PDO('sqlite:' . $dbname); // SQLite3
        } elseif ($dbType === 'mysql') {
            $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8";
            $this->db = new \PDO($dsn, $username, $password); // MySQL
        }
    }

    // Execute a query
    public function query($sql, $params = []) {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}