<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'fetchQuestions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'groupTopics.php';

/**
 * How many rows of each table a result contains, by table name.
 *
 * Sorted, because fetchAllDistributed() keys its result in the order it
 * allocated — largest-mark type first — and nothing downstream depends on that:
 * buildArticle() looks each group up by name.
 */
function counts(array $result): array
{
    $counts = array_map('count', $result);
    ksort($counts);
    return $counts;
}

/** The unit number of every row in a result, per table. */
function unitsOf(array $result): array
{
    return array_map(
        fn(array $rows): array => array_map(fn(array $r): int => (int) $r['unit'], $rows),
        $result
    );
}

const INTERNAL = [
    ['table' => 'multipleChoice',       'count' => 7, 'marks' => 1],
    ['table' => 'shortAnswerQuestions', 'count' => 3, 'marks' => 5],
    ['table' => 'longAnswerQuestions',  'count' => 1, 'marks' => 8],
];

Harness::test('a well-stocked bank supplies exactly the paper asked for', function () {
    $pdo = Fixture::withSupply([
        'multipleChoice'       => ['1.1' => 20, '2.1' => 20, '3.1' => 20],
        'shortAnswerQuestions' => ['1.1' => 20, '2.1' => 20, '3.1' => 20],
        'longAnswerQuestions'  => ['1.1' => 20, '2.1' => 20, '3.1' => 20],
    ]);
    $result = fetchAllDistributed($pdo, 12, groupTopicsByUnit(['1.1', '2.1', '3.1']), INTERNAL);

    Harness::same(
        ['longAnswerQuestions' => 1, 'multipleChoice' => 7, 'shortAnswerQuestions' => 3],
        counts($result)
    );
});

Harness::test('rows come back only for the topics that were asked for', function () {
    $pdo = Fixture::withSupply([
        'multipleChoice'       => ['1.1' => 20, '9.9' => 20],
        'shortAnswerQuestions' => ['1.1' => 20, '9.9' => 20],
        'longAnswerQuestions'  => ['1.1' => 20, '9.9' => 20],
    ]);
    $result = fetchAllDistributed($pdo, 12, groupTopicsByUnit(['1.1']), INTERNAL);

    foreach ($result as $table => $rows) {
        foreach ($rows as $row) {
            Harness::same('1.1', $row['topic'], $table);
        }
    }
});

Harness::test('rows come back only for the grade that was asked for', function () {
    $pdo = Fixture::withSupply(['shortAnswerQuestions' => ['1.1' => 10]], 11);
    $result = fetchAllDistributed($pdo, 12, groupTopicsByUnit(['1.1']), [
        ['table' => 'shortAnswerQuestions', 'count' => 3, 'marks' => 5],
    ]);
    Harness::same(0, count($result['shortAnswerQuestions']));
});

Harness::test('no question appears twice in a paper', function () {
    // The backfill query excludes what the per-unit queries already took. If
    // that exclusion broke, a paper would repeat a question.
    for ($run = 0; $run < 40; $run++) {
        $pdo = Fixture::withSupply([
            'multipleChoice'       => ['1.1' => 4, '2.1' => 2, '3.1' => 9],
            'shortAnswerQuestions' => ['1.1' => 1, '2.1' => 6, '3.1' => 1],
            'longAnswerQuestions'  => ['1.1' => 1, '2.1' => 1, '3.1' => 1],
        ]);
        $result = fetchAllDistributed($pdo, 12, groupTopicsByUnit(['1.1', '2.1', '3.1']), INTERNAL);

        foreach ($result as $table => $rows) {
            $ids = array_column($rows, 'id');
            Harness::same(count($ids), count(array_unique($ids)), "$table, run $run");
        }
    }
});

Harness::test('a unit that cannot supply its quota is made up from the others', function () {
    // Unit 2 has one short-answer question and the allocation wants more than
    // that from somewhere. The paper is still three questions long.
    $pdo = Fixture::withSupply([
        'shortAnswerQuestions' => ['1.1' => 30, '2.1' => 1, '3.1' => 30],
    ]);
    for ($run = 0; $run < 25; $run++) {
        $result = fetchAllDistributed($pdo, 12, groupTopicsByUnit(['1.1', '2.1', '3.1']), [
            ['table' => 'shortAnswerQuestions', 'count' => 3, 'marks' => 5],
        ]);
        Harness::same(3, count($result['shortAnswerQuestions']), "run $run");
    }
});

