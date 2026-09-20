# DiscordPHP-TelegramRelay

Bridges a Discord channel and a Telegram chat, in both directions, and puts
Telegram's own features — polls, pins, bans, invite links — behind Discord
slash commands and Components v2 panels. A server admin wires it up from inside
Discord with `/telegram`; nothing to edit on the host.

Built on [DiscordPHP](https://github.com/discord-php/DiscordPHP) and
[TelegramPHP](https://github.com/Valgorithms/TelegramPHP), sharing one ReactPHP
event loop.

```
#general  ──────────►  t.me/yourgroup
          ◄──────────
```

## What it does

- **Discord → Telegram.** A message in a bridged channel arrives in the chat as
  **author**: message. Mentions are resolved to names, the first image
  attachment is sent as a photo, anything else is linked.
- **Telegram → Discord.** Messages are posted through a webhook, so each sender
  keeps their own name instead of arriving as a wall of identical bot messages.
- **Media both ways.** Photos, documents, voice notes, video and stickers are
  downloaded and re-uploaded to Discord — never linked, because a Telegram file
  URL contains the bot token.
- **Edits follow the message.** An edit on either side rewrites the copy on the
  other, for as long as the bridge still remembers it.
- **Service messages are relayed**, so a Discord reader sees that someone
  joined, the group was renamed, or a message was pinned.
- **Replies keep their context**, quoted in one line.
- **Never relays its own output.** See [Loop prevention](#loop-prevention).
- **Many servers, one bot.** Several Discord servers can follow the same
  Telegram chat; each gets a copy.

## Setup

```bash
composer install
cp env.example .env    # then fill it in
php bot.php
```

`.env` needs a Discord bot token and a Telegram bot token from
[@BotFather](https://t.me/botfather). Four things are easy to miss:

- **Turn Telegram's privacy mode off.** @BotFather → `/setprivacy` → Disable,
  then remove and re-add the bot to the group. A bot with privacy mode on — the
  default — only receives commands and replies addressed to it, so the bridge
  relays nothing out of Telegram and looks broken in exactly one direction.
  This is the single most common setup problem; `/telegram status` says so too.
- **Use a dedicated Discord application.** Slash commands are registered per
  *application*, so sharing a token with another bot that also defines a global
  `/telegram` means whichever boots first wins and the other silently skips
  registering. No error, nothing in the log.
- **Enable the Message Content intent** on the Discord application page.
  Without it every bridged message arrives empty.
- **Grant the bot Manage Webhooks** in the bridged channel. Without it the
  bridge still works, but Telegram messages arrive as plain `**name:** message`
  bot messages instead of per-sender identities.

On Windows, PHP usually ships without a CA bundle and TLS to `api.telegram.org`
fails; point `TELEGRAM_CA_BUNDLE` at a `cacert.pem`. To move files larger than
20 MB, run a [local Bot API server](https://core.telegram.org/bots/api#using-a-local-bot-api-server)
and set `TELEGRAM_BASE_URL`.

## Configuring a bridge

`/telegram`, restricted to the server owner or anyone with **Administrator** /
**Manage Server**:

| Command | |
| --- | --- |
| `/telegram link channel:#general chat:-1001234567890` | Bridge a channel |
| `/telegram here chat:@yourgroup` | Bridge the channel you're in |
| `/telegram unlink [channel:#general]` | Stop bridging it |
| `/telegram list` | This server's bridges, each with an Unlink button |
| `/telegram status` | Whether the Telegram side is actually up |
| `/telegram reset` | Clear them all, behind a confirmation |

`chat:` accepts a numeric id, an `@username`, or a `t.me/...` link, and the
bridge checks it can see the chat before wiring anything up — a typo otherwise
produces a bridge that silently never works. A `t.me/+…` invite link is
rejected with an explanation: it is a join link, not a chat id.

To find a group's id: add the bot, then forward one of its messages to
[@userinfobot](https://t.me/userinfobot).

That permission gate is the security model: whoever can run `/telegram link`
decides which Discord channel gets copied into a Telegram group. Set it on a
private channel and that channel is now being read by people who were never in
the server.

## Telegram's features, from Discord

`/tg` acts on **whichever chat the current channel is bridged to**, so no
command takes a chat id and none can reach a chat this server has not linked.

| Command | | Who |
| --- | --- | --- |
| `/tg send text:…` | Say something in the chat | anyone |
| `/tg photo file:… caption:…` | Upload a photo | anyone |
| `/tg poll question:… options:a, b, c` | Start a real Telegram poll | anyone |
| `/tg chat` | The chat's details, with buttons | anyone |
| `/tg pin message_id:…` | Pin a message | Manage Server |
| `/tg unpin [message_id:…]` | Unpin one, or the latest | Manage Server |
| `/tg ban user_id:… [minutes:…]` | Ban, or ban for a while | Manage Server |
| `/tg unban user_id:…` | Lift a ban | Manage Server |

`send`, `photo`, `poll` and `chat` are open to anyone who can use the channel:
they can already have the bridge carry their words simply by typing, so gating
the tidier route would be theatre. The rest act on the Telegram chat with the
*bot's* rank rather than the caller's, so they are gated like the wiring
itself.

The `/tg chat` panel carries **Refresh**, **Invite link** and **Member count**
buttons. Invite link is always ephemeral, because `exportChatInviteLink`
*revokes the previous link* and mints a new one — that is not something to
leave sitting in a channel.

## Components v2

Every answer is a Components v2 panel rather than an embed, because these
panels are not decorated text. `/telegram list` needs a button *per row* to
unlink that row — a `Section` accessory, which an embed cannot express — and
pressing it redraws the panel in place so the row that was just removed is
visibly gone.

Panels are built by [`PanelBuilder`](src/TelegramRelay/Builders/PanelBuilder.php),
which *extends* `MessageBuilder`: a panel **is** a message builder, so anything
that takes one — `respondWithMessage()`, `updateMessage()`, `Webhook::execute()`
— takes a panel with no unwrapping step.

Buttons are routed by `custom_id` rather than by a listener bound to the button
instance, so a panel posted before a restart still works and the process holds
one handler per action instead of one per message. Ids look like
`tg:unlink:1234567890`, and
[`ComponentRouter`](src/TelegramRelay/Helpers/ComponentRouter.php) refuses to
build one that exceeds Discord's 100-character limit rather than finding out
when the message is rejected.

## Loop prevention

A bridge that repeats itself is an infinite loop that gets the account limited
on both networks. Each direction drops its own output as early as it can:

- **Discord → Telegram** ignores any message carrying a `webhook_id`, and any
  message from a bot. Relayed Telegram chat arrives *through* a webhook, so it
  is caught by the first rule. Other bots are dropped too, deliberately: two
  bridges in one channel would otherwise ping-pong forever.
- **Telegram → Discord** ignores anything sent by the bridge's own bot account.
  Telegram does not echo a bot's own sends back to it, but two instances
  sharing one token would otherwise relay each other forever.

## Safety

**HTML injection.** The bridge sends `parse_mode: HTML` so it can bold the
author's name, which means relayed text is parsed as markup. Everything from
Discord goes through `MessageText::escapeHtml()` first — an unescaped `<b>`
would style the message, and a stray `<` would make Telegram reject the send
outright, silently stopping the bridge. It is
[tested directly](tests/MessageTextTest.php).

**The bot token in file URLs.** A Telegram file is served from
`api.telegram.org/file/bot<TOKEN>/…`. Posting one into Discord would hand the
bot's credentials to everyone who can read the channel, so files are downloaded
and re-uploaded, that URL is never logged, and the `/tg chat` panel has no
thumbnail even though Telegram offers a chat photo.

**Mentions.** Telegram chat is untrusted input, so everything delivered into
Discord is sent with `allowed_mentions: {parse: []}`. Someone typing
`@everyone` still *reads* as having typed it, but pings nobody.

**Rate limits.** Telegram allows roughly 20 messages a minute into one group
and 30 a second overall, and exceeding either earns a `429`. Outbound messages
pass a per-chat token bucket *and* a global one, so ordinary chat is never
delayed and only a genuine burst is paced.

**Phone numbers.** A shared contact is relayed as "shared a contact", by name.
The number is not carried across: it was shared with a Telegram group, not with
a Discord server.

## Surviving a restart

Bridges configured with `/telegram link` live in `var/relay.json` and are
reloaded on every start — nothing has to be set up again. That file is the only
record of them, so it is treated as one:

- **Writes are atomic and flushed to disk.** A crash mid-write, or a machine
  losing power, cannot leave a half-written file where the configuration was.
- **The last good copy is kept** beside it as `relay.json.bak`, written after
  each successful save.
- **A damaged file is never silently replaced.** If the JSON doesn't parse the
  backup is used; if that fails too, the file is preserved as
  `relay.json.corrupt-<timestamp>` and the bridge starts empty rather than
  overwriting it on the next `/telegram link`.
- **Entries of the wrong shape are dropped, not loaded**, so a hand-edited file
  can't take the bridge down — and the good entries in it still survive the
  next write.

On top of that, a start does three things that keep it actually working rather
than merely configured:

- **It re-publishes slash commands whose definition changed.** A bot that only
  registers a command when it is missing keeps whatever it published the first
  time, so a sub-command added later is routed in code and never offered by
  Discord. The two definitions are compared, and only a real difference is
  written.
- **It checks every restored bridge**, ten seconds in, once the guild caches
  have settled: can it still see the Discord channel, and can it still see the
  Telegram chat? A bridge that stopped working while the bot was down — a
  deleted channel, a group that kicked it — is otherwise indistinguishable from
  a quiet day.
- **It says what it found**, in the log, in `/telegram status`, and by DM to
  `DISCORD_OWNER_ID`. Nothing is ever pruned automatically: a guild can be
  briefly unavailable during a Discord outage, and deleting someone's
  configuration over a bad ten seconds is worse than telling them about it.
## What it deliberately doesn't do

- **Deletions.** The Bot API sends a bot no update at all when a message is
  deleted, so a deletion cannot be followed out of Telegram. Honouring it in
  only one direction would be more confusing than not honouring it.
- **Per-sender avatars from Telegram.** A user's profile photo is only
  reachable through a token-bearing URL, so relayed messages carry names but
  the webhook's own avatar.
- **Edits older than the bridge remembers.** The message map is bounded and in
  memory; past it, an edit arrives as a new message rather than a rewrite.

## Layout

```
bot.php                         entrypoint
src/TelegramRelay/
  Relay.php                     the bot: Discord + Telegram on one loop
  Config.php                    settings, from the environment only
  Store.php                     JSON persistence for bridges (atomic writes)
  Links.php                     the routing table — pure
  Builders/
    PanelBuilder.php            Components v2 panels; extends MessageBuilder
  Modules/
    Module.php                  what a feature looks like
    Startup.php                 checks the restored bridges still work
    RoutesCommands.php          sub-command registration + the permission gate
    Configuration.php           /telegram
    Controls.php                /tg — Telegram's features, from Discord
    Bridge.php                  the relay itself, and loop prevention
  Bridge/
    TelegramGateway.php         update subscription, paced sending
    WebhookDelivery.php         posting into Discord, with a fallback
  Helpers/
    MessageText.php             every decision about what text leaves this process
    Media.php                   what a Telegram message is carrying
    MessageMap.php              which message became which, for edits
    ComponentRouter.php         button routing by custom_id
    CommandSync.php             has the published command drifted from the code?
    BridgeCheck.php             what the startup check found, in words
    Permissions.php             who may reconfigure a bridge
    RateLimiter.php             token bucket
```

Sub-commands are registered as `listenCommand(['telegram', 'link'], …)`, so
DiscordPHP's own `RegisteredCommand` does the routing and each handler is
handed its own options — the same shape as an application command anywhere else
in the DiscordPHP family.

The logic worth testing is deliberately pure — `Links`, `MessageText`, `Media`,
`MessageMap`, `RateLimiter`, `Store`, `PanelBuilder` — so routing, escaping,
pacing and every rendered panel can be verified without a socket:

```bash
composer test
```

## Licence

MIT.
