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

namespace Bridge\Telegram\Actions;

use Bridge\Command\ActionError;
use Bridge\Command\Context;
use Bridge\Telegram\TelegramConnector;

/**
 * Reaches the Telegram client from inside an action.
 *
 * An action is handed a {@see Context}, which knows the bot but deliberately
 * not the network — that is what lets the same catalogue serve every chat. A
 * Telegram action does need the Telegram client, so it asks the bot for the
 * connector by name.
 *
 * It can genuinely be absent: the connector is installed only when the
 * environment carries a Telegram token, and it can fail to start while the rest
 * of the bot runs on. Saying so is better than a `TypeError` two frames down.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
trait UsesTelegram
{
    private function telegram(Context $context): TelegramConnector
    {
        $connector = $context->bot->connector(TelegramConnector::NAME);

        if (! $connector instanceof TelegramConnector) {
            throw new ActionError('the Telegram side is not connected, so that cannot be done right now.');
        }

        return $connector;
    }
}