Harness::test('one backfill pass is enough even when only one unit has anything', function () {
    // Three units allocated one each, two of them empty. A single backfill that
    // is not limited to a unit closes the whole gap.
    $pdo = Fixture::withSupply(['shortAnswerQuestions' => ['3.1' => 30]]);
    for ($run = 0; $run < 25; $run++) {
        $result = fetchAllDistributed($pdo, 12, groupTopicsByUnit(['1.1', '2.1', '3.1']), [
            ['table' => 'shortAnswerQuestions', 'count' => 3, 'marks' => 5],
        ]);
        Harness::same(3, count($result['shortAnswerQuestions']), "run $run");
    }
});

Harness::test('a bank with too few questions gives a short paper, not an error', function () {
    $pdo = Fixture::withSupply([
        'multipleChoice'       => ['1.1' => 2],
        'shortAnswerQuestions' => ['1.1' => 1],
        'longAnswerQuestions'  => [],
    ]);
    $result = fetchAllDistributed($pdo, 12, groupTopicsByUnit(['1.1']), INTERNAL);

    Harness::same(
        ['longAnswerQuestions' => 0, 'multipleChoice' => 2, 'shortAnswerQuestions' => 1],
        counts($result)
    );
});

Harness::test('an empty bank gives an empty result rather than throwing', function () {
    $result = fetchAllDistributed(Fixture::empty(), 12, groupTopicsByUnit(['1.1']), INTERNAL);
    Harness::same(
        ['longAnswerQuestions' => 0, 'multipleChoice' => 0, 'shortAnswerQuestions' => 0],
        counts($result)
    );
});

Harness::test('questions are spread across units when every unit can supply', function () {
    // The allocation is only worth having if it survives the fetch. With plenty
    // everywhere, a 7-MCQ internal paper over three units should touch all
    // three rather than take seven from one.
    $pdo = Fixture::withSupply([
        'multipleChoice'       => ['1.1' => 30, '2.1' => 30, '3.1' => 30],
        'shortAnswerQuestions' => ['1.1' => 30, '2.1' => 30, '3.1' => 30],
        'longAnswerQuestions'  => ['1.1' => 30, '2.1' => 30, '3.1' => 30],
    ]);

    for ($run = 0; $run < 25; $run++) {
        $result = fetchAllDistributed($pdo, 12, groupTopicsByUnit(['1.1', '2.1', '3.1']), INTERNAL);

        // Marks per unit across the whole paper, which is what the algorithm
        // balances — not questions per unit.
        $marksPerUnit = [1 => 0, 2 => 0, 3 => 0];
        foreach (unitsOf($result) as $table => $units) {
            foreach ($units as $unit) {
                $marksPerUnit[$unit] += Fixture::MARKS[$table];
            }
        }
        Harness::same(30, array_sum($marksPerUnit), "run $run totals 30");
        Harness::same(10, max($marksPerUnit), "run $run: no unit carries more than 10");
        Harness::same(10, min($marksPerUnit), "run $run: no unit carries less than 10");
    }
});

Harness::test('the unit order is shuffled, so the tie-break favours no one unit', function () {
    // With every unit on zero, allocateByMarks() always gives the first unit the
    // first 8-mark question. fetchAllDistributed() shuffles the unit list before
    // calling it, so over enough papers the long-answer question lands on all
    // three units. Without the shuffle it would always be the same one.
    $pdo = Fixture::withSupply([
        'longAnswerQuestions' => ['1.1' => 30, '2.1' => 30, '3.1' => 30],
    ]);

    $seen = [];
    for ($run = 0; $run < 200; $run++) {
        $result = fetchAllDistributed($pdo, 12, groupTopicsByUnit(['1.1', '2.1', '3.1']), [
            ['table' => 'longAnswerQuestions', 'count' => 1, 'marks' => 8],
        ]);
        $seen[(int) $result['longAnswerQuestions'][0]['unit']] = true;
    }

    ksort($seen);
    Harness::same([1, 2, 3], array_keys($seen), 'units the single question landed on');
});

// ── the table allowlist ──────────────────────────────────────────────────────

