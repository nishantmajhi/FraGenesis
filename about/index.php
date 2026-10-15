<?php
    require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'osCheck.php';

    if (!isPcOS()) {
        readfile(__DIR__ . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'premium.html');
        return;
    }
    readfile('about.html');
