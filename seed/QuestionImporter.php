<?php

/**
 * QuestionImporter
 * Responsible for reading JSON files and inserting questions into the database.
 * Requires an initialized PDO connection — does not manage the database itself.
 *
 * JSON file format: <grade>-<unit>.json (e.g., 11-1.json, 12-1.json)
 */
class QuestionImporter
{
    private PDO $pdo;
    private string $jsonDir;

    public function __construct(PDO $pdo)
    {
        $this->pdo     = $pdo;
        $this->jsonDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
    }

    /**
     * Parse grade and unit from filename.
     * Expected format: <grade>-<unit>.json
     */
    private function parseFilename(string $filename): ?array
    {
        if (preg_match('/^(\d+)-(\d+)\.json$/', $filename, $matches)) {
            return [
                'grade' => (int) $matches[1],
                'unit'  => (int) $matches[2],
            ];
        }
        return null;
    }

    /**
     * Read and decode a JSON file.
     */
    private function readJsonFile(string $filepath): array
    {
        if (!file_exists($filepath)) {
            throw new Exception("File not found: $filepath");
        }

        $data = json_decode(file_get_contents($filepath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON parse error: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Insert multiple choice questions.
     */
    private function insertMultipleChoice(int $grade, int $unit, array $data): int
    {
        if (!isset($data['multipleChoice']['questions'])) {
            return 0;
        }

        $marks = $data['multipleChoice']['marks'] ?? 1;
        $stmt  = $this->pdo->prepare("
            INSERT INTO multipleChoice (grade, unit, topic, question_id, question, options, marks)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $count = 0;
        foreach ($data['multipleChoice']['questions'] as $q) {
            $stmt->execute([
                $grade,
                $unit,
                $q['topic'] ?? null,
                $q['id'],
                $q['question'],
                json_encode($q['options']),
                $marks,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Insert short answer questions.
     */
    private function insertShortAnswerQuestions(int $grade, int $unit, array $data): int
    {
        if (!isset($data['shortAnswerQuestions']['questions'])) {
            return 0;
        }

        $marks = $data['shortAnswerQuestions']['marks'] ?? 5;
        $stmt  = $this->pdo->prepare("
            INSERT INTO shortAnswerQuestions (grade, unit, topic, question_id, question, marks)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $count = 0;
        foreach ($data['shortAnswerQuestions']['questions'] as $q) {
            $stmt->execute([
                $grade,
                $unit,
                $q['topic'] ?? null,
                $q['id'],
                $q['question'],
                $marks,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Insert long answer questions.
     */
    private function insertLongAnswerQuestions(int $grade, int $unit, array $data): int
    {
        if (!isset($data['longAnswerQuestions']['questions'])) {
            return 0;
        }

        $marks = $data['longAnswerQuestions']['marks'] ?? 8;
        $stmt  = $this->pdo->prepare("
            INSERT INTO longAnswerQuestions (grade, unit, topic, question_id, question, marks)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $count = 0;
        foreach ($data['longAnswerQuestions']['questions'] as $q) {
            $stmt->execute([
                $grade,
                $unit,
                $q['topic'] ?? null,
                $q['id'],
                $q['question'],
                $marks,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Insert short notes topics.
     */
    private function insertShortNotes(int $grade, int $unit, array $data): int
    {
        if (!isset($data['shortNotes']['topics'])) {
            return 0;
        }

        $marks = $data['shortNotes']['marks'] ?? 2.5;
        $stmt  = $this->pdo->prepare("
            INSERT INTO shortNotes (grade, unit, topic_id, topic, marks)
            VALUES (?, ?, ?, ?, ?)
        ");

        $count = 0;
        foreach ($data['shortNotes']['topics'] as $t) {
            $stmt->execute([
                $grade,
                $unit,
                $t['id'],
                $t['topic'],
                $marks,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Import a single JSON file by filename.
     */
    public function importFile(string $filename): bool
    {
        try {
            $parsed = $this->parseFilename($filename);
            if (!$parsed) {
                echo "[✗] Invalid filename format: $filename (Expected: <grade>-<unit>.json)\n";
                return false;
            }

            $grade    = $parsed['grade'];
            $unit     = $parsed['unit'];
            $filepath = $this->jsonDir . DIRECTORY_SEPARATOR . $filename;

            echo "\n[→] Processing: $filename (Grade $grade, Unit $unit)\n";

            $data = $this->readJsonFile($filepath);

            $mcCount  = $this->insertMultipleChoice($grade, $unit, $data);
            echo "   ✓ Multiple Choice Questions : $mcCount inserted\n";

            $saqCount = $this->insertShortAnswerQuestions($grade, $unit, $data);
            echo "   ✓ Short Answer Questions    : $saqCount inserted\n";

            $laqCount = $this->insertLongAnswerQuestions($grade, $unit, $data);
            echo "   ✓ Long Answer Questions     : $laqCount inserted\n";

            $snCount  = $this->insertShortNotes($grade, $unit, $data);
            echo "   ✓ Short Notes               : $snCount inserted\n";

            echo "   [✓] Total: " . ($mcCount + $saqCount + $laqCount + $snCount) . " items inserted\n";

            return true;
        } catch (Exception $e) {
            echo "[✗] Error processing $filename: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Import all JSON files found in the data directory.
     */
    public function importAllFiles(): void
    {
        if (!is_dir($this->jsonDir)) {
            mkdir($this->jsonDir, 0755, true);
            echo "[!] Data directory created: {$this->jsonDir}\n";
            echo "[!] Please place JSON files there and re-run.\n";
            return;
        }

        $files = glob($this->jsonDir . DIRECTORY_SEPARATOR . '*.json');
        if (empty($files)) {
            echo "[!] No JSON files found in: {$this->jsonDir}\n";
            return;
        }

        $success = 0;
        $failed  = 0;

        foreach ($files as $filepath) {
            $this->importFile(basename($filepath)) ? $success++ : $failed++;
        }

        echo "\n" . str_repeat("=", 50) . "\n";
        echo "Import Summary:\n";
        echo "  ✓ Successful : $success\n";
        echo "  ✗ Failed     : $failed\n";
        echo str_repeat("=", 50) . "\n";
    }
}