<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'validateRequest.php';

/** Shorthand: a valid grade-12 internal exam request with $extra merged in. */
function req(array $extra = []): array
{
    return validatePaperRequest(array_merge(
        ['grade' => '12', 'fullMarks' => '30', 'topics' => '1.1,1.2'],
        $extra
    ));
}

Harness::test('a request the front end sends is accepted', function () {
    $r = req();
    Harness::same(true, $r['ok']);
    Harness::same(12, $r['grade']);
    Harness::same(30, $r['fullMarks']);
    Harness::same(['1.1', '1.2'], $r['topics']);
});

Harness::test('each of the three required parameters is required by name', function () {
    foreach (['grade', 'fullMarks', 'topics'] as $name) {
        $query = ['grade' => '12', 'fullMarks' => '30', 'topics' => '1.1'];
        unset($query[$name]);
        $r = validatePaperRequest($query);
        Harness::same(false, $r['ok'], $name);
        Harness::contains($name, $r['reason'], $name);
    }
});

Harness::test('an empty query is refused rather than defaulted', function () {
    Harness::same(false, validatePaperRequest([])['ok']);
});

// ── grade ────────────────────────────────────────────────────────────────────

Harness::test('grade accepts only the grades this project has a page for', function () {
    Harness::same(11, req(['grade' => '11'])['grade']);
    Harness::same(12, req(['grade' => '12'])['grade']);
    foreach (['0', '1', '10', '13', '120', '112'] as $bad) {
        Harness::same(false, req(['grade' => $bad])['ok'], "grade=$bad");
    }
});

Harness::test('grade rejects the strings PHP would silently reinterpret', function () {
    // '12.9' becomes 12, '1e1' becomes 10, ' 12' becomes 12, '012' becomes 12 —
    // all without a diagnostic. '12abc' is a TypeError at the first typed
    // parameter, which is an Error and therefore uncaught.
    foreach (['12.9', '1e1', ' 12', '12 ', '012', '+12', '12abc', '0x0c', ''] as $bad) {
        Harness::same(false, req(['grade' => $bad])['ok'], "grade=$bad");
    }
});

Harness::test('grade rejects the value that exhausted the memory limit', function () {
    // ?grade=1e15 reached intToRoman(), which appends one 'M' per thousand and
    // therefore tried to build a string of a million million characters.
    Harness::same(false, req(['grade' => '1e15'])['ok']);
    Harness::same(false, req(['grade' => '1000000000000000'])['ok']);
});

Harness::test('grade rejects an array', function () {
    Harness::same(false, req(['grade' => ['12']])['ok']);
});

// ── fullMarks ────────────────────────────────────────────────────────────────

Harness::test('fullMarks accepts only the three papers that exist', function () {
    foreach ([20, 30, 50] as $marks) {
        $r = req(['fullMarks' => (string) $marks, 'mcq' => '5', 'short' => '15']);
        Harness::same($marks, $r['fullMarks'], "fullMarks=$marks");
    }
    foreach (['0', '10', '25', '40', '100', '20.0', '2e1', 'twenty'] as $bad) {
        Harness::same(false, req(['fullMarks' => $bad])['ok'], "fullMarks=$bad");
    }
});

// ── topics ───────────────────────────────────────────────────────────────────

Harness::test('topics accepts a comma-separated string and a list', function () {
    Harness::same(['1.1', '2.3'], req(['topics' => '1.1,2.3'])['topics']);
    Harness::same(['1.1', '2.3'], req(['topics' => ['1.1', '2.3']])['topics']);
});

Harness::test('topics accepts both syllabus shapes', function () {
    // Grade 12 sends two segments, grade 11 sends three.
    Harness::same(['4.2'], req(['topics' => '4.2'])['topics']);
    Harness::same(['1.1.1'], req(['grade' => '11', 'topics' => '1.1.1'])['topics']);
});

