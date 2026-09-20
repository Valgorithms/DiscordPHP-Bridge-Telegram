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

use Discord\Builders\CommandBuilder;
use Discord\Helpers\ExCollectionInterface;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Interactions\Interaction;
use Discord\Repository\Interaction\GlobalCommandRepository;
use TelegramRelay\Builders\PanelBuilder;
use TelegramRelay\Helpers\CommandSync;
use TelegramRelay\Helpers\Permissions;
use TelegramRelay\Relay;

/**
 * Registers a sub-command with the two checks every one of them needs.
 *
 * DiscordPHP already routes `/telegram link` to a handler registered as
 * `listenCommand(['telegram', 'link'])` and hands it that sub-command's own
 * options; what it does not do is decide who may run it. Both checks —
 * guild-only, and the permission gate — would otherwise be the first eight
 * lines of every handler in the bridge, which is exactly the kind of
 * repetition that eventually gets one of them wrong.
 *
 * Handlers are called as `(Interaction $interaction, Guild $guild,
 * ExCollectionInterface $options)`, and may declare only the leading
 * parameters they use.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
trait RoutesCommands
{
    /**
     * Registers one sub-command.
     *
     * @param list<string>                                            $names   e.g. `['telegram', 'link']`
     * @param callable(Interaction, Guild, ExCollectionInterface): mixed $handler
     * @param bool                                                    $privileged Whether Manage Server is required.
     */
    protected function subCommand(Relay $bot, array $names, callable $handler, bool $privileged = true): void
    {
        $bot->listenCommand(
            $names,
            function (Interaction $interaction, ExCollectionInterface $options) use ($bot, $names, $handler, $privileged) {
                $guild = $interaction->guild;

                if (! $guild instanceof Guild) {
                    return $interaction->respondWithMessage(PanelBuilder::error('Server only.'), true);
                }

                if ($privileged && ! Permissions::mayConfigure($interaction, $guild)) {
                    $bot->logger->warning(sprintf(
                        '[%s] denied /%s for user %s in guild %s (permissions=%s)',
                        $this->name(),
                        implode(' ', $names),
                        (string) ($interaction->user->id ?? '?'),
                        (string) $guild->id,
                        Permissions::bitsFromInteraction($interaction) ?? 'null',
                    ));

                    return $interaction->respondWithMessage(
                        PanelBuilder::error('You need **Manage Server** (or Administrator) to do that.'),
                        true,
                    );
                }

                return $handler($interaction, $guild, $options);
            },
        );
    }

    /**
     * Publishes a command definition, creating it when Discord has never seen
     * it and updating it when this build defines something different.
     *
     * The update is the part that matters across a restart: a bot that only
     * creates a missing command keeps whatever it published the first time, so
     * a sub-command added later is routed in code and never offered by
     * Discord. {@see CommandSync} decides whether anything actually changed,
     * because a global command takes up to an hour to propagate and rewriting
     * it on every boot would restart that clock for nothing.
     */
    protected function publishCommand(Relay $bot, GlobalCommandRepository $repo, CommandBuilder $builder): void
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) json_encode($builder), true) ?? [];
        $name = (string) ($payload['name'] ?? '');

        $published = $repo->get('name', $name);

        if ($published === null) {
            $builder->create($repo)->save($name . ' command');
            $bot->logger->info(sprintf('[%s] registered /%s', $this->name(), $name));

            return;
        }

        if (! CommandSync::differs($published->jsonSerialize(), $payload)) {
            return;
        }

        $bot->logger->info(sprintf('[%s] /%s has changed since it was published — updating it', $this->name(), $name));

        $published->fill($payload);

        $repo->save($published, 'definition changed')->then(
            fn () => $bot->logger->info(sprintf('[%s] updated /%s', $this->name(), $name)),
            fn (\Throwable $e) => $bot->logger->error(sprintf('[%s] could not update /%s: %s', $this->name(), $name, $e->getMessage())),
        );
    }

    /**
     * One option's value out of the collection DiscordPHP hands a handler.
     *
     * The collection is keyed by option name and holds
     * {@see \Discord\Parts\Interactions\Request\Option} parts; an option the
     * user left out is simply absent, which is `null` here rather than an
     * error.
     */
    protected static function option(ExCollectionInterface $options, string $name, mixed $default = null): mixed
    {
        return $options->get('name', $name)?->value ?? $default;
    }
}
