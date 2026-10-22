<?php
// महत्त्वाचं: Server चा Default Timezone (बरेचदा UTC) कुठलाही असो —
// इथे स्पष्टपणे भारतीय प्रमाण वेळ (IST — Indian Standard Time,
// UTC+5:30) निश्चित करतो, जेणेकरून प्रकल्पातली प्रत्येक तारीख/वेळ
// (कामाची नोंद, पत्र, बिल-भरणा, इ.) नेहमी बरोबर भारतीय वेळेनुसारच दिसेल.
date_default_timezone_set('Asia/Kolkata');

// सुरक्षा: Server च्या डिफॉल्ट Setting वर अवलंबून न राहता, इथेच स्पष्टपणे
// ठरवतो — कुठलाही PHP Error/Warning कधीही Visitor ला दिसू नये (त्यातून
// File Path, Database Structure, इ. अंतर्गत माहिती उघड होऊ शकते), पण तो
// तरीही Server च्या Log मध्ये (debugging साठी) व्यवस्थित नोंदवला जावा.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

// Cron Scripts थेट Server वरून, Browser शिवाय चालतात — तिथे
// $_SERVER['HTTP_HOST'] उपलब्ध नसतो, त्यामुळे Email मधल्या Links साठी
// Site चा पूर्ण पत्ता इथे एकदाच नोंदवून ठेवतो. (लाईव्ह Domain टाका, उदा.
// 'https://babashree.com' — शेवटी स्लॅश '/' नको.)
define('SITE_BASE_URL', 'https://amd.babashree.com');

// Database connection — plain PDO, no framework, no Composer needed

$DB_HOST = '127.0.0.1';
$DB_NAME = 'eowms';
$DB_USER = 'root';
$DB_PASS = ''; // XAMPP default MySQL password is empty

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    // Database Server चाही Session-Timezone IST वर सेट करतो — जेणेकरून
    // NOW(), CURDATE(), CURRENT_TIMESTAMP (Default Value म्हणून वापरलेले)
    // — हे सगळे MySQL Function सुद्धा भारतीय वेळेनुसारच काम करतील.
    $pdo->exec("SET time_zone = '+05:30'");
} catch (PDOException $e) {
    // सुरक्षा: तांत्रिक Error चा तपशील सगळ्यांना (Public ला) दाखवायचा नाही —
    // तो फक्त Server च्या Log मध्ये लिहून, Visitor ला एक सर्वसाधारण, सुरक्षित
    // संदेश दाखवायचा — जेणेकरून Database ची अंतर्गत माहिती उघड होणार नाही.
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('सध्या ही सेवा उपलब्ध नाही — कृपया काही वेळाने पुन्हा प्रयत्न करा. (Service temporarily unavailable)');
}