Harness::test('topics tolerates the whitespace a hand-typed URL has', function () {
    Harness::same(['1.1', '1.2'], req(['topics' => ' 1.1 , 1.2 '])['topics']);
});

Harness::test('topics drops empties and deduplicates', function () {
    Harness::same(['1.1', '1.2'], req(['topics' => '1.1,,1.2,1.1,'])['topics']);
});

Harness::test('an all-empty topic list is refused, not treated as one topic', function () {
    // The front end sends topics= with nothing after it when no box is ticked.
    // The original turned that into the single topic "" and produced a paper
    // with no questions in it and no indication anything was wrong.
    foreach (['', ',', ' , '] as $bad) {
        $r = req(['topics' => $bad]);
        Harness::same(false, $r['ok'], "topics='$bad'");
        Harness::contains('at least one topic', $r['reason']);
    }
});

Harness::test('a nested topics array is refused, not passed to trim()', function () {
    // ?topics[0][]=1 — trim(array) is a TypeError, which the generator's
    // catch (Exception) did not catch.
    Harness::same(false, req(['topics' => [['1']]])['ok']);
    Harness::same(false, req(['topics' => ['1.1', ['2.2']]])['ok']);
});

Harness::test('topics refuses a scalar that is not a string', function () {
    Harness::same(false, req(['topics' => 1.5])['ok']);
    Harness::same(false, req(['topics' => null])['ok']);
});

Harness::test('topics refuses ids the database could never hold', function () {
    foreach (['04.2', '4.02', '0', '0.1', '.1', '1.', '1..2', '4.2.3.4.5', 'DROP', '1.1;--', "1.1\0"] as $bad) {
        Harness::same(false, req(['topics' => $bad])['ok'], "topics=$bad");
    }
});

Harness::test('topics has an upper bound', function () {
    $many = [];
    for ($i = 1; $i <= MAX_TOPICS; $i++) {
        $many[] = '1.' . $i;
    }
    Harness::same(true, req(['topics' => $many])['ok'], 'at the limit');

    // 1.1 to 1.256 is only 256 distinct values, so go two segments wider.
    $tooMany = [];
    for ($unit = 1; $unit <= 4; $unit++) {
        for ($i = 1; $i <= 100; $i++) {
            $tooMany[] = "$unit.$i";
        }
    }
    $r = req(['topics' => $tooMany]);
    Harness::same(false, $r['ok'], 'over the limit');
    Harness::contains('no more than', $r['reason']);
});

// ── the unit test split ──────────────────────────────────────────────────────

/** Shorthand: a 20-mark unit test request. */
function unitReq(array $extra = []): array
{
    return validatePaperRequest(array_merge(
        ['grade' => '12', 'fullMarks' => '20', 'topics' => '1.1'],
        $extra
    ));
}

Harness::test('the unit test defaults to the paper the sliders start on', function () {
    $r = unitReq();
    Harness::same(5, $r['mcq'], '5 one-mark questions');
    Harness::same(3, $r['short'], '3 five-mark questions');
});

Harness::test('marks are converted to question counts, so no caller divides', function () {
    Harness::same(2, unitReq(['mcq' => '10', 'short' => '10'])['short']);
    Harness::same(10, unitReq(['mcq' => '10', 'short' => '10'])['mcq']);
});

Harness::test('every split the sliders can produce is accepted', function () {
    // The two ranges are step=5, min=0, max=20 and are wired so that one makes
    // up what the other leaves.
    foreach ([0, 5, 10, 15, 20] as $mcq) {
        $short = 20 - $mcq;
        $r = unitReq(['mcq' => (string) $mcq, 'short' => (string) $short]);
        Harness::same(true, $r['ok'], "mcq=$mcq&short=$short");
        Harness::same($mcq + $r['short'] * SHORT_ANSWER_MARKS, 20, "mcq=$mcq totals 20");
    }
});

