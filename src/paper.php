<?php
    /**
     * The HTML of a question paper, with no database and no request in it.
     *
     * src/unit.php, src/internal.php and src/terminal.php were three copies of
     * the same 119 lines, differing only in the question types they ask for and
     * the words in the <nav>. They now each describe their paper and call
     * renderPaper(). The markup this file emits is byte-identical to what the
     * three copies emitted, which tests/PaperTest.php checks against a
     * transcription of the original builder.
     *
     * Taking the rows as an argument rather than fetching them is what makes
     * that check possible: the same rows can be put through both builders and
     * the output compared exactly, which a function that queried for itself
     * could never allow — ORDER BY RANDOM() is not seedable.
     */

    require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'intToRoman.php';

    /**
     * The three groups a paper can have, in the order they are printed.
     *
     * Keyed by the table the questions come from, because that is the key
     * fetchAllDistributed() returns them under.
     */
    function paperGroups(): array {
        return [
            'multipleChoice' => [
                'id'          => 'MCQ',
                'heading'     => "Group 'A'",
                'instruction' => 'Tick the best alternative.',
                'options'     => true,
            ],
            'shortAnswerQuestions' => [
                'id'          => 'short',
                'heading'     => "Group 'B'",
                'instruction' => 'Give short answer to the following questions.',
                'options'     => false,
            ],
            'longAnswerQuestions' => [
                'id'          => 'long',
                'heading'     => "Group 'C'",
                'instruction' => 'Give long answer to the following questions.',
                'options'     => false,
            ],
        ];
    }

    /**
     * Builds the <article> body: one <section> per group that has questions.
     *
     * A group with no rows is left out entirely rather than printed empty, which
     * is what the original's `if (!empty(...))` guards did.
     *
     * @param array $questionsByTable Output of fetchAllDistributed().
     */
    function buildArticle(array $questionsByTable): string {
        $article = '';

        foreach (paperGroups() as $table => $group) {
            $questions = $questionsByTable[$table] ?? [];
            if (empty($questions)) {
                continue;
            }

            // The marks per question come from the row, not from the paper's
            // own question-type list, so a paper says what the database
            // actually contains. The two agree today: every row in
            // multipleChoice is 1 mark, shortAnswerQuestions 5 and
            // longAnswerQuestions 8.
            $marks      = $questions[0]['marks'];
            $totalMarks = count($questions) * $marks;

            $article .= '<section id="' . $group['id'] . '">
                            <h1>' . $group['heading'] . '</h1>
                            <h2>
                            <span>' . $group['instruction'] . '</span>
                            <span class="marks">' . count($questions) . 'x' . $marks . '=' . $totalMarks . '</span>
                            </h2>
                            <ol>';

            foreach ($questions as $question) {
                if ($group['options']) {
                    $options  = json_decode($question['options'], true);
                    $article .= '<li>
                                ' . htmlspecialchars($question['question']) . '
                            <ol type="a">';
                    foreach ($options as $option) {
                        $article .= '<li>' . htmlspecialchars($option) . '</li>';
                    }
                    $article .= '</ol>
                            </li>';
                } else {
                    $article .= '<li>
                                ' . htmlspecialchars($question['question']) . '
                            </li>';
                }
            }

            $article .= '</ol>
                        </section>';
        }

        return $article;
    }

    /**
     * Puts an article into question/question.html, replacing the example <nav>
     * and <article> that file ships as a worked sample.
     *
     * @param int    $grade    Already validated, so intToRoman() cannot be
     *                         handed something that loops it into the memory
     *                         limit.
     * @param string $examName What the <nav> calls this paper.
     * @return string The whole page, or an error page if the template is gone.
     */
    function renderPaper(int $grade, string $examName, string $article): string {
        $templatePath = dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'question' . DIRECTORY_SEPARATOR . 'question.html';

        if (!file_exists($templatePath)) {
            return '<div style="color: red; padding: 20px;"><strong>File Error:</strong> question.html not found at ' . htmlspecialchars($templatePath) . '</div>';
        }

        return preg_replace(
            ['/<nav>.*?<\/nav>/s', '/<article>.*?<\/article>/s'],
            [
                '<nav><span>Grade ' . intToRoman($grade) . '</span><span>' . $examName . '</span></nav>',
                '<article>' . $article . '</article>',
            ],
            file_get_contents($templatePath)
        );
    }

    /**
     * Generates a paper: connect, group the topics, allocate, fetch, render.
     *
     * The three papers in this directory differ only in $examName and
     * $questionTypes, so this is the whole of what they have in common.
     *
     * @param int    $grade         Already validated against GRADES.
     * @param array  $topics        Already validated topic identifiers.
     * @param array  $questionTypes [['table' => ..., 'count' => ..., 'marks' => ...], ...]
     * @return string The page to send.
     */
    function generatePaper(int $grade, array $topics, string $examName, array $questionTypes): string {
        $dbPath = dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'questions.sqlite3';

        require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'groupTopics.php';
        require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'fetchQuestions.php';

        try {
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $unitMap = groupTopicsByUnit($topics);

            // A question type with a count of zero is dropped rather than asked
            // for, so a 20-mark paper of nothing but MCQs has no empty Group B.
            $wanted = array_values(array_filter(
                $questionTypes,
                fn(array $qt): bool => $qt['count'] > 0
            ));

            $questions = fetchAllDistributed($pdo, $grade, $unitMap, $wanted);

            return renderPaper($grade, $examName, buildArticle($questions));
        } catch (Exception $e) {
            // What the original showed, unchanged, for the failures it did
            // actually catch: the database file being missing or unreadable.
            return '<div style="color: red; padding: 20px;"><strong>Database Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
        } catch (Throwable $e) {
            // Everything the original did *not* catch. A TypeError and a
            // ValueError are Errors, not Exceptions, so a bad argument anywhere
            // below here used to reach the client as an uncaught fatal error
            // with the absolute path of the offending file and a stack trace in
            // the response body. Those go to the log now and nowhere else.
            error_log('FraGenesis: ' . $e);

            return '<div style="color: red; padding: 20px;"><strong>Database Error:</strong> the question paper could not be generated.</div>';
        }
    }
