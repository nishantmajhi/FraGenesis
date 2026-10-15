<?php
    require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'invalidQuery.php';
    require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'validateRequest.php';

    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($requestMethod === 'GET' && count($_GET) > 0) {
        handleRequest();
    } else {
        invalidQuery();
    }

    function handleRequest(): void {
        // Everything is checked before anything is used. The original read the
        // four parameters straight out of $_GET and divided one of them, so
        // ?short=abc was a TypeError, ?grade=abc was a TypeError from a typed
        // parameter four calls down, ?topics[0][]=1 was a TypeError inside
        // trim(), and ?grade=1e15 spun intToRoman() until the memory limit went.
        // All four were uncaught fatal errors that put an absolute filesystem
        // path and a stack trace in the response body.
        $request = validatePaperRequest($_GET);

        if (!$request['ok']) {
            invalidQuery();
            return;
        }

        // Grade 11 has a syllabus but no questions imported for it yet.
        if ($request['grade'] === 11) {
            readfile(dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'grade11.html');
            return;
        }

        switch ($request['fullMarks']) {
            case 20:
                require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'unit.php';
                echo unitTest($request['grade'], $request['topics'], $request['mcq'], $request['short']);
                break;
            case 30:
                require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'internal.php';
                echo internalExam($request['grade'], $request['topics']);
                break;
            case 50:
                require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'terminal.php';
                echo terminalExam($request['grade'], $request['topics']);
                break;
        }
    }
