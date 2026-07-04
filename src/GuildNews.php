<?php

namespace ErnestDefoe\Armory;

use Carbon\Carbon;
use ErnestDefoe\Armory\Support\GuildPoster;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * First-kill hype threads: watches the Battle.net guild activity feed and
 * posts a news discussion the first time a boss falls on a given
 * difficulty. Repeat clears never post again (per-boss+mode seen map).
 *
 * The first run after enabling SEEDS the map from the current feed
 * without posting, so switching the feature on doesn't burst out threads
 * for kills everyone already celebrated.
 */
class GuildNews
{
    protected const SEEN_KEY = 'armory.news_kills_seen';
    protected const SEEDED_KEY = 'armory.news_seeded';
    protected const FEED_DAYS = 14;
    protected const MAX_POSTS_PER_RUN = 3;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Armory $armory,
        protected GuildPoster $poster,
        protected TranslatorInterface $translator,
        protected LoggerInterface $log,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('armory.news_enabled')
            && trim((string) $this->settings->get('armory.guild_name')) !== '';
    }

    /** Returns the number of news threads posted. */
    public function run(): int
    {
        $kills = array_values(array_filter(
            $this->activity(),
            fn ($a) => ($a['type'] ?? '') === 'kill' && ($a['name'] ?? '') !== ''
        ));
        if (! $kills) {
            return 0;
        }

        $seen = $this->seen();

        if (! $this->settings->get(self::SEEDED_KEY)) {
            foreach ($kills as $k) {
                $seen[$this->keyOf($k)] = (int) ($k['timestamp'] ?? 0);
            }
            $this->save($seen);
            $this->settings->set(self::SEEDED_KEY, '1');

            return 0;
        }

        $fresh = array_filter($kills, fn ($k) => ! isset($seen[$this->keyOf($k)]));
        if (! $fresh) {
            return 0;
        }

        usort($fresh, fn ($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));

        $posted = 0;
        foreach (array_slice($fresh, 0, self::MAX_POSTS_PER_RUN) as $kill) {
            $seen[$this->keyOf($kill)] = (int) ($kill['timestamp'] ?? 0);
            if ($this->postKill($kill)) {
                $posted++;
            }
        }
        $this->save($seen);

        return $posted;
    }

    /** ---- content -------------------------------------------------- */

    protected function postKill(array $kill): bool
    {
        $boss = (string) $kill['name'];
        $mode = (string) ($kill['mode'] ?? '');
        $when = Carbon::createFromTimestamp((int) ($kill['timestamp'] ?? time()), 'UTC');
        $guild = trim((string) $this->settings->get('armory.guild_name'));

        $title = $mode !== ''
            ? $this->t('kill_title_mode', ['boss' => $boss, 'mode' => $mode])
            : $this->t('kill_title', ['boss' => $boss]);

        $content = implode("\n\n", [
            $this->t('kill_intro', [
                'boss' => $boss,
                'mode' => $mode !== '' ? $mode : $this->t('mode_unknown'),
                'guild' => $guild,
                'date' => $when->format('l, F j'),
            ]),
            $this->t('kill_body'),
            '*'.$this->t('footer').'*',
        ]);

        return (bool) $this->poster->post($title, $content, ['armory.news_tag_slug', 'armory.briefing_tag_slug']);
    }

    /** ---- plumbing ------------------------------------------------- */

    /** Feed rows from the armory service; separated so tests can stub it. */
    protected function activity(): array
    {
        return $this->armory->guildRecentActivity(self::FEED_DAYS);
    }

    protected function keyOf(array $kill): string
    {
        return mb_strtolower(($kill['name'] ?? '').'|'.($kill['mode'] ?? ''));
    }

    protected function seen(): array
    {
        $map = json_decode((string) $this->settings->get(self::SEEN_KEY), true);

        return is_array($map) ? $map : [];
    }

    protected function save(array $seen): void
    {
        if (count($seen) > 400) {
            asort($seen);
            $seen = array_slice($seen, -400, null, true);
        }
        $this->settings->set(self::SEEN_KEY, json_encode($seen));
    }

    protected function t(string $key, array $params = []): string
    {
        return $this->translator->trans(
            'ernestdefoe-armory.news.'.$key,
            array_combine(array_map(fn ($k) => '{'.$k.'}', array_keys($params)), array_values($params))
        );
    }
}
