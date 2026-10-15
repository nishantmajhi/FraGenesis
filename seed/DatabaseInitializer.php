<?php

/**
 * DatabaseInitializer
 * Responsible for creating the SQLite database and all required tables.
 */
class DatabaseInitializer
{
    private string $dbPath;
    private PDO $pdo;

    public function __construct(bool $fresh = false)
    {
        $this->dbPath = dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'questions.sqlite3';
        $this->initialize($fresh);
    }

    /**
     * Drop all tables so they can be recreated with the current schema.
     */
    private function dropTables(): void
    {
        $tables = ['multipleChoice', 'shortAnswerQuestions', 'longAnswerQuestions', 'shortNotes'];
        foreach ($tables as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS $table");
        }
        echo "[!] All tables dropped.\n";
    }

    /**
     * Create the database connection and all tables.
     */
    private function initialize(bool $fresh): void
    {
        try {
            $this->pdo = new PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            if ($fresh) {
                $this->dropTables();
            }

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS multipleChoice (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    grade       INTEGER NOT NULL,
                    unit        INTEGER NOT NULL,
                    topic       TEXT,
                    question_id INTEGER NOT NULL,
                    question    TEXT    NOT NULL,
                    options     TEXT    NOT NULL,
                    marks       INTEGER NOT NULL
                )
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS shortAnswerQuestions (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    grade       INTEGER NOT NULL,
                    unit        INTEGER NOT NULL,
                    topic       TEXT,
                    question_id INTEGER NOT NULL,
                    question    TEXT    NOT NULL,
                    marks       INTEGER NOT NULL
                )
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS longAnswerQuestions (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    grade       INTEGER NOT NULL,
                    unit        INTEGER NOT NULL,
                    topic       TEXT,
                    question_id INTEGER NOT NULL,
                    question    TEXT    NOT NULL,
                    marks       INTEGER NOT NULL
                )
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS shortNotes (
                    id       INTEGER PRIMARY KEY AUTOINCREMENT,
                    grade    INTEGER NOT NULL,
                    unit     INTEGER NOT NULL,
                    topic_id INTEGER NOT NULL,
                    topic    TEXT    NOT NULL,
                    marks    REAL    NOT NULL
                )
            ");

            echo "[✓] Database initialized successfully.\n";
        } catch (PDOException $e) {
            die("[✗] Database Error: " . $e->getMessage() . "\n");
        }
    }

    /**
     * Return the active PDO connection for use by other classes.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}