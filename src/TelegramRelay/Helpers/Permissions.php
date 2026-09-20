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

use Discord\Parts\Guild\Guild;
use Discord\Parts\User\Member;

/**
 * Who is allowed to reconfigure the bridge.
 *
 * Deliberately narrow: a bridge decides which Discord channel gets piped into
 * a Telegram group, so mis-set it and a private channel is suddenly being read
 * by strangers who were never in the server. The same gate covers `/telegram
 * ban` and `/telegram pin`, which act on the Telegram chat with the bot's own
 * rank. Only the server owner or someone holding Administrator / Manage Server
 * may touch any of it.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Permissions
{
    /** Administrator. */
    public const ADMINISTRATOR = 1 << 3;

    /** Manage Server. */
    public const MANAGE_GUILD = 1 << 5;

    /**
     * May the member behind this interaction reconfigure the bridge?
     *
     * Checks, in order: the guild owner (who always can, regardless of roles),
     * the fully-resolved `member.permissions` bitfield Discord puts on the
     * interaction payload, and finally the cached {@see Member} part. The
     * bitfield is preferred because it needs no cache and Discord has already
     * resolved channel overwrites into it.
     */
    public static function mayConfigure(object $interaction, ?Guild $guild = null): bool
    {
        $guild ??= $interaction->guild ?? null;
        $userId = (string) ($interaction->user->id ?? '');

        if ($guild instanceof Guild && $userId !== '' && (string) $guild->owner_id === $userId) {
            return true;
        }

        if (self::bitsGrant(self::bitsFromInteraction($interaction))) {
            return true;
        }

        $member = $interaction->member ?? null;

        return $member instanceof Member && self::memberGrants($member);
    }

    /**
     * The `member.permissions` decimal string off an interaction payload — the
     * effective permissions Discord computed for the invoking member in that
     * channel — or `null` when unreachable (a DM, or a shape we don't know).
     *
     * Reads the *raw* attributes first: DiscordPHP's `->member` getter runs a
     * transform that does not preserve `permissions`, so going through it
     * alone silently loses the value.
     */
    public static function bitsFromInteraction(object $interaction): ?string
    {
        $candidates = [];

        if (method_exists($interaction, 'getRawAttributes')) {
            $candidates[] = $interaction->getRawAttributes()['member'] ?? null;
        }
        $candidates[] = $interaction->member ?? null;

        foreach ($candidates as $member) {
            $bits = self::rawPermValue($member);
            if ($bits !== null) {
                return $bits;
            }
        }

        return null;
    }

    /** Does a permissions bitfield carry Administrator or Manage Server? */
    public static function bitsGrant(int|string|null $bits): bool
    {
        if ($bits === null || $bits === '') {
            return false;
        }

        $value = is_string($bits) ? (int) $bits : $bits;

        return ($value & self::ADMINISTRATOR) === self::ADMINISTRATOR
            || ($value & self::MANAGE_GUILD) === self::MANAGE_GUILD;
    }

    private static function memberGrants(Member $member): bool
    {
        $perms = $member->getPermissions();

        if ($perms === null) {
            return false;
        }

        return (bool) ($perms->administrator ?? false) || (bool) ($perms->manage_guild ?? false);
    }

    /**
     * Pulls `permissions` out of whatever shape a `member` attribute took — a
     * gateway `stdClass`, an array, or a Part (raw attributes, not the getter)
     * — normalised to a decimal string.
     */
    private static function rawPermValue(mixed $member): ?string
    {
        if (is_array($member)) {
            $perms = $member['permissions'] ?? null;
        } elseif (is_object($member)) {
            $perms = null;
            if (method_exists($member, 'getRawAttributes')) {
                $perms = $member->getRawAttributes()['permissions'] ?? null;
            }
            $perms ??= $member->permissions ?? null;
        } else {
            return null;
        }

        if ($perms === null || is_object($perms)) {
            return null;
        }

        return (string) $perms;
    }
}
