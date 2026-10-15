<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'allocate.php';

/** The three papers src/ generates, by the composition each asks for. */
const PAPERS = [
    'unit test'     => [
        ['table' => 'multipleChoice',       'count' => 5, 'marks' => 1],
        ['table' => 'shortAnswerQuestions', 'count' => 3, 'marks' => 5],
    ],
    'internal exam' => [
        ['table' => 'multipleChoice',       'count' => 7, 'marks' => 1],
        ['table' => 'shortAnswerQuestions', 'count' => 3, 'marks' => 5],
        ['table' => 'longAnswerQuestions',  'count' => 1, 'marks' => 8],
    ],
    'terminal exam' => [
        ['table' => 'multipleChoice',       'count' => 9, 'marks' => 1],
        ['table' => 'shortAnswerQuestions', 'count' => 5, 'marks' => 5],
        ['table' => 'longAnswerQuestions',  'count' => 2, 'marks' => 8],
    ],
];

Harness::test('every question asked for is allocated to exactly one unit', function () {
    foreach (PAPERS as $name => $types) {
        for ($units = 1; $units <= 9; $units++) {
            $plan = allocateByMarks($units, $types);
            foreach ($types as $qt) {
                Harness::same(
                    $qt['count'],
                    array_sum($plan['allocations'][$qt['table']]),
                    "$name, $units units, {$qt['table']}"
                );
            }
        }
    }
});

Harness::test('the marks allocated add up to the paper total', function () {
    foreach (PAPERS as $name => $types) {
        $paperMarks = 0;
        foreach ($types as $qt) {
            $paperMarks += $qt['count'] * $qt['marks'];
        }
        for ($units = 1; $units <= 9; $units++) {
            $plan = allocateByMarks($units, $types);
            Harness::same($paperMarks, array_sum($plan['unitMarks']), "$name, $units units");
        }
    }
});

Harness::test('the three papers total 20, 30 and 50 marks', function () {
    $totals = [];
    foreach (PAPERS as $name => $types) {
        $totals[$name] = array_sum(allocateByMarks(3, $types)['unitMarks']);
    }
    Harness::same(['unit test' => 20, 'internal exam' => 30, 'terminal exam' => 50], $totals);
});

Harness::test('no unit is allocated a negative number of questions', function () {
    foreach (PAPERS as $types) {
        for ($units = 1; $units <= 9; $units++) {
            foreach (allocateByMarks($units, $types)['allocations'] as $alloc) {
                foreach ($alloc as $n) {
                    Harness::ok($n >= 0);
                }
            }
        }
    }
});

Harness::test('allocation is per unit index, one entry each', function () {
    $plan = allocateByMarks(4, PAPERS['internal exam']);
    foreach ($plan['allocations'] as $table => $alloc) {
        Harness::same([0, 1, 2, 3], array_keys($alloc), $table);
    }
    Harness::same([0, 1, 2, 3], array_keys($plan['unitMarks']));
});

// ── the balancing claim ──────────────────────────────────────────────────────

Harness::test('the papers the site actually makes come out balanced', function () {
    // These are the exact numbers, not a tolerance, so a change to the
    // algorithm has to be looked at rather than absorbed.
    Harness::same([10, 10, 10], allocateByMarks(3, PAPERS['internal exam'])['unitMarks']);
    Harness::same([15, 15], allocateByMarks(2, PAPERS['internal exam'])['unitMarks']);
    Harness::same([8, 8, 7, 7], allocateByMarks(4, PAPERS['internal exam'])['unitMarks']);
    Harness::same([10, 10, 10, 10, 10], allocateByMarks(5, PAPERS['terminal exam'])['unitMarks']);
    Harness::same([7, 7, 6], allocateByMarks(3, PAPERS['unit test'])['unitMarks']);
});

Harness::test('sorting largest-mark-first is what makes it balance', function () {
    // The docblock in utils/allocate.php claims the sort order is load-bearing.
    // This is that claim as a number: same greedy, same inputs, other order.
    $types  = PAPERS['internal exam'];
    usort($types, fn($a, $b) => $a['marks'] <=> $b['marks']);

    $unitMarks = array_fill(0, 3, 0);
    foreach ($types as $qt) {
        for ($i = 0; $i < $qt['count']; $i++) {
            $unitMarks[leastLoadedUnit($unitMarks)] += $qt['marks'];
        }
    }

    Harness::same([8, 15, 7], $unitMarks, 'smallest-mark-first');
    Harness::same([10, 10, 10], allocateByMarks(3, PAPERS['internal exam'])['unitMarks'], 'largest-mark-first');
});

Harness::test('the spread never exceeds the largest question in the paper', function () {
    // The bound the greedy can actually promise. It cannot split an 8-mark
    // question, so 30 marks over 7 units comes out 8/5/5/5/3/2/2 — a spread of
    // 6, under the 8 that is the size of the indivisible lump.
    foreach (PAPERS as $name => $types) {
        $largest = max(array_column($types, 'marks'));
        for ($units = 1; $units <= 12; $units++) {
            $marks  = allocateByMarks($units, $types)['unitMarks'];
            $spread = max($marks) - min($marks);
            Harness::ok(
                $spread <= $largest,
                "$name over $units units: spread $spread exceeds the largest question, $largest"
            );
        }
    }
});

