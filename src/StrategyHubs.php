<?php

namespace ErnestDefoe\Armory;

use ErnestDefoe\Armory\Support\GuildPoster;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Boss strategy hubs: one scaffolded discussion per boss of the guild's
 * CURRENT raid (the last instance in the current-expansion progression),
 * so assignments, vods and notes always have a predictable home. Boss
 * names come from the same char-union progression data as the /guild
 * banner — no extra API. A per-boss ledger keeps threads unique; at most
 * two are created per run so enabling never floods the forum.
 */
class StrategyHubs
{
    protected const LEDGER_KEY = 'armory.strategy_created';
    protected const MAX_POSTS_PER_RUN = 2;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected GuildLeaderboard $leaderboard,
        protected GuildPoster $poster,
        protected TranslatorInterface $translator,
        protected LoggerInterface $log,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('armory.strategy_enabled');
    }

    /** Returns the number of hub threads created. */
    public function run(): int
    {
        $instances = $this->progression();
        if (! $instances) {
            return 0;
        }

        $raid = end($instances);
        $bosses = $raid['bosses'] ?? [];
        if (! $bosses) {
            return 0;
        }

        $ledger = $this->ledger();
        $created = 0;

        foreach ($bosses as $boss) {
            if ($created >= self::MAX_POSTS_PER_RUN) {
                break;
            }
            $key = mb_strtolower($raid['name'].'|'.$boss);
            if (isset($ledger[$key])) {
                continue;
            }

            $id = $this->poster->post(
                $this->t('title', ['boss' => $boss, 'raid' => $raid['name']]),
                $this->compose($boss, $raid['name']),
                ['armory.strategy_tag_slug', 'armory.briefing_tag_slug']
            );
            if ($id) {
                $ledger[$key] = $id;
                $created++;
            }
        }

        if ($created > 0) {
            $this->settings->set(self::LEDGER_KEY, json_encode($ledger));
        }

        return $created;
    }

    /** ---- content -------------------------------------------------- */

    protected function compose(string $boss, string $raid): string
    {
        $wowhead = 'https://www.wowhead.com/search?q='.rawurlencode($boss);

        return implode("\n\n", [
            $this->t('intro', ['boss' => $boss, 'raid' => $raid]),
            "### 📖 {$this->t('overview_heading')}\n\n".$this->t('overview_body', ['url' => $wowhead]),
            "### 🎯 {$this->t('assignments_heading')}\n\n".$this->t('assignments_body'),
            "### 🎬 {$this->t('logs_heading')}\n\n".$this->t('logs_body'),
            '*'.$this->t('footer').'*',
        ]);
    }

    /** ---- plumbing ------------------------------------------------- */

    /**
     * Progression rows for the current raid; separated so tests can stub it.
     * A daily cron, so building on a cold cache here is fine (off the request
     * path) — the web endpoints only ever read the pre-built cache.
     */
    protected function progression(): array
    {
        return $this->leaderboard->progression() ?? $this->leaderboard->buildProgression();
    }

    protected function ledger(): array
    {
        $map = json_decode((string) $this->settings->get(self::LEDGER_KEY), true);

        return is_array($map) ? $map : [];
    }

    protected function t(string $key, array $params = []): string
    {
        return $this->translator->trans(
            'ernestdefoe-armory.strategy.'.$key,
            array_combine(array_map(fn ($k) => '{'.$k.'}', array_keys($params)), array_values($params))
        );
    }
}
