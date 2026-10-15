<?php
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'invalidQuery.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'osCheck.php';

    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($requestMethod === 'GET') {
        if (!isPcOS()) {
            readfile(__DIR__ . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'premium.html');
            return;
        }

        readfile(__DIR__ . DIRECTORY_SEPARATOR . 'home.html');
    } else {
        invalidQuery();
    }
