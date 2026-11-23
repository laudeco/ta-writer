<?php

declare(strict_types=1);

// Kernel tests build the kernel directly (no Runtime), so the .env files are loaded here.
// Variables already set by phpunit.xml.dist win over .env.
require dirname(__DIR__).'/vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__).'/.env');
