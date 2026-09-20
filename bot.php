<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-TelegramRelay project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@valgorithms.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  php bot.php
 *
 *  Configuration comes from .env (see env.example). Bridges are configured
 *  from inside Discord with /telegram and live in var/relay.json.
 */

// Walk up for the autoloader so a PHPacker binary finds it wherever it runs.
$baseDir = __DIR__;
while (! is_file($baseDir . '/vendor/autoload.php')) {
    $parent = \dirname($baseDir);
    if ($parent === $baseDir) {
        fwrite(STDERR, "Could not find vendor/autoload.php — run `composer install`.\n");
        exit(1);
    }
    $baseDir = $parent;
}
require $baseDir . '/vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use TelegramRelay\Config;
use TelegramRelay\Modules\Bridge;
use TelegramRelay\Modules\Configuration;
use TelegramRelay\Modules\Controls;
use TelegramRelay\Modules\Startup;
use TelegramRelay\Relay;
use TelegramRelay\Store;

try {
    $config = Config::fromEnvironment($baseDir . '/.env', $baseDir . '/var/relay.json');
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$logger = new Logger('relay');
$logger->pushHandler(new StreamHandler(
    'php://stdout',
    Level::fromName(ucfirst($config->logLevel)) ?? Level::Info,
));

$relay = new Relay($config, new Store($config->storePath), ['logger' => $logger]);

$relay
    ->addModule(new Configuration())
    ->addModule(new Controls())
    ->addModule(new Bridge())
    // Last, so it reports on a bridge that is already listening.
    ->addModule(new Startup());

$relay->on('ready', static function (Relay $bot): void {
    $bot->logger->info(sprintf('[relay] logged into Discord as %s', (string) $bot->user->username));
});

// Ctrl-C should stop polling Telegram too, not leave a long-poll dangling.
foreach ([\defined('SIGINT') ? SIGINT : null, \defined('SIGTERM') ? SIGTERM : null] as $signal) {
    if ($signal !== null && function_exists('pcntl_signal')) {
        $relay->getLoop()->addSignal($signal, static function () use ($relay): void {
            $relay->logger->info('[relay] shutting down');
            $relay->getTelegram()->stop();
            $relay->close();
        });
    }
}

$relay->run();
