<?php
    /**
     * The allocation decision, with no database in it.
     *
     * This is phase one of what used to be fetchAllDistributed(): given how
     * many units a paper draws on and what question types it is made of, decide
     * how many questions of each type each unit supplies. Phase two — turning
     * that into SELECTs — stayed in utils/fetchQuestions.php.
     *
     * Splitting it out is the only way the algorithm can be tested, and it is
     * the interesting part of this project: the balancing property below is a
     * real claim that can now be checked against a few thousand random inputs
     * rather than read and believed.
     */

    /**
     * Decides how many questions of each type come from each unit, so that
     * every unit carries as near an equal share of the paper's TOTAL MARKS as
     * the question sizes allow.
     *
     * Greedy: take the question types largest-mark-first, and give each
     * individual question to whichever unit has the lowest running mark total.
     *
     * The sort order is load-bearing and is the part that is not obvious.
     * Marks come in indivisible lumps — 8, 5 and 1 in this project — and a
     * greedy pass can only correct an imbalance with the lumps it has left. Go
     * largest-first and the 8s land on empty units and the 1s fill the gaps to
     * within a mark or two. Go smallest-first and the 1s spread out evenly
     * first, leaving nothing small enough to offset the 8s: a 30-mark internal
     * paper over three units comes out 8/15/7 instead of 10/10/10.
     * tests/AllocateTest.php pins both numbers.
     *
     * The balance the greedy can reach is bounded by the largest question, not
     * by the algorithm: a 30-mark paper containing one 8-mark question cannot be
     * split evenly over seven units, and this returns 8/5/5/5/3/2/2 for that
     * case. That is the best available, not a defect.
     *
     * @param int   $numUnits      How many units the paper draws on. At least 1.
     * @param array $questionTypes [['table' => string, 'count' => int,
     *                               'marks' => int], ...]. Counts and marks must
     *                              be non-negative integers.
     * @return array{types: array, allocations: array<string, int[]>, unitMarks: int[]}
     *               `types` is $questionTypes in the order they were allocated;
     *               `allocations[$table][$unitIndex]` is a question count;
     *               `unitMarks[$unitIndex]` is the marks that unit ended with.
     * @throws InvalidArgumentException if the inputs cannot describe a paper.
     */
    function allocateByMarks(int $numUnits, array $questionTypes): array {
        // The original had no guard here, and min() on an empty array is a
        // ValueError — which is an Error, not an Exception, so the try/catch
        // around the call site would not have caught it.
        if ($numUnits < 1) {
            throw new InvalidArgumentException('a paper needs at least one unit');
        }

        foreach ($questionTypes as $qt) {
            if (!isset($qt['table'], $qt['count'], $qt['marks'])) {
                throw new InvalidArgumentException('a question type needs a table, a count and a marks value');
            }
            // Integers, not "numeric". A fractional count made the original
            // loop ceil(count) times and then interpolate the fractional
            // remainder into a SQL LIMIT, which SQLite rejects outright.
            if (!is_int($qt['count']) || $qt['count'] < 0) {
                throw new InvalidArgumentException('a question count must be a non-negative integer');
            }
            if (!is_int($qt['marks']) || $qt['marks'] < 0) {
                throw new InvalidArgumentException('a marks value must be a non-negative integer');
            }
        }

        $unitMarks = array_fill(0, $numUnits, 0);

        $sorted = $questionTypes;
        usort($sorted, fn($a, $b) => $b['marks'] <=> $a['marks']);

        $allocations = [];
        foreach ($sorted as $qt) {
            $alloc = array_fill(0, $numUnits, 0);
            for ($i = 0; $i < $qt['count']; $i++) {
                $minIdx = leastLoadedUnit($unitMarks);
                $alloc[$minIdx]++;
                $unitMarks[$minIdx] += $qt['marks'];
            }
            // Keyed by table, so two types drawing on the same table would
            // overwrite one another. No paper does that today; the assertion
            // makes it fail loudly rather than silently if one ever does.
            if (isset($allocations[$qt['table']])) {
                throw new InvalidArgumentException("two question types share the table {$qt['table']}");
            }
            $allocations[$qt['table']] = $alloc;
        }

        return [
            'types'       => $sorted,
            'allocations' => $allocations,
            'unitMarks'   => $unitMarks,
        ];
    }

    /**
     * Index of the unit with the fewest marks so far, ties going to the lowest
     * index.
     *
     * The tie-break is a real bias — with every unit on zero the first question
     * of the largest type always lands on unit 0 — and the caller neutralises it
     * by shuffling the unit order before it gets here, not by randomising in
     * this function. Keeping the decision deterministic is what makes it
     * testable.
     */
    function leastLoadedUnit(array $unitMarks): int {
        $minIdx = 0;
        foreach ($unitMarks as $i => $marks) {
            if ($marks < $unitMarks[$minIdx]) {
                $minIdx = $i;
            }
        }
        return $minIdx;
    }
