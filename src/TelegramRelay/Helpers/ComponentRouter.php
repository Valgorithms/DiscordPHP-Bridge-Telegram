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

namespace TelegramRelay\Helpers;

use Discord\Parts\Interactions\Interaction;
use React\Promise\PromiseInterface;

/**
 * Routes button and select-menu presses by `custom_id`.
 *
 * DiscordPHP can attach a listener to a {@see \Discord\Builders\Components\Button}
 * instance, but that listener lives in the process that built the button. A
 * panel posted before a restart would go dead, and every panel would hold a
 * closure for as long as the message existed. The bridge instead encodes what
 * a button *does* into its `custom_id` and routes on that, so a panel from
 * last week still works and the process holds one handler per action rather
 * than one per message.
 *
 * Ids look like `tg:unlink:1234567890`: a fixed prefix so another bot's
 * components are ignored, an action, then arguments. Discord caps a
 * `custom_id` at 100 characters, which is enforced when one is built rather
 * than discovered when Discord rejects the message.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class ComponentRouter
{
    /** Marks a component as ours. */
    public const PREFIX = 'tg';

    /** Discord's hard limit on a `custom_id`. */
    public const ID_LIMIT = 100;

    /** @var array<string, callable(Interaction, list<string>): mixed> */
    private array $handlers = [];

    /**
     * Builds a `custom_id` for an action and its arguments.
     *
     * @throws \InvalidArgumentException when an argument contains the `:`
     *                                   separator, or the result is too long
     *                                   for Discord to accept.
     */
    public static function id(string $action, string ...$args): string
    {
        foreach ([$action, ...$args] as $part) {
            if (str_contains($part, ':')) {
                throw new \InvalidArgumentException('A custom_id part may not contain ":": ' . $part);
            }
        }

        $id = implode(':', [self::PREFIX, $action, ...$args]);

        if (strlen($id) > self::ID_LIMIT) {
            throw new \InvalidArgumentException(sprintf('custom_id "%s" is %d characters; Discord allows %d.', $id, strlen($id), self::ID_LIMIT));
        }

        return $id;
    }

    /**
     * Splits one of our ids back up, or `null` when it is not ours.
     *
     * @return array{action: string, args: list<string>}|null
     */
    public static function parse(?string $customId): ?array
    {
        if ($customId === null || $customId === '') {
            return null;
        }

        $parts = explode(':', $customId);

        if (count($parts) < 2 || array_shift($parts) !== self::PREFIX) {
            return null;
        }

        $action = array_shift($parts);

        return $action === '' ? null : ['action' => $action, 'args' => array_values($parts)];
    }

    /**
     * Registers the handler for one action.
     *
     * @param callable(Interaction, list<string>): mixed $handler
     */
    public function on(string $action, callable $handler): self
    {
        $this->handlers[$action] = $handler;

        return $this;
    }

    /** Whether anything is registered for an action. */
    public function handles(string $action): bool
    {
        return isset($this->handlers[$action]);
    }

    /**
     * Hands an interaction to its handler.
     *
     * Returns `null` — not a rejected promise — for anything that is not ours
     * to handle, because this runs on every `INTERACTION_CREATE` in every
     * guild the bot is in, including components belonging to other features.
     */
    public function dispatch(Interaction $interaction): mixed
    {
        if ($interaction->type !== Interaction::TYPE_MESSAGE_COMPONENT) {
            return null;
        }

        $parsed = self::parse($interaction->data->custom_id ?? null);

        if ($parsed === null || ! isset($this->handlers[$parsed['action']])) {
            return null;
        }

        /** @var PromiseInterface|mixed $result */
        $result = ($this->handlers[$parsed['action']])($interaction, $parsed['args']);

        return $result;
    }
}