Harness::test('a table the database does not have is refused', function () {
    $pdo = Fixture::empty();
    Harness::throws('no question table named', fn() => resolveQuestionTable($pdo, 'users'));
    Harness::throws('no question table named', fn() => resolveQuestionTable($pdo, 'multipleChoice; DROP TABLE multipleChoice'));
    Harness::throws('no question table named', fn() => resolveQuestionTable($pdo, ''));
    Harness::throws('no question table named', fn() => resolveQuestionTable($pdo, 'MULTIPLECHOICE'));
});

Harness::test('resolveQuestionTable returns the name as the database spells it', function () {
    $pdo = Fixture::empty();
    foreach (array_keys(Fixture::SCHEMA) as $table) {
        Harness::same($table, resolveQuestionTable($pdo, $table));
    }
});

Harness::test('a non-string table name is refused rather than concatenated', function () {
    $pdo = Fixture::empty();
    Harness::throws('must be a string', fn() => resolveQuestionTable($pdo, 12));
    Harness::throws('must be a string', fn() => resolveQuestionTable($pdo, null));
    Harness::throws('must be a string', fn() => resolveQuestionTable($pdo, ['multipleChoice']));
});

Harness::test('a paper naming an unknown table throws instead of building SQL', function () {
    $pdo = Fixture::withSupply(['multipleChoice' => ['1.1' => 5]]);
    Harness::throws('no question table named', fn() => fetchAllDistributed(
        $pdo,
        12,
        groupTopicsByUnit(['1.1']),
        [['table' => 'sqlite_master', 'count' => 1, 'marks' => 1]]
    ));
});

Harness::test('the allowlist is read per connection, not cached across them', function () {
    // A static cache keyed on nothing would answer for whichever database was
    // opened first, which in a suite like this one is the wrong answer.
    $withTables = Fixture::empty();
    $bare       = new PDO('sqlite::memory:');

    Harness::same('multipleChoice', resolveQuestionTable($withTables, 'multipleChoice'));
    Harness::throws('no question table named', fn() => resolveQuestionTable($bare, 'multipleChoice'));
    Harness::same('multipleChoice', resolveQuestionTable($withTables, 'multipleChoice'));
});

// ── what the fetcher hands the renderer ──────────────────────────────────────

Harness::test('rows carry the columns the paper builder reads', function () {
    $pdo = Fixture::withSupply([
        'multipleChoice'       => ['1.1' => 5],
        'shortAnswerQuestions' => ['1.1' => 5],
    ]);
    $result = fetchAllDistributed($pdo, 12, groupTopicsByUnit(['1.1']), [
        ['table' => 'multipleChoice',       'count' => 2, 'marks' => 1],
        ['table' => 'shortAnswerQuestions', 'count' => 1, 'marks' => 5],
    ]);

    foreach (['question', 'marks', 'id'] as $column) {
        Harness::ok(isset($result['multipleChoice'][0][$column]), "multipleChoice.$column");
    }
    Harness::ok(isset($result['multipleChoice'][0]['options']), 'multipleChoice.options');
    Harness::same(1, (int) $result['multipleChoice'][0]['marks']);
    Harness::same(5, (int) $result['shortAnswerQuestions'][0]['marks']);
});

Harness::test('a paper needing no units at all is refused, not run on min([])', function () {
    Harness::throws('at least one unit', fn() => fetchAllDistributed(
        Fixture::empty(),
        12,
        [],
        INTERNAL
    ));
});

Harness::test('sqlite_sequence is a real table and is still refused', function () {
    // AUTOINCREMENT creates it, so it is in the listing. A listing-only check
    // would have resolved it and left the failure to the SELECT.
    $pdo    = Fixture::withSupply(['multipleChoice' => ['1.1' => 1]]);
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")
                  ->fetchAll(PDO::FETCH_COLUMN);

    Harness::ok(in_array('sqlite_sequence', $tables, true), 'sqlite_sequence exists');
    Harness::throws('no question table named', fn() => resolveQuestionTable($pdo, 'sqlite_sequence'));
});

Harness::test('a table without the question columns is refused', function () {
    $pdo = Fixture::empty();
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, password TEXT)');
    Harness::throws('no question table named', fn() => resolveQuestionTable($pdo, 'users'));
});
