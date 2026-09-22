# DiscordPHP-Bridge-Telegram

The Telegram connector for [DiscordPHP-Bridge](https://github.com/discord-php/DiscordPHP-Bridge):
a two-way chat bridge with edits and media, Components v2 panels, and one
command catalogue shared with every other network the bot is on.

```
#general  ──────────►  t.me/mygroup
          ◄──────────
```

Built on [TelegramPHP](https://github.com/Valgorithms/TelegramPHP), sharing the
bot's ReactPHP event loop.

## Installing it

```php
use Bridge\Telegram\{TelegramConfig, TelegramConnector};

if (TelegramConfig::isConfigured($environment)) {
    $bot->addConnector(new TelegramConnector(TelegramConfig::fromEnvironment($environment)));
}
```

That is the whole integration. The connector arrives with `link`, `here`,
`unlink`, `list`, `status` and `reset` already written — the core defines those
once for every connector — so what is in this package is only what is actually
about Telegram.

`.env` needs one thing: `TELEGRAM_TOKEN`, from [@BotFather](https://t.me/BotFather).
Two more are optional: `TELEGRAM_BASE_URL` for a self-hosted Bot API server, and
`TELEGRAM_POLL_INTERVAL` to slow the long poll down.

**Turn off privacy mode**, or `/setprivacy` → Disable, via BotFather. With it on
the bot only sees messages addressed to it, so the bridge relays almost nothing
and the reason is invisible from Discord.

## What it can do that a text-only network cannot

The connector implements two of the core's optional capabilities, and the relay
asks rather than assuming:

- **`Capability\Editing`.** Editing a Discord message rewrites the Telegram copy
  in place, and vice versa. A network that cannot edit gets the original left
  alone rather than a second "(edited)" message, which is worse.
- **`Capability\Media`.** A picture posted in Discord arrives in Telegram as a
  *picture*, not a link. That matters more than it sounds: Discord's CDN links
  are signed and expire in about a day, so a relayed link works for people
  reading along live and is dead by the time anyone reads the logs.

Only the first image goes as a photo — a media group is a different call and
needs every attachment to be an image — and everything else relays as a link in
the text.

## Commands

Everything below works four ways — as a Discord slash command, as a Discord
prefix command, in Telegram chat, and in the chat of any *other* network the bot
is bridged to. `!telegram` on its own lists what you can run.

| | |
| --- | --- |
| `/telegram link` · `here` · `unlink` · `list` · `status` · `reset` | the bridge (admin) |
| `/telegram chat send` · `photo` · `poll` · `info` | speak into the chat |
| `/telegram chat pin` · `unpin` | the pinned message (admin) |
| `/telegram mod ban` · `unban` | moderation (admin) |

A chat drops the group and keeps the qualifier — `!telegram send`, not
`!telegram chat send` and never a bare `!send`. That is deliberate: a name is
only free because no connector has claimed it yet, and since every connector's
commands are offered in every chat, an unqualified `!ban` is one installed
package away from meaning two things.

`send`, `photo`, `poll` and `info` are open to everyone, which is safe because
they can only ever reach a chat somebody with **Manage Server** already bridged
to that channel. Pinning, unpinning and banning need that same rung.

`/telegram chat info` answers with a Components v2 panel: what the bot can see
about the chat, plus Refresh, Member count and Invite link buttons. Each button
carries the chat id it was drawn for, so a panel still works after the channel
has been re-linked somewhere else, and after the bot has restarted.

**Invite links are ephemeral and gated, deliberately.**
`exportChatInviteLink` *revokes the chat's previous link* and mints a new one,
so the answer is both a working invite to a private group and the reason the old
one stopped working — not something to leave sitting in a channel.

## The token in the URL

A Telegram file URL contains the bot token in its path. That is Telegram's
design, not a mistake to work around, and it has three consequences this package
takes seriously:

- Such a URL is **never logged**, never put in an exception message that might
  be, and never posted into Discord.
- A Telegram error is never re-used unexamined either, because a Telegram error
  can quote the request URL. Every one is matched against known causes and
  rewritten, with any URL in it replaced before it can reach a channel.
- The connector hands the core `null` for a Telegram attachment's URL, every
  time. The relay then names the file rather than linking it.

## Coming from DiscordPHP-TelegramRelay

This repository *is* that project, with everything that was not about Telegram
moved into the core. The commands were renamed to make room for other networks:

| Before | Now |
| --- | --- |
| `/telegram link` · `here` · `unlink` · `list` · `reset` | unchanged |
| `/telegram status` | unchanged |
| `/tg send` · `photo` · `poll` | `/telegram chat send` · `photo` · `poll` |
| `/tg chat` | `/telegram chat info` |
| `/tg pin` · `unpin` | `/telegram chat pin` · `unpin` |
| `/tg ban` · `unban` | `/telegram mod ban` · `unban` |

The old names are unregistered from Discord automatically on the first boot
where every connector starts.

**The stored state is compatible.** A `var/relay.json` written by the old bot is
migrated on load into the connector-keyed shape — point the app's store path at
it, or copy it across.

## Layout

```
src/Bridge/Telegram/
  TelegramConnector.php    the client, the long poll, edits and media
  TelegramConfig.php       settings, from the environment only
  TelegramGateway.php      the poll, and paced sending
  TelegramText.php         HTML mode, the limits, naming a chat
  Media.php                what a Telegram message was carrying
  Panels.php               the buttons a panel carries
  Actions/                 the commands themselves
```

```bash
composer test
```

## Licence

MIT.
