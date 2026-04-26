<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use FileManager\App;

$startDir = $argv[1] ?? ($_SERVER['HOME'] ?? getcwd() ?: '/');

(new App($startDir))->run();
