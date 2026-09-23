<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-Bridge-Telegram project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@valgorithms.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Bridge\Telegram\Tests\Doubles;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Telegram\Http\HttpInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * The Bot API, as far as a test needs it: every call recorded, every answer
 * scripted, nothing on the network.
 *
 * `getUpdates` never answers, so a started client sits in its first long poll
 * for the rest of the test rather than spinning.
 */
final class FakeHttp implements HttpInterface
{
    /** A token-shaped string, for proving it never escapes. */
    public const TOKEN = '123456789:AAHfiqksKZ8WmR2zSjiQ7_v4TMAKdiHm9T0';

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, mixed> method => the result, or a Throwable to reject with, or a callable given the content */
    public array $answers = [];

    /** @var array<string, string|\Throwable> file_path => bytes */
    public array $files = [];

    public function execute(string $method, array $content = []): PromiseInterface
    {
        $this->calls[] = [$method, $content];

        if ($method === 'getUpdates') {
            return (new Deferred())->promise();
        }

        $answer = $this->answers[$method] ?? true;

        if (is_callable($answer)) {
            $answer = $answer($content);
        }

        return $answer instanceof \Throwable ? reject($answer) : resolve($answer);
    }

    public function download(string $filePath): PromiseInterface
    {
        $file = $this->files[$filePath] ?? new \RuntimeException(
            'GET https://api.telegram.org/file/bot' . self::TOKEN . '/' . $filePath . ' failed',
        );

        return $file instanceof \Throwable ? reject($file) : resolve($file);
    }

    public function fileUrl(string $filePath): string
    {
        return 'https://api.telegram.org/file/bot' . self::TOKEN . '/' . $filePath;
    }

    public function setToken(string $token): void
    {
    }

    /**
     * Every call to one method, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function callsTo(string $method): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call[1],
            array_filter($this->calls, static fn (array $call): bool => $call[0] === $method),
        ));
    }
}
