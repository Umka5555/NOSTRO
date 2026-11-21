<?php
// Пример настроек подключения к PostgreSQL (скопируйте в db.php и укажите ваш пароль)
$DB_HOST = 'localhost';
$DB_PORT = '5432';
$DB_NAME = 'NOSTRO';
$DB_USER = 'postgres';
$DB_PASS = 'your_password_here';

function getDbConnection(): PDO {
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;

    $dsn = "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME}";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    return new PDO($dsn, $DB_USER, $DB_PASS, $options);
}
