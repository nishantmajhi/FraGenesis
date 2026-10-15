<?php

/**
 * index.php
 * Entry point for database setup and question import.
 *
 * Usage:
 *   php index.php            → generate JSON (if missing), then import all
 *   php index.php 12-1.json  → generate JSON (if missing), then import a single file
 *   php index.php --fresh    → regenerate all JSON, drop tables, re-import everything
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'DatabaseInitializer.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'QuestionImporter.php';

// ── Parse arguments ───────────────────────────────────────────────────────
$fresh = false;
$args  = array_slice($argv, 1);

if (($pos = array_search('--fresh', $args)) !== false) {
    $fresh = true;
    array_splice($args, $pos, 1);
}

// ── Confirm before wiping data ────────────────────────────────────────────
if ($fresh) {
    echo "[!] WARNING: --fresh will regenerate all JSON, drop all tables, and re-import everything.\n";
    echo "    Are you sure? (yes/no): ";
    $confirm = strtolower(trim(fgets(STDIN)));
    if ($confirm !== 'yes') {
        echo "[✗] Aborted.\n";
        exit(0);
    }
}

// ── Step 1: Generate JSON from raw .txt files via the Perl script ─────────
$perlScript = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'generate_12_json.pl';
$jsonDir    = __DIR__ . DIRECTORY_SEPARATOR . 'data';

if (!file_exists($perlScript)) {
    echo "[!] Perl script not found at: $perlScript — skipping JSON generation.\n";
} else {
    // Check whether any JSON files are already present
    $existingJson = glob($jsonDir . DIRECTORY_SEPARATOR . '*.json');
    $needsGenerate = $fresh || empty($existingJson);

    if ($needsGenerate) {
        $reason = $fresh ? '--fresh flag' : 'no JSON files found';
        echo "[→] Generating JSON files ($reason)...\n";

        $cmd      = 'perl ' . escapeshellarg($perlScript);
        $output   = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        foreach ($output as $line) {
            echo "    $line\n";
        }

        if ($exitCode !== 0) {
            echo "[✗] Perl script exited with code $exitCode. Aborting.\n";
            exit(1);
        }

        echo "[✓] JSON generation complete.\n\n";
    } else {
        echo "[✓] JSON files already present — skipping generation (use --fresh to regenerate).\n\n";
    }
}

// ── Step 2: Initialize the database ──────────────────────────────────────
try {
    $initializer = new DatabaseInitializer($fresh);

    // ── Step 3: Import ────────────────────────────────────────────────────
    $importer = new QuestionImporter($initializer->getPdo());

    if (!empty($args)) {
        $importer->importFile($args[0]);
    } else {
        $importer->importAllFiles();
    }
} catch (Exception $e) {
    echo "[✗] Fatal Error: " . $e->getMessage() . "\n";
    exit(1);
}