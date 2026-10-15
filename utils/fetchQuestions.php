<?php
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'allocate.php';

    /**
     * Fetches multiple question types in one pass, distributing questions across
     * units so that each unit receives as equal a share of TOTAL MARKS as
     * possible.
     *
     * The decision of how many questions each unit supplies is
     * allocateByMarks() in utils/allocate.php, which is where the algorithm and
     * its reasoning now live. What is left here is the part that talks to
     * SQLite: turn an allocation into SELECTs, and make up any shortfall.
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

        // Shuffled so the deterministic tie-break inside allocateByMarks() —
        // which always gives the first of several equally loaded units the next
        // question — is not always the same unit.
        shuffle($unitKeys);

        $plan = allocateByMarks(count($unitKeys), $questionTypes);

        $results = [];
        foreach ($plan['types'] as $qt) {
            // The table name is resolved through the list of tables the
            // database itself reports, and the string that goes into the SQL is
            // the one that came back from that list — never the caller's. The
            // caller's value is only ever compared. That is what makes the
            // interpolation safe by construction rather than safe because of
            // where the callers happen to get their table names from.
            $table   = resolveQuestionTable($pdo, $qt['table']);
            $alloc   = $plan['allocations'][$qt['table']];
            $fetched = [];

            foreach ($unitKeys as $i => $unitKey) {
                $need = $alloc[$i];
                if ($need === 0) continue;

                $topicsInUnit = $unitMap[$unitKey];
                $placeholders = implode(',', array_fill(0, count($topicsInUnit), '?'));

                // (int) on a value allocateByMarks() has already guaranteed is
                // an int. Belt and braces, and it documents that nothing but an
                // integer may ever be interpolated here: a fractional LIMIT is
                // not a soft failure, SQLite answers "datatype mismatch".
                $stmt = $pdo->prepare(
                    "SELECT * FROM $table
                     WHERE grade = ? AND topic IN ($placeholders)
                     ORDER BY RANDOM()
                     LIMIT " . (int) $need
                );
                $stmt->execute(array_merge([$grade], $topicsInUnit));
                $fetched = array_merge($fetched, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }

            // ── Backfill if a unit returned fewer rows than its quota ─────
            // One pass is enough: this query is not limited to a unit, so it can
            // make up any shortfall from anywhere in the selected topics. It
            // trades the balance the allocation achieved for a paper of the
            // requested length, which is the right way round — a short paper is
            // the more visible failure.
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
                     LIMIT " . (int) $stillNeeded
                );
                $stmt->execute(array_merge([$grade], $allTopics, $fetchedIds));
                $fetched = array_merge($fetched, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }

            shuffle($fetched);
            $results[$table] = $fetched;
        }

        return $results;
    }

    /** Columns a table must have for the queries above to be able to use it. */
    const REQUIRED_QUESTION_COLUMNS = ['id', 'grade', 'topic', 'question', 'marks'];

    /**
     * Returns $wanted if the database has a question table by exactly that name.
     *
     * The returned value is the name as the database spells it, so the caller
     * interpolates a string it read out of sqlite_master and not one it was
     * handed. There is no `..` check, no quoting and no forbidden-character list
     * here, because none is needed: a name that is not in the listing is
     * refused, and a name that is in the listing is a table this query can work
     * against.
     *
     * "Question table" and not merely "table" for a reason. A schema with
     * AUTOINCREMENT in it also contains sqlite_sequence, so a listing of every
     * table would resolve that too — and the resulting SELECT would fail on its
     * missing columns rather than being refused up front.
     *
     * @throws InvalidArgumentException if there is no such table.
     */
    function resolveQuestionTable(PDO $pdo, mixed $wanted): string {
        if (!is_string($wanted)) {
            throw new InvalidArgumentException('a table name must be a string');
        }

        // Deliberately not cached in a static. sqlite_master is a handful of
        // rows, a paper asks at most three times, and a cache would have to be
        // keyed by connection — a test suite that opens several in-memory
        // databases is exactly where a connection-blind cache answers for the
        // wrong one.
        $tables = $pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);

        $found = array_search($wanted, $tables, true);
        if ($found === false) {
            throw new InvalidArgumentException("no question table named $wanted");
        }

        $table = $tables[$found];

        // PRAGMA takes no bound parameters, so the name is quoted — and it is
        // the name from the listing, which is why quoting it is enough.
        $columns = $pdo
            ->query('PRAGMA table_info(' . $pdo->quote($table) . ')')
            ->fetchAll(PDO::FETCH_COLUMN, 1);

        foreach (REQUIRED_QUESTION_COLUMNS as $column) {
            if (!in_array($column, $columns, true)) {
                throw new InvalidArgumentException("no question table named $wanted");
            }
        }

        return $table;
    }
