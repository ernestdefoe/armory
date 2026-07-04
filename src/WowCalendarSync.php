<?php

namespace ErnestDefoe\Armory;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Auto-populates the calendar (ernestdefoe/calendar, soft dependency) with
 * the deterministic WoW schedule — Blizzard exposes no in-game events API,
 * but these are pure math:
 *
 *  - Darkmoon Faire: opens the first Sunday of each month, runs one week.
 *  - Weekly reset: US Tue 15:00 UTC, EU/KR/TW Wed 07:00 UTC.
 *
 * Events are keyed by deterministic slugs (wow-darkmoon-faire-2026-08, …) and
 * INSERTED only when missing — an admin can edit or delete any generated
 * event and the sync will never overwrite or resurrect it within its window.
 */
class WowCalendarSync
{
    /** How far ahead to generate. */
    private const DMF_MONTHS = 3;
    private const RESET_WEEKS = 5;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db,
        protected BlizzardApi $api,
        protected TranslatorInterface $translator,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('armory.calendar_sync_enabled');
    }

    /** Generate upcoming events. Returns how many were inserted. */
    public function sync(): int
    {
        if (! $this->db->getSchemaBuilder()->hasTable('calendar_events')) {
            return 0;
        }

        $inserted = 0;
        $now = Carbon::now('UTC');

        // Darkmoon Faire — one all-week event per month.
        for ($i = 0; $i < self::DMF_MONTHS; $i++) {
            $month = $now->copy()->addMonthsNoOverflow($i);
            $open = new Carbon('first sunday of '.$month->format('F Y'), 'UTC');
            if ($open->copy()->addDays(7)->lt($now)) {
                continue; // this month's faire already ended
            }
            $inserted += $this->insert(
                'wow-darkmoon-faire-'.$open->format('Y-m'),
                $this->t('dmf_title'),
                $this->t('dmf_description'),
                $open,
                $open->copy()->addDays(7),
                true
            );
        }

        // Weekly resets.
        $reset = $this->nextReset($now);
        for ($i = 0; $i < self::RESET_WEEKS; $i++) {
            $at = $reset->copy()->addWeeks($i);
            $inserted += $this->insert(
                'wow-weekly-reset-'.$at->format('Y-m-d'),
                $this->t('reset_title'),
                $this->t('reset_description'),
                $at,
                $at->copy()->addHour(),
                false
            );
        }

        return $inserted;
    }

    /** The next weekly reset at or after $now for the configured region. */
    public function nextReset(Carbon $now): Carbon
    {
        $cfg = [
            'us' => [Carbon::TUESDAY, 15],
            'eu' => [Carbon::WEDNESDAY, 7],
            'kr' => [Carbon::WEDNESDAY, 7],
            'tw' => [Carbon::WEDNESDAY, 7],
        ][$this->api->region()] ?? [Carbon::TUESDAY, 15];

        $reset = $now->copy()->startOfDay()->setTime($cfg[1], 0);
        while ($reset->dayOfWeek !== $cfg[0] || $reset->lt($now)) {
            $reset->addDay()->setTime($cfg[1], 0);
        }

        return $reset;
    }

    /** Insert one event unless its slug already exists (or ever existed and was deleted this window). */
    protected function insert(string $slug, string $title, string $description, Carbon $start, Carbon $end, bool $allDay): int
    {
        if ($this->db->table('calendar_events')->where('slug', $slug)->exists()) {
            return 0;
        }

        // Deleted-by-admin guard: remember every slug we ever created so a
        // deliberately removed event isn't resurrected on the next sync.
        $created = array_filter(explode(',', (string) $this->settings->get('armory.calendar_sync_created')));
        if (in_array($slug, $created, true)) {
            return 0;
        }

        $this->db->table('calendar_events')->insert([
            'user_id' => null,
            'title' => $title,
            'slug' => $slug,
            'description' => $description,
            'start_at' => $start->toDateTimeString(),
            'end_at' => $end->toDateTimeString(),
            'all_day' => $allDay,
            'timezone' => 'UTC',
            'is_published' => true,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Keep the created-ledger bounded: only slugs still in the future window matter.
        $created[] = $slug;
        $this->settings->set('armory.calendar_sync_created', implode(',', array_slice($created, -40)));

        return 1;
    }

    protected function t(string $key): string
    {
        return $this->translator->trans('ernestdefoe-armory.calendar.'.$key);
    }
}
