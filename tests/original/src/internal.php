<?php
    /**
     * Generates an internal exam paper with MCQ, short answer, and long answer questions.
     * Marks are distributed as: 7x1=7 (MCQ) + 3x5=15 (SAQ) + 1x8=8 (LAQ) = 30 marks.
     *
     * @param int   $grade  The grade level to fetch questions for
     * @param array $topics List of topic identifiers (e.g. "4.2.1")
     */
    function internalExam($grade, $topics) {
        $dbPath = dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'questions.sqlite3';
        
        try {
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $article        = '';
            $questionNumber = 1;
            
            require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'groupTopics.php';
            $unitMap        = groupTopicsByUnit($topics);
            
            // Fetch all question types together so marks are distributed evenly
            // across units: 7x1=7 (MCQ) + 3x5=15 (SAQ) + 1x8=8 (LAQ) = 30 marks
            require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'fetchQuestions.php';
            $allQuestions = fetchAllDistributed($pdo, $grade, $unitMap, [
                ['table' => 'multipleChoice',       'count' => 7, 'marks' => 1],
                ['table' => 'shortAnswerQuestions',  'count' => 3, 'marks' => 5],
                ['table' => 'longAnswerQuestions',   'count' => 1, 'marks' => 8],
            ]);

            $mcQuestions    = $allQuestions['multipleChoice']       ?? [];
            $shortQuestions = $allQuestions['shortAnswerQuestions']  ?? [];
            $longQuestions  = $allQuestions['longAnswerQuestions']   ?? [];

            // Group A: MCQ — 7x1=7
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

            // Group B: Short Answer Questions — 3x5=15
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

            // Group C: Long Answer Questions — 1x8=8
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

            require_once dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'intToRoman.php';
            
            $unitHtmlPath = dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'question' . DIRECTORY_SEPARATOR . 'question.html';
            if (file_exists($unitHtmlPath)) {
                $unitHtml = file_get_contents($unitHtmlPath);
                $unitHtml = preg_replace(
                    ['/<nav>.*?<\/nav>/s', '/<article>.*?<\/article>/s'],
                    ['<nav><span>Grade ' . intToRoman($grade) . '</span><span>Internal Exam</span></nav>', '<article>' . $article . '</article>'],
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