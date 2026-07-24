<?php

namespace ErnestDefoe\Armory;

use Illuminate\Support\Carbon;

/**
 * The regional weekly reset clock.
 *
 * WoW's week rolls over at a fixed regional moment (US Tue 15:00 UTC, everyone
 * else Wed 07:00 UTC) and almost everything weekly hangs off it: the Great
 * Vault, Mythic+ periods, raid lockouts, the briefing, leaderboard deltas.
 * This is the single source of that schedule — it used to be copied into
 * Briefing, GuildLeaderboard and WowCalendarSync independently.
 */
class WeeklyReset
{
    private const RESETS = [
        'us' => ['day' => Carbon::TUESDAY, 'hour' => 15],
        'eu' => ['day' => Carbon::WEDNESDAY, 'hour' => 7],
        'kr' => ['day' => Carbon::WEDNESDAY, 'hour' => 7],
        'tw' => ['day' => Carbon::WEDNESDAY, 'hour' => 7],
    ];

    public function __construct(
        protected BlizzardApi $api
    ) {
    }

    /** The most recent reset at or before $now. */
    public function last(?Carbon $now = null): Carbon
    {
        $now = $now ? $now->copy() : Carbon::now('UTC');
        $cfg = $this->config();

        $reset = $now->copy()->startOfDay()->setTime($cfg['hour'], 0);
        while ($reset->dayOfWeek !== $cfg['day'] || $reset->gt($now)) {
            $reset->subDay()->setTime($cfg['hour'], 0);
        }

        return $reset;
    }

    /**
     * The next reset strictly after $now. Paired with last() this partitions
     * time cleanly: standing exactly on a reset, that moment is the start of
     * the current week (last()), and next() is a full week ahead — so a
     * countdown rolls over instead of sticking at zero.
     */
    public function next(?Carbon $now = null): Carbon
    {
        $now = $now ? $now->copy() : Carbon::now('UTC');
        $cfg = $this->config();

        $reset = $now->copy()->startOfDay()->setTime($cfg['hour'], 0);
        while ($reset->dayOfWeek !== $cfg['day'] || $reset->lte($now)) {
            $reset->addDay()->setTime($cfg['hour'], 0);
        }

        return $reset;
    }

    /** Stable identifier for the current week, for ledgers and snapshots. */
    public function key(?Carbon $now = null): string
    {
        return $this->last($now)->format('Y-m-d');
    }

    /**
     * Seconds until the next reset — drives the countdown in the UI.
     *
     * abs() on purpose: Carbon 3 returns a SIGNED difference (Carbon 2 returned
     * an absolute one), so getting the operand order wrong silently yields a
     * negative that clamps to a permanent zero.
     */
    public function secondsUntilNext(?Carbon $now = null): int
    {
        $now = $now ? $now->copy() : Carbon::now('UTC');

        return (int) abs($now->diffInSeconds($this->next($now)));
    }

    private function config(): array
    {
        return self::RESETS[$this->api->region()] ?? self::RESETS['us'];
    }
}
