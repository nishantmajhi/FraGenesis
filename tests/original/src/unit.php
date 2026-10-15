<?php
    /**
     * Generates a unit test paper with MCQ and short answer questions.
    *
    * @param int   $grade              The grade level to fetch questions for
    * @param array $topics             List of topic identifiers (e.g. "4.2.1")
    * @param int   $noOfMCQ            Number of multiple choice questions to include
    * @param int   $noOfShortQuestions Number of short answer questions to include
    */
    function unitTest($grade, $topics, $noOfMCQ, $noOfShortQuestions) {
        $dbPath = dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'questions.sqlite3';
        
        try {
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $article        = '';
            $questionNumber = 1;

            require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'groupTopics.php';
            $unitMap        = groupTopicsByUnit($topics);
            
            $questionTypes = [];
            if ($noOfMCQ            > 0) $questionTypes[] = ['table' => 'multipleChoice',      'count' => $noOfMCQ,            'marks' => 1];
            if ($noOfShortQuestions > 0) $questionTypes[] = ['table' => 'shortAnswerQuestions', 'count' => $noOfShortQuestions, 'marks' => 5];
            
            require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'fetchQuestions.php';
            $allQuestions   = fetchAllDistributed($pdo, $grade, $unitMap, $questionTypes);
            $mcQuestions    = $allQuestions['multipleChoice']      ?? [];
            $shortQuestions = $allQuestions['shortAnswerQuestions'] ?? [];

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

            require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'intToRoman.php';
            
            $unitHtmlPath = dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'question' . DIRECTORY_SEPARATOR . 'question.html';
            if (file_exists($unitHtmlPath)) {
                $unitHtml = file_get_contents($unitHtmlPath);
                $unitHtml = preg_replace(
                    ['/<nav>.*?<\/nav>/s', '/<article>.*?<\/article>/s'],
                    ['<nav><span>Grade ' . intToRoman($grade) . '</span><span>Unit Test</span></nav>', '<article>' . $article . '</article>'],
                    $unitHtml
                );
                echo $unitHtml;
            } else {
                echo '<div style="color: red; padding: 20px;"><strong>File Error:</strong> question.html not found at ' . htmlspecialchars($unitHtmlPath) . '</div>';
            }
        } catch (Exception $e) {
            echo '<div style="color: red; padding: 20px;"><strong>Database Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }