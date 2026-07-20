<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'request_system');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
if ($conn->query($sql) === TRUE) {
    $conn->select_db(DB_NAME);
} else {
    die("Error creating database: " . $conn->error);
}

// Create users table
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL,
    role ENUM('admin', 'staff', 'viewer') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql);

// Create requests table
$sql = "CREATE TABLE IF NOT EXISTS requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    student_number VARCHAR(50) NOT NULL,
    student_name VARCHAR(255) NOT NULL,
    description TEXT,
    requester_id INT,
    status ENUM('pending', 'processing', 'for_signature', 'for_release', 'released') DEFAULT 'pending',
    document_path VARCHAR(255),
    is_archived TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requester_id) REFERENCES users(id)
)";
$conn->query($sql);

// Add is_archived column if it doesn't exist
$sql = "SHOW COLUMNS FROM requests LIKE 'is_archived'";
$result = $conn->query($sql);
if ($result->num_rows === 0) {
    $conn->query("ALTER TABLE requests ADD COLUMN is_archived TINYINT DEFAULT 0 AFTER document_path");
}

// Alter existing table if it exists to add new columns
$sql = "SHOW COLUMNS FROM requests LIKE 'student_number'";
$result = $conn->query($sql);
if ($result->num_rows === 0) {
    // Add new columns if they don't exist
    $conn->query("ALTER TABLE requests ADD COLUMN student_number VARCHAR(50) NOT NULL AFTER title");
    $conn->query("ALTER TABLE requests ADD COLUMN student_name VARCHAR(255) NOT NULL AFTER student_number");
    
    // Update existing records with extracted information from description
    $sql = "SELECT id, description FROM requests";
    $results = $conn->query($sql);
    while ($row = $results->fetch_assoc()) {
        $desc = $row['description'];
        $student_number = '';
        $student_name = '';
        
        // Try to extract student information from existing description
        if (preg_match('/Student Number:\s*([^\n]+)/', $desc, $matches)) {
            $student_number = $matches[1];
        }
        if (preg_match('/Student Name:\s*([^\n]+)/', $desc, $matches)) {
            $student_name = $matches[1];
        }
        
        // Update record with extracted information
        $update_sql = "UPDATE requests SET 
            student_number = '" . $conn->real_escape_string($student_number) . "',
            student_name = '" . $conn->real_escape_string($student_name) . "'
            WHERE id = " . $row['id'];
        $conn->query($update_sql);
    }
}

// Create audit_trail table
$sql = "CREATE TABLE IF NOT EXISTS audit_trail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(255) NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
$conn->query($sql);

// Create archive_history table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS archive_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT,
    action ENUM('archive', 'restore') NOT NULL,
    actioned_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id),
    FOREIGN KEY (actioned_by) REFERENCES users(id)
)";
$conn->query($sql);

// Add default admin account if it doesn't exist
$check_admin = "SELECT id FROM users WHERE username = 'admin'";
$result = $conn->query($check_admin);

if ($result && $result->num_rows === 0) {
    $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
    $sql = "INSERT INTO users (username, password, email, role) 
            VALUES ('admin', '$admin_password', 'admin@example.com', 'admin')";
    $conn->query($sql);
}
?>