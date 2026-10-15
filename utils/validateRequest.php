<?php
    /**
     * Validates the query string of a paper request before any of it reaches
     * the generator.
     *
     * The rule this file follows is that a parameter is checked against the set
     * of values the rest of the project already knows how to handle, never
     * against a description of the values it cannot. So `fullMarks` is checked
     * against the three papers that exist, not against "is it a number"; and a
     * topic is checked against the shape the database stores, not against a
     * list of characters that would be awkward.
     *
     * Every check is on the raw string. Nothing is coerced first, because the
     * coercions are what the original got wrong: PHP turns "12.9" into 12 and
     * "1e3" into 1000 without complaint, and throws a TypeError on "12abc" —
     * and a TypeError is an Error, not an Exception, so the try/catch in the
     * generators does not catch it and the request dies with a stack trace and
     * an absolute filesystem path in the response body.
     *
     * The function is pure: query array in, decision out. It touches no
     * superglobal and prints nothing, so the whole of it is testable.
     */

    /** Grades the project has content or a page for. */
    const GRADES = [11, 12];

    /** The three papers `src/` can generate, by their full marks. */
    const FULL_MARKS = [20, 30, 50];

    /** Marks per short-answer question — every paper prices them at five. */
    const SHORT_ANSWER_MARKS = 5;

    /**
     * Upper bound on how many topics one request may name. Grade 11 is the
     * larger syllabus at 105 topic ids, so 256 leaves room for the syllabus to
     * grow while still bounding the IN (...) list and the allocation loop.
     */
    const MAX_TOPICS = 256;

    /**
     * True for a string or int that is a plain non-negative decimal integer.
     *
     * Deliberately strict. It rejects leading zeros, leading and trailing
     * space, a leading plus, exponents and decimal points — all of which PHP
     * would otherwise accept somewhere downstream and quietly reinterpret.
     */
    function isPlainNonNegativeInt(mixed $value): bool {
        if (is_int($value)) {
            return $value >= 0;
        }
        return is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,9})$/', $value) === 1;
    }

    /**
     * True for a topic identifier of the shape this project uses: a unit
     * number, then up to three dot-separated sub-numbers.
     *
     * Grade 12 sends two segments ("4.2"), grade 11 sends three ("1.1.1"), and
     * groupTopicsByUnit() normalises anything longer down to two. Leading zeros
     * are rejected because the database stores none, so "04.2" would otherwise
     * be a topic that validates, queries nothing and silently produces a
     * shorter paper than the one that was asked for.
     */
    function isTopicId(mixed $value): bool {
        return is_string($value)
            && preg_match('/^[1-9][0-9]{0,2}(?:\.(?:0|[1-9][0-9]{0,2})){0,3}$/', $value) === 1;
    }

    /**
     * Validates a paper request.
     *
     * @param array $query Normally $_GET.
     * @return array Either
     *               ['ok' => true, 'grade' => int, 'fullMarks' => int,
     *                'topics' => string[], 'mcq' => int, 'short' => int]
     *               or
     *               ['ok' => false, 'reason' => string].
     *
     *               `mcq` and `short` are the number of questions of each kind,
     *               already converted from the marks the query states, so the
     *               caller never divides anything. They are 0 for the papers
     *               whose composition is fixed.
     */
    function validatePaperRequest(array $query): array {
        foreach (['grade', 'fullMarks', 'topics'] as $required) {
            if (!isset($query[$required])) {
                return ['ok' => false, 'reason' => "$required is required"];
            }
        }

        if (!isPlainNonNegativeInt($query['grade'])) {
            return ['ok' => false, 'reason' => 'grade must be a whole number'];
        }
        $grade = (int) $query['grade'];
        if (!in_array($grade, GRADES, true)) {
            return ['ok' => false, 'reason' => 'grade must be one of ' . implode(', ', GRADES)];
        }

        if (!isPlainNonNegativeInt($query['fullMarks'])) {
            return ['ok' => false, 'reason' => 'fullMarks must be a whole number'];
        }
        $fullMarks = (int) $query['fullMarks'];
        if (!in_array($fullMarks, FULL_MARKS, true)) {
            return ['ok' => false, 'reason' => 'fullMarks must be one of ' . implode(', ', FULL_MARKS)];
        }

        $topics = validateTopics($query['topics']);
        if (is_string($topics)) {
            return ['ok' => false, 'reason' => $topics];
        }

        // Only the 20-mark unit test is customisable. The other two have a
        // fixed composition, so mcq and short carry no meaning there and are
        // ignored rather than rejected — the front end never sends them.
        $mcq   = 0;
        $short = 0;
        if ($fullMarks === 20) {
            $split = validateUnitTestSplit($query, $fullMarks);
            if (is_string($split)) {
                return ['ok' => false, 'reason' => $split];
            }
            [$mcq, $short] = $split;
        }

        return [
            'ok'        => true,
            'grade'     => $grade,
            'fullMarks' => $fullMarks,
            'topics'    => $topics,
            'mcq'       => $mcq,
            'short'     => $short,
        ];
    }

    /**
     * @return string[]|string The topic list, or the reason it was refused.
     */
    function validateTopics(mixed $raw): array|string {
        if (is_array($raw)) {
            $list = $raw;
        } else if (is_string($raw)) {
            $list = explode(',', $raw);
        } else {
            return 'topics must be a list or a comma-separated string';
        }

        $topics = [];
        foreach ($list as $topic) {
            // A nested array reaches here from ?topics[0][]=1. The original
            // handed it straight to trim(), which is a TypeError and therefore
            // an uncaught fatal error rather than a 400.
            if (!is_string($topic) && !is_int($topic)) {
                return 'topics must contain only topic identifiers';
            }
            $topic = (string) $topic;
            // Checked before trim(), because trim() strips NUL and vertical tab
            // along with the spaces — so "1.1\0" would otherwise become the
            // perfectly valid topic "1.1" rather than being refused.
            if (preg_match('/[\x00-\x1F\x7F]/', $topic) === 1) {
                return 'topics must contain only topic identifiers';
            }
            $topic = trim($topic);
            if ($topic === '') {
                continue;
            }
            if (!isTopicId($topic)) {
                return 'topics must contain only topic identifiers';
            }
            $topics[] = $topic;
        }

        $topics = array_values(array_unique($topics));

        if ($topics === []) {
            return 'at least one topic is required';
        }
        if (count($topics) > MAX_TOPICS) {
            return 'no more than ' . MAX_TOPICS . ' topics may be requested';
        }

        return $topics;
    }

    /**
     * Validates the MCQ/short-answer split of a unit test.
     *
     * The two query parameters are *marks*, not question counts — that is what
     * the two sliders on the home page set, and they are wired so that one
     * always makes up what the other leaves. Three things follow, and the
     * original enforced none of them:
     *
     *  - short marks must divide by five, because a short-answer question is
     *    worth five. ?short=16 asked for 3.2 questions; the loop ran four
     *    times, so the paper contained four of them and announced "4x5=20".
     *  - the two must add up to the paper's full marks, or the paper does not
     *    total what its own heading says. ?mcq=4&short=16 printed a 24-mark
     *    paper under a 20-mark title.
     *  - neither may be negative. A negative count did not reach SQL as the
     *    first review supposed — the `if (... > 0)` guards in src/unit.php drop
     *    the whole group — but it silently produced a paper missing a section.
     *
     * @return array{0:int,1:int}|string [mcqCount, shortAnswerCount], or the
     *                                   reason the split was refused.
     */
    function validateUnitTestSplit(array $query, int $fullMarks): array|string {
        $mcqMarks   = $query['mcq']   ?? 5;
        $shortMarks = $query['short'] ?? 15;

        if (!isPlainNonNegativeInt($mcqMarks) || !isPlainNonNegativeInt($shortMarks)) {
            return 'mcq and short must be whole numbers of marks';
        }

        $mcqMarks   = (int) $mcqMarks;
        $shortMarks = (int) $shortMarks;

        if ($shortMarks % SHORT_ANSWER_MARKS !== 0) {
            return 'short must be a multiple of ' . SHORT_ANSWER_MARKS
                 . ', because a short-answer question is worth '
                 . SHORT_ANSWER_MARKS . ' marks';
        }

        if ($mcqMarks + $shortMarks !== $fullMarks) {
            return "mcq and short must add up to $fullMarks marks";
        }

        // An MCQ is worth one mark, so its mark total is also its count.
        return [$mcqMarks, intdiv($shortMarks, SHORT_ANSWER_MARKS)];
    }