Harness::test('short marks that do not divide by five are refused', function () {
    // ?short=16 asked for 3.2 questions. The loop ran four times, so the paper
    // contained four of them and its own heading said "4x5=20".
    $r = unitReq(['mcq' => '4', 'short' => '16']);
    Harness::same(false, $r['ok']);
    Harness::contains('multiple of 5', $r['reason']);
});

Harness::test('a split that does not total the paper is refused', function () {
    foreach ([['0', '0'], ['5', '5'], ['20', '20'], ['1', '15'], ['0', '5']] as [$mcq, $short]) {
        $r = unitReq(['mcq' => $mcq, 'short' => $short]);
        Harness::same(false, $r['ok'], "mcq=$mcq&short=$short");
    }
});

Harness::test('a non-numeric split is refused instead of being divided', function () {
    // ?short=abc was 'abc' / 5, a TypeError, and an uncaught fatal error.
    foreach (['abc', '1e1', '15.0', ' 15', '-5', ''] as $bad) {
        Harness::same(false, unitReq(['short' => $bad])['ok'], "short=$bad");
        Harness::same(false, unitReq(['mcq' => $bad])['ok'], "mcq=$bad");
    }
});

Harness::test('a negative count is refused rather than dropping a whole group', function () {
    // The first review said a negative count reached SQL as LIMIT -5. It did
    // not: src/unit.php guarded each type with `if (... > 0)`, so the group was
    // dropped and the paper came out short with no error. (And SQLite treats a
    // negative LIMIT as no limit at all, so it would not have complained.)
    Harness::same(false, unitReq(['mcq' => '-1', 'short' => '20'])['ok']);
    Harness::same(false, unitReq(['mcq' => '25', 'short' => '-5'])['ok']);
});

Harness::test('a fractional count is refused rather than reaching a SQL LIMIT', function () {
    // ?mcq=3.5 allocated ceil(3.5) = 4 questions and then, if the topic could
    // not supply them, interpolated the remaining 1.5 into LIMIT. SQLite
    // answers "datatype mismatch" and the page showed that.
    Harness::same(false, unitReq(['mcq' => '3.5', 'short' => '15'])['ok']);
    Harness::same(false, unitReq(['mcq' => '0', 'short' => '6'])['ok']);
});

Harness::test('a huge count is refused rather than allocated one at a time', function () {
    Harness::same(false, unitReq(['mcq' => '1000000', 'short' => '0'])['ok']);
});

Harness::test('mcq and short are ignored on the papers that have no sliders', function () {
    // The 30- and 50-mark papers have a fixed composition. The front end never
    // sends these; a URL that does is answered with the paper, not a 400.
    foreach ([30, 50] as $marks) {
        $r = req(['fullMarks' => (string) $marks, 'mcq' => '999', 'short' => 'abc']);
        Harness::same(true, $r['ok'], "fullMarks=$marks");
        Harness::same(0, $r['mcq']);
        Harness::same(0, $r['short']);
    }
});

// ── the two predicates, on their own ─────────────────────────────────────────

Harness::test('isPlainNonNegativeInt is strict about what an integer looks like', function () {
    foreach ([0, 7, '0', '7', '4294967296'] as $good) {
        Harness::same(true, isPlainNonNegativeInt($good), var_export($good, true));
    }
    foreach ([-1, '-1', '00', '01', '1.0', '1e3', ' 1', '1 ', '+1', '', 'x', '0b1', null, [], 1.5, true] as $bad) {
        Harness::same(false, isPlainNonNegativeInt($bad), var_export($bad, true));
    }
});

Harness::test('isTopicId matches what the database stores and nothing else', function () {
    foreach (['1', '1.1', '12.34', '1.1.1', '7.8.9.10', '4.0'] as $good) {
        Harness::same(true, isTopicId($good), $good);
    }
    foreach (['', '0', '0.1', '01', '1.01', '1.', '.1', '1..1', '1.2.3.4.5', '1 . 1', 'a', '1a', '１.１', 1, 1.1, null] as $bad) {
        Harness::same(false, isTopicId($bad), var_export($bad, true));
    }
});
