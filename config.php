<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'betagen');
define('DB_USER', 'root');
define('DB_PASS', '');

define('MAIL_FROM', 'no-reply@example.com');
define('MAIL_FROM_NAME', 'APP BETAGEN');

define('OTP_EXPIRE_MINUTES', 10);
define('SESSION_NAME', 'BETAGEN_SESSION');

date_default_timezone_set('Asia/Bangkok');

session_name(SESSION_NAME);
session_start();

function db() {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

        $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ));
    }

    return $pdo;
}

function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

function input_json() {
    $raw = file_get_contents('php://input');

    if (!$raw) {
        return array();
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        json_response(array(
            'ok' => false,
            'message' => 'ข้อมูล JSON ไม่ถูกต้อง'
        ), 400);
    }

    return $data;
}

function clean($value) {
    if (is_array($value)) {
        return $value;
    }

    return trim((string)$value);
}

function current_user() {
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT id,email,is_seller,created_at
         FROM users
         WHERE id=?
         LIMIT 1'
    );

    $stmt->execute(array(
        (int)$_SESSION['user_id']
    ));

    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    $user['id'] = (int)$user['id'];
    $user['is_seller'] = (int)$user['is_seller'];

    return $user;
}

function require_user() {
    $user = current_user();

    if (!$user) {
        json_response(array(
            'ok' => false,
            'message' => 'กรุณาเข้าสู่ระบบ'
        ), 401);
    }

    return $user;
}

function require_seller() {
    $user = require_user();

    if ((int)$user['is_seller'] !== 1) {
        json_response(array(
            'ok' => false,
            'message' => 'บัญชีนี้ยังไม่ได้เปิดใช้งาน Seller'
        ), 403);
    }

    return $user;
}

function send_mail_text($to, $subject, $body) {
    $headers = array();

    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';
    $headers[] = 'Reply-To: ' . MAIL_FROM;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    return mail(
        $to,
        '=?UTF-8?B?' . base64_encode($subject) . '?=',
        $body,
        implode("\r\n", $headers)
    );
}
