<?php
    require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'invalidQuery.php';
    
    $requestMethod = $_SERVER['REQUEST_METHOD'];
    if ($requestMethod === 'GET' && count($_GET) > 0) {
        handleRequest();
    } else {
        invalidQuery();
    }

    function handleRequest(): void {
        $grade = $_GET['grade'] ?? null;
        $fullMarks = $_GET['fullMarks'] ?? null;
        $topics = parseTopics();

        if ($grade === null || $fullMarks === null || $topics === null) {
            invalidQuery();
            return;
        } else if ($grade == 11) {
            readfile('../pages/grade11.html');
            return;
        }

        require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'internal.php';
        require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'terminal.php';
        require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'unit.php';

        switch ($fullMarks) {
            case 20:
                $MCQ = $_GET['mcq'] ?? 5;
                $shortQuestions = $_GET['short'] ?? 15;
                echo unitTest($grade, $topics, $MCQ, $shortQuestions / 5);
                break;
            case 30:
                echo internalExam($grade, $topics);
                break;
            case 50:
                echo terminalExam($grade, $topics);
                break;
            default:
                invalidQuery();
        }
    }

    function parseTopics(): ?array {
        if (!isset($_GET['topics'])) {
            return null;
        }
        return is_array($_GET['topics']) ? $_GET['topics'] : explode(',', $_GET['topics']);
    }
