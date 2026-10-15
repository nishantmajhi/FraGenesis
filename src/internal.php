<?php
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'paper.php';

    /**
     * Generates an internal exam paper with MCQ, short answer, and long answer
     * questions. Marks are distributed as:
     * 7x1=7 (MCQ) + 3x5=15 (SAQ) + 1x8=8 (LAQ) = 30 marks.
     *
     * @param int   $grade  The grade level to fetch questions for
     * @param array $topics List of topic identifiers (e.g. "4.2.1")
     */
    function internalExam(int $grade, array $topics): string {
        return generatePaper($grade, $topics, 'Internal Exam', [
            ['table' => 'multipleChoice',       'count' => 7, 'marks' => 1],
            ['table' => 'shortAnswerQuestions', 'count' => 3, 'marks' => 5],
            ['table' => 'longAnswerQuestions',  'count' => 1, 'marks' => 8],
        ]);
    }
