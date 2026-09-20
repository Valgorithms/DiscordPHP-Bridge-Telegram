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

use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Interactions\Interaction;
use Discord\WebSockets\Event as DiscordEvent;
use Discord\WebSockets\Intents;
use Telegram\Telegram;
use TelegramRelay\Bridge\TelegramGateway;
use TelegramRelay\Bridge\WebhookDelivery;
use TelegramRelay\Builders\PanelBuilder;
use TelegramRelay\Helpers\ComponentRouter;
use TelegramRelay\Modules\Module;

/**
 * The bot: one Discord gateway connection, one Telegram client, and the
 * modules that join them.
 *
 * Both clients share a single ReactPHP loop. `Telegram::run()` would call the
 * loop's `run()` itself, which is why the Telegram side is started through
 * `start()` — TelegramPHP's loop-free entry point — and `Discord::run()` is
 * left to drive everything.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Relay extends Discord
{
    public const GITHUB = 'https://github.com/Valgorithms/DiscordPHP-TelegramRelay';

    /** The gateway intents the bridge needs; MESSAGE_CONTENT is privileged. */
    public const INTENTS = Intents::GUILDS | Intents::GUILD_MESSAGES | Intents::MESSAGE_CONTENT;

    /** Named `telegram`, and deliberately not `client`: {@see Discord} owns that name. */
    private Telegram $telegram;

    private ?TelegramGateway $telegramGateway = null;

    private ?WebhookDelivery $delivery = null;

    private ComponentRouter $components;

    /** @var list<Module> */
    private array $modules = [];

    private bool $modulesBooted = false;

    /** The last startup check's headline; see {@see Modules\Startup}. */
    private ?string $lastCheck = null;

    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        array $options = [],
    ) {
        parent::__construct($options + [
            'token' => $config->discordToken,
            'intents' => self::INTENTS,
        ]);

        $this->components = new ComponentRouter();

        $telegramOptions = [
            'token' => $config->telegramToken,
            'loop' => $this->getLoop(),
            'logger' => $this->logger,
            'socket_options' => $config->socketOptions(),
        ];

        if ($config->telegramBaseUrl !== null) {
            $telegramOptions['base_url'] = $config->telegramBaseUrl;
        }

        $this->telegram = new Telegram($telegramOptions);

        $this->once('init', fn () => $this->start());
    }

    /** Adds a module. Call before {@see run()}; modules boot in registration order. */
    public function addModule(Module $module): self
    {
        $this->modules[] = $module;

        return $this;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getStore(): Store
    {
        return $this->store;
    }

    public function getTelegram(): Telegram
    {
        return $this->telegram;
    }

    /** The router every module registers its buttons with. */
    public function components(): ComponentRouter
    {
        return $this->components;
    }

    public function gateway(): TelegramGateway
    {
        if ($this->telegramGateway === null) {
            throw new \LogicException('The Telegram gateway is not up yet.');
        }

        return $this->telegramGateway;
    }

    /** Whether the Telegram side came up. `/telegram` reports this rather than timing out. */
    public function telegramIsUp(): bool
    {
        return $this->telegramGateway !== null;
    }

    public function delivery(): WebhookDelivery
    {
        return $this->delivery ??= new WebhookDelivery($this);
    }

    /** A builder that can never ping anyone — Telegram chat is untrusted input. */
    public static function reply(): MessageBuilder
    {
        return MessageBuilder::new()->setAllowedMentions(['parse' => []]);
    }

    /** What the last startup check found, for `/telegram status`. */
    public function rememberCheck(string $summary): void
    {
        $this->lastCheck = $summary;
    }

    /** The last startup check's headline, or `null` before it has run. */
    public function getLastCheck(): ?string
    {
        return $this->lastCheck;
    }

    /** Tells a human, when one is configured, rather than only the log. */
    public function notifyOwner(string $markdown): void
    {
        $ownerId = $this->config->discordOwnerId;

        if ($ownerId === null) {
            return;
        }

        $this->users->fetch($ownerId)->then(
            fn ($user) => $user->sendMessage(self::reply()->setContent($markdown)),
        )->catch(fn (\Throwable $e) => $this->logger->error(
            '[relay] could not DM the owner: ' . $e->getMessage(),
        ));
    }

    /**
     * Brings up the Telegram client once Discord is ready, then boots modules.
     *
     * Modules boot only after Telegram has answered `getMe`, because
     * {@see Modules\Bridge} needs a live gateway to attach its handler to.
     * When Telegram does *not* come up the modules still boot minus the
     * bridge, so `/telegram list` keeps working and an operator can see the
     * wiring they are trying to debug.
     */
    private function start(): void
    {
        $this->on(DiscordEvent::INTERACTION_CREATE, function (Interaction $interaction): void {
            $this->components->dispatch($interaction);
        });

        $this->components->on('dismiss', fn (Interaction $interaction) => $interaction->updateMessage(
            PanelBuilder::notice('-# Cancelled.'),
        ));

        $this->telegram->start()->then(
            function (): void {
                $this->telegramGateway = new TelegramGateway($this->telegram, $this->getLoop(), $this->logger);
                $this->telegramGateway->listen();

                $this->logger->info(sprintf(
                    '[relay] telegram ready as @%s; %d bridge(s) configured',
                    (string) ($this->telegram->getBotUser()?->username ?? '?'),
                    $this->store->links()->count(),
                ));

                $this->bootModules();
            },
            function (\Throwable $e): void {
                $this->logger->error('[relay] telegram failed to start: ' . $e->getMessage());
                $this->notifyOwner(
                    "⚠️ **The Telegram side of the bridge did not start.**\n"
                    . '`' . $e->getMessage() . '`',
                );
                $this->bootModules(skip: ['bridge']);
            },
        );
    }

    /** @param list<string> $skip */
    private function bootModules(array $skip = []): void
    {
        if ($this->modulesBooted) {
            return;
        }
        $this->modulesBooted = true;

        foreach ($this->modules as $module) {
            if (in_array($module->name(), $skip, true)) {
                $this->logger->warning('[relay] skipping module: ' . $module->name());

                continue;
            }

            try {
                $module->boot($this);
                $this->logger->info('[relay] module booted: ' . $module->name());
            } catch (\Throwable $e) {
                $this->logger->error('[relay] module ' . $module->name() . ' failed to boot: ' . $e->getMessage());
            }
        }
    }
}
