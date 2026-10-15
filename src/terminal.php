<?php
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'paper.php';

    /**
     * Generates a terminal exam paper with MCQ, short answer, and long answer
     * questions. Marks are distributed as:
     * 9x1=9 (MCQ) + 5x5=25 (SAQ) + 2x8=16 (LAQ) = 50 marks.
     *
     * @param int   $grade  The grade level to fetch questions for
     * @param array $topics List of topic identifiers (e.g. "4.2.1")
     */
    function terminalExam(int $grade, array $topics): string {
        return generatePaper($grade, $topics, 'Terminal Exam', [
            ['table' => 'multipleChoice',       'count' => 9, 'marks' => 1],
            ['table' => 'shortAnswerQuestions', 'count' => 5, 'marks' => 5],
            ['table' => 'longAnswerQuestions',  'count' => 2, 'marks' => 8],
        ]);
    }