Harness::test('the spread bound holds for a few thousand random papers', function () {
    // Reading two implementations against each other finds one divergence in
    // three; generating inputs finds the rest. Seeded so a failure can be
    // reproduced exactly.
    mt_srand(20260919);

    $checked = 0;
    for ($case = 0; $case < 3000; $case++) {
        $units = mt_rand(1, 12);
        $types = [];
        $howMany = mt_rand(1, 4);
        for ($t = 0; $t < $howMany; $t++) {
            $types[] = [
                'table' => "t$t",
                'count' => mt_rand(0, 25),
                'marks' => mt_rand(1, 10),
            ];
        }

        $plan    = allocateByMarks($units, $types);
        $largest = max(array_column($types, 'marks'));
        $marks   = $plan['unitMarks'];
        $spread  = max($marks) - min($marks);

        $total = 0;
        foreach ($types as $qt) {
            $total += $qt['count'] * $qt['marks'];
            if ($qt['count'] !== array_sum($plan['allocations'][$qt['table']])) {
                Harness::ok(false, "case $case: {$qt['table']} lost questions");
                return;
            }
        }

        if ($total !== array_sum($marks)) {
            Harness::ok(false, "case $case: marks do not add up");
            return;
        }

        // The bound only binds once every unit has something; with more units
        // than questions some units are necessarily empty.
        if ($spread > $largest && min($marks) > 0) {
            Harness::ok(false, sprintf(
                'case %d: %d units, spread %d exceeds largest question %d (%s)',
                $case, $units, $spread, $largest, json_encode($types)
            ));
            return;
        }

        $checked++;
    }

    Harness::same(3000, $checked, 'cases checked');
});

Harness::test('a question type asking for nothing gets nothing', function () {
    $plan = allocateByMarks(3, [
        ['table' => 'multipleChoice',       'count' => 0, 'marks' => 1],
        ['table' => 'shortAnswerQuestions', 'count' => 2, 'marks' => 5],
    ]);
    Harness::same([0, 0, 0], $plan['allocations']['multipleChoice']);
    Harness::same(10, array_sum($plan['unitMarks']));
});

Harness::test('an empty paper allocates nothing and balances trivially', function () {
    $plan = allocateByMarks(3, []);
    Harness::same([], $plan['allocations']);
    Harness::same([0, 0, 0], $plan['unitMarks']);
});

// ── the guards ───────────────────────────────────────────────────────────────

Harness::test('no units is refused rather than reaching min() on an empty array', function () {
    // The original had no guard. min([]) is a ValueError, and a ValueError is an
    // Error, so the try/catch around the call site did not catch it: the request
    // would have died with a stack trace.
    Harness::throws('at least one unit', fn() => allocateByMarks(0, PAPERS['unit test']));
    Harness::throws('at least one unit', fn() => allocateByMarks(-1, PAPERS['unit test']));
});

Harness::test('a fractional count is refused rather than allocated', function () {
    // The original looped `for ($i = 0; $i < 3.2; $i++)`, allocating four
    // questions for a request of 3.2, and then interpolated the leftover 0.2
    // into a SQL LIMIT.
    Harness::throws('non-negative integer', fn() => allocateByMarks(
        2,
        [['table' => 'multipleChoice', 'count' => 3.2, 'marks' => 1]]
    ));
    Harness::throws('non-negative integer', fn() => allocateByMarks(
        2,
        [['table' => 'multipleChoice', 'count' => '5', 'marks' => 1]]
    ));
    Harness::throws('non-negative integer', fn() => allocateByMarks(
        2,
        [['table' => 'multipleChoice', 'count' => -1, 'marks' => 1]]
    ));
});

Harness::test('a malformed question type is refused', function () {
    Harness::throws('needs a table', fn() => allocateByMarks(2, [['count' => 1, 'marks' => 1]]));
    Harness::throws('needs a table', fn() => allocateByMarks(2, [['table' => 'x', 'marks' => 1]]));
    Harness::throws('non-negative integer', fn() => allocateByMarks(
        2,
        [['table' => 'x', 'count' => 1, 'marks' => 1.5]]
    ));
});

Harness::test('two types on one table are refused rather than overwriting', function () {
    // allocations is keyed by table, so the second would silently replace the
    // first. No paper does this today.
    Harness::throws('share the table', fn() => allocateByMarks(2, [
        ['table' => 'shortAnswerQuestions', 'count' => 1, 'marks' => 5],
        ['table' => 'shortAnswerQuestions', 'count' => 2, 'marks' => 3],
    ]));
});

Harness::test('leastLoadedUnit picks the minimum and breaks ties low', function () {
    Harness::same(0, leastLoadedUnit([0, 0, 0]));
    Harness::same(2, leastLoadedUnit([5, 5, 1, 5]));
    Harness::same(1, leastLoadedUnit([8, 3, 3]));
    Harness::same(0, leastLoadedUnit([7]));
});
