<?php

namespace ErnestDefoe\Armory;

use Carbon\Carbon;
use ErnestDefoe\Armory\Support\GuildPoster;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "This Week in the Pact" — the weekly reset-day briefing topic. Assembles
 * this week's M+ affixes (Raider.IO public API), Darkmoon Faire status
 * (computed — Blizzard exposes no events endpoint), the guild's calendar
 * events for the week (soft integration with ernestdefoe/calendar), last
 * week's boss kills + guild achievements (Battle.net guild activity feed) and
 * the WoW Token price, then posts it as a (optionally pinned) discussion.
 *
 * Every section degrades independently: a missing calendar extension, an
 * unreachable API or an unconfigured guild just drops that section.
 */
class Briefing
{
    /** Weekly reset moments (UTC) per region. */
    private const RESETS = [
        'us' => ['day' => Carbon::TUESDAY, 'hour' => 15],
        'eu' => ['day' => Carbon::WEDNESDAY, 'hour' => 7],
        'kr' => ['day' => Carbon::WEDNESDAY, 'hour' => 7],
        'tw' => ['day' => Carbon::WEDNESDAY, 'hour' => 7],
    ];

    /** Reused across affix fetches instead of a fresh TCP stack per invocation. */
    protected Client $http;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db,
        protected Armory $armory,
        protected BlizzardApi $api,
        protected GuildPoster $poster,
        protected TranslatorInterface $translator,
        protected LoggerInterface $log,
    ) {
        $this->http = new Client(['timeout' => 8, 'http_errors' => false]);
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('armory.briefing_enabled');
    }

    /** The most recent weekly reset moment at or before $now. */
    public function lastReset(?Carbon $now = null): Carbon
    {
        $now = $now ?? Carbon::now('UTC');
        $cfg = self::RESETS[$this->api->region()] ?? self::RESETS['us'];

        $reset = $now->copy()->startOfDay()->setTime($cfg['hour'], 0);
        while ($reset->dayOfWeek !== $cfg['day'] || $reset->gt($now)) {
            $reset->subDay()->setTime($cfg['hour'], 0);
        }

        return $reset;
    }

    /** Due when enabled, this week's reset has passed and we haven't posted for it. */
    public function due(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return $this->settings->get('armory.briefing_last_key') !== $this->lastReset()->format('Y-m-d');
    }

    /** Post the briefing now. Returns the new discussion id, or null on failure. */
    public function post(): ?int
    {
        $reset = $this->lastReset();
        $title = $this->t('title', ['date' => $reset->format('F j, Y')]);
        $content = $this->compose($reset);

        // Discussion creation (admin actor + tag resolution) is shared with the
        // other automated posts — delegate to GuildPoster; only the pinning +
        // last-key bookkeeping below is briefing-specific.
        $id = $this->poster->post($title, $content, ['armory.briefing_tag_slug']);
        if ($id === null) {
            return null;
        }

        $this->applyPinning($id);

        $this->settings->set('armory.briefing_last_key', $reset->format('Y-m-d'));
        $this->settings->set('armory.briefing_last_discussion_id', (string) $id);

        return $id;
    }

    /** ---- content -------------------------------------------------- */

    public function compose(Carbon $reset): string
    {
        $parts = [$this->t('intro')];

        $parts[] = "### 🎁 {$this->t('vault_heading')}\n\n".$this->t('vault_body');

        if ($affixes = $this->affixes()) {
            $parts[] = "### 🌀 {$this->t('affixes_heading')}\n\n".$affixes;
        }

        $parts[] = "### 🎪 {$this->t('dmf_heading')}\n\n".$this->darkmoon();

        if ($events = $this->weekEvents($reset)) {
            $parts[] = "### 📅 {$this->t('events_heading')}\n\n".$events;
        }

        if ($activity = $this->lastWeekActivity()) {
            $parts[] = "### 🏆 {$this->t('activity_heading')}\n\n".$activity;
        }

        if ($token = $this->token()) {
            $parts[] = "### 🪙 {$this->t('token_heading')}\n\n".$token;
        }

        $parts[] = '*'.$this->t('footer').'*';

        return implode("\n\n", $parts);
    }

    /** This week's M+ affixes from Raider.IO's public endpoint (no key needed). */
    protected function affixes(): ?string
    {
        try {
            $r = $this->http->get('https://raider.io/api/v1/mythic-plus/affixes', [
                'query' => ['region' => $this->api->region(), 'locale' => 'en'],
            ]);
            $data = json_decode((string) $r->getBody(), true);
            $names = array_values(array_filter(array_map(
                fn ($a) => is_array($a) ? (string) ($a['name'] ?? '') : '',
                (array) ($data['affix_details'] ?? [])
            )));

            return $names ? '**'.implode('** · **', $names).'**' : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Darkmoon Faire: opens the first Sunday of each month, runs one week. */
    protected function darkmoon(?Carbon $now = null): string
    {
        $now = $now ?? Carbon::now('UTC');
        $open = new Carbon('first sunday of '.$now->format('F Y'), 'UTC');
        $close = $open->copy()->addDays(7);

        if ($now->gte($close)) {
            $open = new Carbon('first sunday of '.$now->copy()->addMonthNoOverflow()->format('F Y'), 'UTC');
            $close = $open->copy()->addDays(7);
        }

        if ($now->gte($open) && $now->lt($close)) {
            return $this->t('dmf_open', ['date' => $close->format('l, F j')]);
        }

        return $this->t('dmf_closed', ['date' => $open->format('l, F j')]);
    }

    /** Published calendar events between this reset and the next (soft dependency). */
    protected function weekEvents(Carbon $reset): ?string
    {
        if (! $this->db->getSchemaBuilder()->hasTable('calendar_events')) {
            return null;
        }

        try {
            $rows = $this->db->table('calendar_events')
                ->where('is_published', true)
                ->whereBetween('start_at', [$reset->toDateTimeString(), $reset->copy()->addWeek()->toDateTimeString()])
                ->orderBy('start_at')
                ->limit(12)
                ->get(['title', 'start_at']);
        } catch (\Throwable $e) {
            return null;
        }

        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->map(fn ($e) => '- **'.$e->title.'** — '.Carbon::parse($e->start_at)->format('l, M j · H:i \U\T\C'))->implode("\n");
    }

    /** Boss kills + guild achievements since the previous reset. */
    protected function lastWeekActivity(): ?string
    {
        $items = $this->armory->guildRecentActivity(7);
        if (! $items) {
            return null;
        }

        $lines = [];
        foreach (array_slice($items, 0, 12) as $a) {
            $when = Carbon::createFromTimestamp($a['timestamp'], 'UTC')->format('M j');
            $lines[] = $a['type'] === 'kill'
                ? '- ⚔️ '.$this->t('activity_kill', ['boss' => $a['name'], 'mode' => $a['mode'], 'date' => $when])
                : '- 🏅 '.$this->t('activity_achievement', ['name' => $a['name'], 'who' => $a['who'], 'date' => $when]);
        }

        return $lines ? implode("\n", $lines) : null;
    }

    protected function token(): ?string
    {
        $copper = $this->api->tokenPrice($this->api->region());
        if (! $copper) {
            return null;
        }

        return $this->t('token_price', ['gold' => number_format(intdiv($copper, 10000))]);
    }

    /** ---- plumbing ------------------------------------------------- */

    /** Pin the new briefing; unpin the previous one. Both are best-effort. */
    protected function applyPinning(int $discussionId): void
    {
        if (! $this->settings->get('armory.briefing_pin', true)
            || ! $this->db->getSchemaBuilder()->hasColumn('discussions', 'is_sticky')) {
            return;
        }

        try {
            $previous = (int) $this->settings->get('armory.briefing_last_discussion_id');
            if ($previous) {
                $this->db->table('discussions')->where('id', $previous)->update(['is_sticky' => false]);
            }
            $this->db->table('discussions')->where('id', $discussionId)->update(['is_sticky' => true]);
        } catch (\Throwable $e) {
            // Pinning is cosmetic — never fail the briefing over it.
        }
    }

    protected function t(string $key, array $params = []): string
    {
        return $this->translator->trans(
            'ernestdefoe-armory.briefing.'.$key,
            array_combine(array_map(fn ($k) => '{'.$k.'}', array_keys($params)), array_values($params))
        );
    }
}
