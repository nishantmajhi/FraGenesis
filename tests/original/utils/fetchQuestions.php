<?php
    /**
     * Fetches multiple question types in one pass, distributing questions across
     * units so that each unit receives as equal a share of TOTAL MARKS as possible.
     *
     * Algorithm: sort question types by marks descending, then greedily assign
     * each question to whichever unit currently has the lowest mark total.
     *
     * @param PDO    $pdo
     * @param int    $grade
     * @param array  $unitMap       Output of groupTopicsByUnit()
     * @param array  $questionTypes [
     *                   ['table' => 'multipleChoice',       'count' => 7, 'marks' => 1],
     *                   ['table' => 'shortAnswerQuestions', 'count' => 3, 'marks' => 5],
     *                   ['table' => 'longAnswerQuestions',  'count' => 1, 'marks' => 8],
     *               ]
     * @return array  Keyed by table name → array of fetched rows
     */
    function fetchAllDistributed(PDO $pdo, int $grade, array $unitMap, array $questionTypes): array {
        $unitKeys = array_keys($unitMap);
        shuffle($unitKeys);
        $numUnits  = count($unitKeys);
        $unitMarks = array_fill(0, $numUnits, 0);

        // Sort highest-mark types first so the greedy pass has maximum room to balance
        $sorted = $questionTypes;
        usort($sorted, fn($a, $b) => $b['marks'] <=> $a['marks']);

        // ── Phase 1: decide how many questions each unit gets per type ────
        $allocations = [];
        foreach ($sorted as $qt) {
            $alloc = array_fill(0, $numUnits, 0);
            for ($i = 0; $i < $qt['count']; $i++) {
                $minIdx = (int) array_search(min($unitMarks), $unitMarks);
                $alloc[$minIdx]++;
                $unitMarks[$minIdx] += $qt['marks'];
            }
            $allocations[$qt['table']] = $alloc;
        }

        // ── Phase 2: fetch rows according to the allocations ──────────────
        $results = [];
        foreach ($sorted as $qt) {
            $table   = $qt['table'];
            $alloc   = $allocations[$table];
            $fetched = [];

            foreach ($unitKeys as $i => $unitKey) {
                $need = $alloc[$i];
                if ($need === 0) continue;

                $topicsInUnit = $unitMap[$unitKey];
                $placeholders = implode(',', array_fill(0, count($topicsInUnit), '?'));
                $stmt = $pdo->prepare(
                    "SELECT * FROM $table
                     WHERE grade = ? AND topic IN ($placeholders)
                     ORDER BY RANDOM()
                     LIMIT $need"
                );
                $stmt->execute(array_merge([$grade], $topicsInUnit));
                $fetched = array_merge($fetched, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }

            // ── Backfill if a unit returned fewer rows than its quota ─────
            $stillNeeded = $qt['count'] - count($fetched);
            if ($stillNeeded > 0) {
                $fetchedIds = array_column($fetched, 'id');
                $allTopics  = array_merge(...array_values($unitMap));
                $topicPH    = implode(',', array_fill(0, count($allTopics), '?'));
                $excludePH  = empty($fetchedIds)
                    ? ''
                    : 'AND id NOT IN (' . implode(',', array_fill(0, count($fetchedIds), '?')) . ')';

                $stmt = $pdo->prepare(
                    "SELECT * FROM $table
                     WHERE grade = ? AND topic IN ($topicPH)
                     $excludePH
                     ORDER BY RANDOM()
                     LIMIT $stillNeeded"
                );
                $stmt->execute(array_merge([$grade], $allTopics, $fetchedIds));
                $fetched = array_merge($fetched, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }

            shuffle($fetched);
            $results[$table] = $fetched;
        }

        return $results;
    }