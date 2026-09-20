<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-TelegramRelay project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@valgorithms.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace TelegramRelay;

/**
 * Everything the bridge needs to start, read from the environment.
 *
 * Secrets only ever come from the environment — never from the JSON store,
 * which is runtime state a server admin edits through `/telegram` and which is
 * safe to inspect or check into a backup.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Config
{
    private function __construct(
        public readonly string $discordToken,
        public readonly string $telegramToken,
        public readonly ?string $discordOwnerId,
        public readonly ?string $caBundle,
        public readonly ?string $telegramBaseUrl,
        public readonly string $storePath,
        public readonly string $envPath,
        public readonly string $logLevel,
    ) {
    }

    /**
     * The `socket_options` TelegramPHP should connect with.
     *
     * Only ever a CA bundle. A Windows PHP build typically has no
     * `openssl.cafile`, so TLS to `api.telegram.org` fails outright; pointing
     * the connector at a `cacert.pem` is the fix. Everything else is left at
     * ReactPHP's defaults — in particular, verification is never disabled.
     *
     * @return array<string, mixed>
     */
    public function socketOptions(): array
    {
        return $this->caBundle === null ? [] : ['tls' => ['cafile' => $this->caBundle]];
    }

    /**
     * Reads a `.env`-style file (if present) then the process environment,
     * which wins — so a container can override a file without editing it.
     *
     * @throws \RuntimeException when a required value is missing.
     */
    public static function fromEnvironment(string $envPath, string $storePath): self
    {
        $file = [];

        if (is_file($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || $trimmed[0] === '#' || ! str_contains($trimmed, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $trimmed, 2);
                $file[trim($key)] = trim($value, " \t\"'");
            }
        }

        $get = static function (string $key) use ($file): ?string {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }

            $value = $file[$key] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        };

        $required = static function (string $key) use ($get): string {
            $value = $get($key);
            if ($value === null) {
                throw new \RuntimeException("Missing required setting {$key} — see env.example.");
            }

            return $value;
        };

        $caBundle = $get('TELEGRAM_CA_BUNDLE');

        return new self(
            discordToken: $required('DISCORD_TOKEN'),
            telegramToken: $required('TELEGRAM_TOKEN'),
            discordOwnerId: $get('DISCORD_OWNER_ID'),
            // A path that isn't there would fail every request with a confusing
            // TLS error, so an unusable value is treated as unset.
            caBundle: $caBundle !== null && is_file($caBundle) ? $caBundle : null,
            telegramBaseUrl: $get('TELEGRAM_BASE_URL'),
            storePath: $storePath,
            envPath: $envPath,
            logLevel: strtolower($get('LOG_LEVEL') ?? 'info'),
        );
    }
}
