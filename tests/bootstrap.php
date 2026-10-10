<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if (!isset($_SERVER['APP_ENV'], $_ENV['APP_ENV'])) {
    $_SERVER['APP_ENV'] = 'test';
    $_ENV['APP_ENV'] = 'test';
}

if (!isset($_SERVER['KERNEL_CLASS'], $_ENV['KERNEL_CLASS'])) {
    $_SERVER['KERNEL_CLASS'] = 'App\\Kernel';
    $_ENV['KERNEL_CLASS'] = 'App\\Kernel';
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
