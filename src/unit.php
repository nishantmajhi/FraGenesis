<?php
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'paper.php';

    /**
     * Generates a unit test paper with MCQ and short answer questions.
     *
     * The two counts come from the two sliders on the home page, which are wired
     * so that the marks always add up to twenty: an MCQ is worth one mark and a
     * short-answer question five. validatePaperRequest() enforces that, so by
     * the time this is called $noOfMCQ + 5 * $noOfShortQuestions == 20.
     *
     * @param int   $grade              The grade level to fetch questions for
     * @param array $topics             List of topic identifiers (e.g. "4.2.1")
     * @param int   $noOfMCQ            Number of multiple choice questions to include
     * @param int   $noOfShortQuestions Number of short answer questions to include
     */
    function unitTest(int $grade, array $topics, int $noOfMCQ, int $noOfShortQuestions): string {
        return generatePaper($grade, $topics, 'Unit Test', [
            ['table' => 'multipleChoice',       'count' => $noOfMCQ,            'marks' => 1],
            ['table' => 'shortAnswerQuestions', 'count' => $noOfShortQuestions, 'marks' => 5],
        ]);
    }
