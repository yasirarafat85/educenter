<?php
// ডাটাবেস কানেকশন — PDO ব্যবহার করে (নিরাপদ, prepared statements সাপোর্ট করে)

require_once __DIR__ . '/../config.php';

function get_db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (DEV_MODE) {
                die('ডাটাবেস কানেকশন ব্যর্থ হয়েছে: ' . $e->getMessage());
            }
            die('সাময়িক সমস্যা হয়েছে, একটু পরে আবার চেষ্টা করুন।');
        }
    }

    return $pdo;
}
