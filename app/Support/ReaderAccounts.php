<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Reader;
use App\Models\User;
use App\Notifications\ReaderConfirmEmail;
use App\Notifications\ReaderInvitation;
use App\Notifications\ReaderLetIn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

/**
 * What happens to readers' accounts: signing in, confirming an address,
 * being let in, invitations, and coming and going from groups. Kept here so
 * the site's forms and the admin's page do it the same way.
 */
class ReaderAccounts
{
    /** Days a confirm link and an invitation stay good. */
    public const CONFIRM_DAYS = 3;

    public const INVITE_DAYS = 14;

    protected const GROUP_INVITE = 'readers.group_invite';

    public static function signIn(Reader $reader, bool $remember = false): void
    {
        Auth::guard('reader')->login($reader, $remember);
        session()->regenerate();
        $reader->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    public static function signOut(): void
    {
        Auth::guard('reader')->logout();
        session()->regenerateToken();
    }

    public static function confirmUrl(Reader $reader): string
    {
        return URL::temporarySignedRoute('readers.confirm', now()->addDays(self::CONFIRM_DAYS), ['reader' => $reader->id, 'hash' => sha1($reader->email)]);
    }

    public static function sendConfirm(Reader $reader): void
    {
        rescue(fn () => $reader->notify(new ReaderConfirmEmail(self::confirmUrl($reader))), null, false);
    }

    /** They pressed the button in the email: in, or waiting for the book to let them in. */
    public static function confirm(Reader $reader): void
    {
        if ($reader->confirmed() || $reader->state === 'blocked') {
            return;
        }
        $letIn = $reader->approved_at !== null || Readers::joining() !== 'approval';
        $reader->forceFill([
            'email_verified_at' => now(),
            'state' => $letIn ? 'active' : 'waiting',
            'approved_at' => $letIn ? ($reader->approved_at ?? now()) : null,
        ])->save();
    }

    /** The book lets a waiting reader in (and tells them). */
    public static function approve(Reader $reader): void
    {
        $was = $reader->state;
        $reader->forceFill(['state' => 'active', 'approved_at' => now(), 'email_verified_at' => $reader->email_verified_at ?? now()])->save();
        if ($was === 'waiting') {
            rescue(fn () => $reader->notify(new ReaderLetIn), null, false);
        }
    }

    /** An invitation by email: a signed link to the join page, with their address (and groups) filled in. */
    public static function invite(string $email, string $name = '', array $groups = [], ?User $by = null): string
    {
        $url = URL::temporarySignedRoute('readers.join', now()->addDays(self::INVITE_DAYS), array_filter([
            'email' => mb_strtolower($email),
            'name' => $name !== '' ? $name : null,
            'groups' => $groups !== [] ? implode(',', $groups) : null,
            'by' => $by?->id,
        ]));
        rescue(fn () => \Illuminate\Support\Facades\Notification::route('mail', $email)->notify((new ReaderInvitation($url, $by?->name))->locale(app(\App\Booklet\Site::class)->language())), null, false);

        return $url;
    }

    // Groups ----------------------------------------------------------------------------------

    public static function addTo(Group $group, Reader|User $who, string $state = 'in'): GroupMember
    {
        $member = GroupMember::query()->firstOrNew([
            'group_id' => $group->id,
            'member_type' => $who instanceof Reader ? 'reader' : 'user',
            'member_id' => $who->id,
        ]);
        // Asking never undoes being in.
        $member->state = $member->exists && $member->state === 'in' ? 'in' : $state;
        $member->save();
        Remember::forget();

        return $member;
    }

    public static function removeFrom(Group $group, Reader|User $who): void
    {
        GroupMember::query()->where('group_id', $group->id)
            ->where('member_type', $who instanceof Reader ? 'reader' : 'user')
            ->where('member_id', $who->id)->delete();
        Remember::forget();
    }

    /** A reader joins (or asks to join) a group from their own page, as the group allows. */
    public static function joinFromSite(Group $group, Reader $reader): ?string
    {
        return match ($group->joining) {
            'open' => self::addTo($group, $reader)->state,
            'approval' => self::addTo($group, $reader, 'asked')->state,
            default => null,
        };
    }

    /** Remember a group's invitation link they followed, until they've joined or signed in. */
    public static function holdGroupInvite(Group $group): void
    {
        session()->put(self::GROUP_INVITE, ['group' => $group->id, 'token' => $group->invite_token]);
    }

    public static function groupInvite(): ?Group
    {
        $held = session(self::GROUP_INVITE);
        if (! is_array($held)) {
            return null;
        }
        $group = Group::find($held['group'] ?? 0);

        return $group && $group->joining === 'invite' && $group->invite_token && hash_equals((string) $group->invite_token, (string) ($held['token'] ?? '')) ? $group : null;
    }

    public static function forgetGroupInvite(): void
    {
        session()->forget(self::GROUP_INVITE);
    }

    /** People waiting on the book: readers to let in, and asks to join groups. */
    public static function waitingCount(): int
    {
        return rescue(fn () => Reader::query()->where('state', 'waiting')->count() + GroupMember::query()->where('state', 'asked')->count(), 0, false);
    }
}
