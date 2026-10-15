<?php

declare(strict_types=1);

/**
 * In-memory question banks, built with the same schema as seed/.
 *
 * Nothing in this suite reads db/questions.sqlite3. That file holds a real
 * imported question bank and the seeder that rebuilds it drops its tables, so a
 * suite that touched it would be one accidental argument away from destroying
 * data it does not own.
 */
final class Fixture
{
    /**
     * The schema, copied from seed/DatabaseInitializer.php. If those two ever
     * diverge, SchemaTest.php fails.
     */
    public const SCHEMA = [
        'multipleChoice' => 'CREATE TABLE multipleChoice (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                grade       INTEGER NOT NULL,
                unit        INTEGER NOT NULL,
                topic       TEXT,
                question_id INTEGER NOT NULL,
                question    TEXT    NOT NULL,
                options     TEXT    NOT NULL,
                marks       INTEGER NOT NULL
            )',
        'shortAnswerQuestions' => 'CREATE TABLE shortAnswerQuestions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                grade       INTEGER NOT NULL,
                unit        INTEGER NOT NULL,
                topic       TEXT,
                question_id INTEGER NOT NULL,
                question    TEXT    NOT NULL,
                marks       INTEGER NOT NULL
            )',
        'longAnswerQuestions' => 'CREATE TABLE longAnswerQuestions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                grade       INTEGER NOT NULL,
                unit        INTEGER NOT NULL,
                topic       TEXT,
                question_id INTEGER NOT NULL,
                question    TEXT    NOT NULL,
                marks       INTEGER NOT NULL
            )',
    ];

    /** Marks each table's questions are worth, as the papers price them. */
    public const MARKS = [
        'multipleChoice'       => 1,
        'shortAnswerQuestions' => 5,
        'longAnswerQuestions'  => 8,
    ];

    /** An empty database with the three question tables in it. */
    public static function empty(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        foreach (self::SCHEMA as $sql) {
            $pdo->exec($sql);
        }

        return $pdo;
    }

    /**
     * A database stocked to order.
     *
     * @param array $supply ['multipleChoice' => ['1.1' => 4, '2.1' => 3], ...]
     *                      — table, then topic, then how many questions.
     * @param int   $grade
     */
    public static function withSupply(array $supply, int $grade = 12): PDO
    {
        $pdo = self::empty();

        foreach ($supply as $table => $topics) {
            $hasOptions = $table === 'multipleChoice';
            $columns    = $hasOptions
                ? '(grade, unit, topic, question_id, question, options, marks)'
                : '(grade, unit, topic, question_id, question, marks)';
            $values     = $hasOptions ? '(?, ?, ?, ?, ?, ?, ?)' : '(?, ?, ?, ?, ?, ?)';
            $stmt       = $pdo->prepare("INSERT INTO $table $columns VALUES $values");

            foreach ($topics as $topic => $count) {
                $unit = (int) explode('.', (string) $topic)[0];

                for ($i = 1; $i <= $count; $i++) {
                    $row = [$grade, $unit, (string) $topic, $i, "$table $topic #$i"];
                    if ($hasOptions) {
                        $row[] = json_encode(["opt $i a", "opt $i b"]);
                    }
                    $row[] = self::MARKS[$table];
                    $stmt->execute($row);
                }
            }
        }

        return $pdo;
    }

    /** Question rows shaped the way fetchAllDistributed() returns them. */
    public static function rows(string $table, int $count, int $marks): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $row = [
                'id'       => $i,
                'grade'    => 12,
                'unit'     => 1,
                'topic'    => '1.1',
                'question' => "$table question $i",
                'marks'    => $marks,
            ];
            if ($table === 'multipleChoice') {
                $row['options'] = json_encode(["a$i", "b$i"]);
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
