<?php

/**
 * @file tests/locale-check.php
 *
 * Copyright (c) 2025 Hendrix Nwaokolo, Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief CI check: every plugin locale key referenced in PHP/template source
 * exists in the locale .po files with a non-empty translation, and the .po
 * files contain no empty msgstr entries.
 *
 * Usage: php tests/locale-check.php   (exit code 0 = pass)
 */

$root = dirname(__DIR__);
$prefixes = [
    'plugins\.paymethod\.paystack\.[A-Za-z0-9_.]+',
    'mailable\.paystack\.[A-Za-z0-9_.]+',
    'emails\.paystack\.[A-Za-z0-9_.]+',
];

// ── Collect keys defined across all locale .po files ─────────────────────────
$defined = [];
$emptyTranslations = [];
foreach (glob($root . '/locale/*/*.po') as $po) {
    $lines = file($po, FILE_IGNORE_NEW_LINES);
    $currentKey = null;
    foreach ($lines as $i => $line) {
        if (preg_match('/^msgid "(.+)"$/', $line, $m)) {
            $currentKey = $m[1];
            continue;
        }
        if ($currentKey !== null && preg_match('/^msgstr "(.*)"$/', $line, $m)) {
            $defined[$currentKey] = true;
            // Multi-line msgstr: a bare quoted line follows; treat as non-empty.
            $next = $lines[$i + 1] ?? '';
            if ($m[1] === '' && !preg_match('/^"/', $next)) {
                $emptyTranslations[] = basename($po) . ': ' . $currentKey;
            }
            $currentKey = null;
        }
    }
}

// ── Collect keys referenced in source ────────────────────────────────────────
$used = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (preg_match('#/(\.git|tests|node_modules)/#', $path)) {
        continue;
    }
    if (!preg_match('/\.(php|tpl|xml)$/', $path)) {
        continue;
    }
    $contents = file_get_contents($path);
    foreach ($prefixes as $prefix) {
        if (preg_match_all('/' . $prefix . '/', $contents, $m)) {
            foreach ($m[0] as $key) {
                $used[rtrim($key, '.')][] = substr($path, strlen($root) + 1);
            }
        }
    }
}

// ── Report ───────────────────────────────────────────────────────────────────
$errors = 0;
foreach ($used as $key => $files) {
    if (!isset($defined[$key])) {
        fwrite(STDERR, "MISSING: {$key}  (used in " . implode(', ', array_unique($files)) . ")\n");
        $errors++;
    }
}
foreach ($emptyTranslations as $entry) {
    fwrite(STDERR, "EMPTY: {$entry}\n");
    $errors++;
}

if ($errors > 0) {
    fwrite(STDERR, "\n{$errors} locale problem(s) found.\n");
    exit(1);
}
echo 'Locale check passed: ' . count($used) . " keys used, all defined.\n";
