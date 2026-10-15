<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'paper.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'original' . DIRECTORY_SEPARATOR . 'builder.php';

/** Rows keyed the way fetchAllDistributed() returns them. */
function groups(int $mcq, int $short, int $long): array
{
    $result = [];
    if ($mcq > 0)   $result['multipleChoice']       = Fixture::rows('multipleChoice', $mcq, 1);
    if ($short > 0) $result['shortAnswerQuestions'] = Fixture::rows('shortAnswerQuestions', $short, 5);
    if ($long > 0)  $result['longAnswerQuestions']  = Fixture::rows('longAnswerQuestions', $long, 8);
    return $result;
}

// ── the consolidation is byte-for-byte ───────────────────────────────────────

Harness::test('the markup matches the original builder exactly, for every shape of paper', function () {
    // src/unit.php, src/internal.php and src/terminal.php were three copies of
    // one builder. This is the proof that replacing them with src/paper.php did
    // not change a byte of what a reader sees: same rows, both builders, exact
    // string comparison. Every combination of present and absent groups, plus
    // the three real papers.
    $shapes = [
        [5, 3, 0],   // unit test
        [7, 3, 1],   // internal exam
        [9, 5, 2],   // terminal exam
        [20, 0, 0],  // the all-MCQ end of the slider
        [0, 4, 0],   // the all-short end of the slider
        [0, 0, 0],   // an empty bank
        [1, 0, 1],
        [0, 1, 1],
        [1, 1, 1],
        [2, 0, 0],
        [0, 0, 3],
    ];

    foreach ($shapes as [$mcq, $short, $long]) {
        $result = groups($mcq, $short, $long);
        $label  = "$mcq/$short/$long";

        Harness::same(
            originalArticle(
                $result['multipleChoice']       ?? [],
                $result['shortAnswerQuestions'] ?? [],
                $result['longAnswerQuestions']  ?? []
            ),
            buildArticle($result),
            $label
        );
    }
});

Harness::test('the markup still matches when a question needs escaping', function () {
    // A Computer Science paper is the likely place for a < to turn up.
    $rows = [[
        'id'       => 1,
        'marks'     => 5,
        'question' => 'Is a < b && b > c "true"? Write if (x <= 3) { }',
    ]];
    $mcq = [[
        'id'       => 2,
        'marks'    => 1,
        'question' => 'Which operator is <=?',
        'options'  => json_encode(['<', '>', '<=', '&amp;']),
    ]];

    Harness::same(
        originalArticle($mcq, $rows, []),
        buildArticle(['multipleChoice' => $mcq, 'shortAnswerQuestions' => $rows])
    );
});

Harness::test('the whole page matches the original substitution', function () {
    $template = file_get_contents(
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'question' . DIRECTORY_SEPARATOR . 'question.html'
    );
    foreach ([['Unit Test', 12], ['Internal Exam', 12], ['Terminal Exam', 11]] as [$name, $grade]) {
        $article = buildArticle(groups(7, 3, 1));
        Harness::same(
            originalPage($template, $grade, $name, $article),
            renderPaper($grade, $name, $article),
            $name
        );
    }
});

// ── what the markup says ─────────────────────────────────────────────────────

Harness::test('each group is printed with its own marks arithmetic', function () {
    $html = buildArticle(groups(7, 3, 1));
    Harness::contains('<span class="marks">7x1=7</span>', $html);
    Harness::contains('<span class="marks">3x5=15</span>', $html);
    Harness::contains('<span class="marks">1x8=8</span>', $html);
});

Harness::test('the three papers add up to the marks their names promise', function () {
    foreach ([[5, 3, 0, 20], [7, 3, 1, 30], [9, 5, 2, 50]] as [$mcq, $short, $long, $expected]) {
        $html = buildArticle(groups($mcq, $short, $long));
        preg_match_all('/class="marks">\d+x\d+=(\d+)</', $html, $m);
        Harness::same($expected, array_sum(array_map('intval', $m[1])), "$mcq/$short/$long");
    }
});

Harness::test('a group with no questions is left out entirely', function () {
    $html = buildArticle(groups(5, 0, 0));
    Harness::contains('id="MCQ"', $html);
    Harness::notContains('id="short"', $html);
    Harness::notContains('id="long"', $html);
    Harness::notContains("Group 'B'", $html);
});

Harness::test('the groups are printed A, B, C whatever order the fetcher used', function () {
    // fetchAllDistributed() keys its result largest-mark-type first, so the
    // long-answer group arrives before the MCQs.
    $result  = groups(7, 3, 1);
    $shuffled = array_reverse($result, true);
    Harness::same(buildArticle($result), buildArticle($shuffled));

    $html = buildArticle($shuffled);
    Harness::ok(
        strpos($html, 'id="MCQ"') < strpos($html, 'id="short"')
        && strpos($html, 'id="short"') < strpos($html, 'id="long"'),
        'sections out of order'
    );
});

Harness::test('question text and options are escaped', function () {
    $html = buildArticle(['multipleChoice' => [[
        'id'       => 1,
        'marks'    => 1,
        'question' => '<script>alert(1)</script>',
        'options'  => json_encode(['<b>a</b>', "it's"]),
    ]]]);
    Harness::notContains('<script>', $html);
    Harness::contains('&lt;script&gt;', $html);
    Harness::contains('&lt;b&gt;a&lt;/b&gt;', $html);
    Harness::contains('it&#039;s', $html);
});

Harness::test('marks come from the row, not from what the paper asked for', function () {
    // If the importer ever stored a different mark value, the paper says what
    // the database holds rather than what src/ hoped for.
    $rows = Fixture::rows('shortAnswerQuestions', 2, 4);
    Harness::contains('2x4=8', buildArticle(['shortAnswerQuestions' => $rows]));
});

Harness::test('an unknown table in the result is ignored, not printed', function () {
    $html = buildArticle([
        'shortNotes'           => Fixture::rows('shortNotes', 3, 2),
        'shortAnswerQuestions' => Fixture::rows('shortAnswerQuestions', 1, 5),
    ]);
    Harness::notContains('shortNotes', $html);
    Harness::contains('1x5=5', $html);
});

// ── the page ─────────────────────────────────────────────────────────────────

Harness::test('the nav says the grade in roman numerals and names the paper', function () {
    $page = renderPaper(12, 'Internal Exam', '');
    Harness::contains('<nav><span>Grade XII</span><span>Internal Exam</span></nav>', $page);

    $page = renderPaper(11, 'Terminal Exam', '');
    Harness::contains('<nav><span>Grade XI</span><span>Terminal Exam</span></nav>', $page);
});

Harness::test('the example paper shipped in the template is replaced, not added to', function () {
    // question/question.html contains a hand-written sample paper so the file
    // can be opened on its own. None of it may survive into a generated page.
    $page = renderPaper(12, 'Unit Test', buildArticle(groups(1, 0, 0)));
    Harness::notContains('Which one of the following is an input device?', $page);
    Harness::notContains('Suggest the prevention methods of cybercrime.', $page);
    Harness::notContains('Grade XI</span>', $page);
    Harness::same(1, preg_match_all('/<article>/', $page), 'one article element');
    Harness::same(1, preg_match_all('/<nav>/', $page), 'one nav element');
});

Harness::test('the page keeps the head, the print button and the scripts', function () {
    $page = renderPaper(12, 'Unit Test', buildArticle(groups(1, 1, 1)));
    foreach (['question.css', 'question.js', 'id="subject"', 'title="Print"', '<!DOCTYPE html>'] as $needed) {
        Harness::contains($needed, $page);
    }
});
