<?php

/**
 * The original article builder, lifted out of src/unit.php, src/internal.php
 * and src/terminal.php with its I/O removed.
 *
 * Those three files each contained their own copy of this markup, and the three
 * copies were byte-identical — only the question types and the <nav> text
 * differed. src/paper.php is now the one copy. This file is how that is proved:
 * the same rows go through both builders and the output is compared exactly.
 *
 * Transcribed from commit d70b64f ("Initial commit"). The literals below,
 * including their indentation, are that commit's src/internal.php lines 39-99
 * verbatim, with the surrounding PDO and template code taken out. Nothing here
 * is a fix; it is the reference. tests/original/*.php next to it are byte-exact
 * copies and OriginalTest.php asserts that they still match the commit.
 */

/**
 * @param array $mcQuestions    Rows for Group A.
 * @param array $shortQuestions Rows for Group B.
 * @param array $longQuestions  Rows for Group C.
 */
function originalArticle(array $mcQuestions, array $shortQuestions, array $longQuestions): string
{
    $article        = '';
    $questionNumber = 1;

    // Group A: MCQ
    if (!empty($mcQuestions)) {
        $totalMarks = count($mcQuestions) * $mcQuestions[0]['marks'];

        $article .= '<section id="MCQ">
                            <h1>Group \'A\'</h1>
                            <h2>
                            <span>Tick the best alternative.</span>
                            <span class="marks">' . count($mcQuestions) . 'x' . $mcQuestions[0]['marks'] . '=' . $totalMarks . '</span>
                            </h2>
                            <ol>';
        foreach ($mcQuestions as $question) {
            $options = json_decode($question['options'], true);
            $article .= '<li>
                                ' . htmlspecialchars($question['question']) . '
                            <ol type="a">';
            foreach ($options as $option) {
                $article .= '<li>' . htmlspecialchars($option) . '</li>';
            }
            $article .= '</ol>
                            </li>';
            $questionNumber++;
        }
        $article .= '</ol>
                        </section>';
    }

    // Group B: Short Answer Questions
    if (!empty($shortQuestions)) {
        $totalMarks = count($shortQuestions) * $shortQuestions[0]['marks'];
        $article .= '<section id="short">
                            <h1>Group \'B\'</h1>
                            <h2>
                            <span>Give short answer to the following questions.</span>
                            <span class="marks">' . count($shortQuestions) . 'x' . $shortQuestions[0]['marks'] . '=' . $totalMarks . '</span>
                            </h2>
                            <ol>';
        foreach ($shortQuestions as $question) {
            $article .= '<li>
                                ' . htmlspecialchars($question['question']) . '
                            </li>';
            $questionNumber++;
        }
        $article .= '</ol>
                        </section>';
    }

    // Group C: Long Answer Questions
    if (!empty($longQuestions)) {
        $totalMarks = count($longQuestions) * $longQuestions[0]['marks'];
        $article .= '<section id="long">
                            <h1>Group \'C\'</h1>
                            <h2>
                            <span>Give long answer to the following questions.</span>
                            <span class="marks">' . count($longQuestions) . 'x' . $longQuestions[0]['marks'] . '=' . $totalMarks . '</span>
                            </h2>
                            <ol>';
        foreach ($longQuestions as $question) {
            $article .= '<li>
                                ' . htmlspecialchars($question['question']) . '
                            </li>';
            $questionNumber++;
        }
        $article .= '</ol>
                        </section>';
    }

    return $article;
}

/** The original's template substitution, from the same three files. */
function originalPage(string $template, $grade, string $examName, string $article): string
{
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'intToRoman.php';

    return preg_replace(
        ['/<nav>.*?<\/nav>/s', '/<article>.*?<\/article>/s'],
        [
            '<nav><span>Grade ' . intToRoman($grade) . '</span><span>' . $examName . '</span></nav>',
            '<article>' . $article . '</article>',
        ],
        $template
    );
}
