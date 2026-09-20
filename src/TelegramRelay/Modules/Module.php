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

namespace TelegramRelay\Modules;

use TelegramRelay\Relay;

/**
 * A self-contained feature that attaches its own listeners once the gateway is
 * ready. Same shape as Tutelar's and TwitchRelay's module contract, so the
 * projects stay legible to each other.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
interface Module
{
    /** Stable short name, used for logging. */
    public function name(): string;

    /** Attach listeners, timers, and slash-command handlers. Called once, after ready. */
    public function boot(Relay $bot): void;
}
