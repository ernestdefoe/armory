<?php

namespace ErnestDefoe\Armory;

use Carbon\Carbon;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Post-raid recap threads from Warcraft Logs. The hourly command looks at
 * the guild's recent public reports; when one has FINISHED (ended more
 * than ~45 minutes ago) and hasn't been posted yet, a recap discussion
 * goes up: per-boss kills/wipes with best pulls, the night's top parses,
 * and a link to the full report. One recap per run, oldest first, so a
 * backlog never floods the forum.
 */
class RaidRecap
{
    protected const SETTLE_MINUTES = 45;
    protected const MAX_AGE_DAYS = 14;
    protected const LEDGER_KEY = 'armory.wcl_processed';
    protected const LEDGER_MAX = 10;

    /** WCL raid difficulty ids → labels. */
    protected const DIFFICULTIES = [
        1 => 'LFR',
        3 => 'Normal',
        4 => 'Heroic',
        5 => 'Mythic',
    ];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db,
        protected WarcraftLogs $wcl,
        protected JsonApi $jsonApi,
        protected TranslatorInterface $translator,
        protected LoggerInterface $log,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('armory.wcl_enabled')
            && $this->wcl->configured()
            && trim((string) $this->settings->get('armory.guild_name')) !== '';
    }

    /**
     * Post the next unprocessed finished report, if any.
     * Returns the new discussion id, or null when nothing was due.
     */
    public function run(bool $force = false): ?int
    {
        $reports = $this->wcl->recentReports(
            trim((string) $this->settings->get('armory.guild_name')),
            trim((string) $this->settings->get('armory.guild_realm')),
            trim((string) ($this->settings->get('armory.region') ?: 'us')),
        );
        if (! $reports) {
            return null;
        }

        $processed = $this->ledger();
        $cutoffOld = Carbon::now('UTC')->subDays(self::MAX_AGE_DAYS)->getTimestampMs();
        $cutoffSettled = Carbon::now('UTC')->subMinutes(self::SETTLE_MINUTES)->getTimestampMs();

        $due = array_filter($reports, function (array $r) use ($processed, $cutoffOld, $cutoffSettled, $force) {
            $end = (float) ($r['endTime'] ?? 0);
            $hasEncounters = ! empty($r['fights']);

            return ($r['code'] ?? '') !== ''
                && $hasEncounters
                && $end > $cutoffOld
                && $end < $cutoffSettled
                && ($force || ! in_array($r['code'], $processed, true));
        });
        if (! $due) {
            return null;
        }

        usort($due, fn ($a, $b) => ($a['endTime'] <=> $b['endTime']));
        $report = $due[0];

        $actor = $this->actor();
        if (! $actor) {
            $this->log->warning('[armory] raid recap: no admin user to post as');

            return null;
        }

        $ended = Carbon::createFromTimestampMs((float) $report['endTime'], 'UTC');
        $zone = (string) ($report['zone']['name'] ?? $this->t('unknown_zone'));
        $title = $this->t('title', ['zone' => $zone, 'date' => $ended->format('F j, Y')]);
        $content = $this->compose($report, $zone);

        try {
            /** @var Discussion $discussion */
            $discussion = $this->jsonApi
                ->forResource(DiscussionResource::class)
                ->forEndpoint('create')
                ->process([
                    'data' => [
                        'attributes' => ['title' => $title, 'content' => $content],
                        'relationships' => $this->tagRelationship(),
                    ],
                ], [], ['actor' => $actor]);
        } catch (\Throwable $e) {
            $this->log->error('[armory] raid recap post failed: '.$e->getMessage());

            return null;
        }

        $this->remember((string) $report['code']);

        return (int) $discussion->id;
    }

    /** ---- content -------------------------------------------------- */

    public function compose(array $report, string $zone): string
    {
        $start = Carbon::createFromTimestampMs((float) ($report['startTime'] ?? 0), 'UTC');
        $end = Carbon::createFromTimestampMs((float) ($report['endTime'] ?? 0), 'UTC');
        $minutes = max(1, (int) $start->diffInMinutes($end));
        $duration = sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
        $url = 'https://www.warcraftlogs.com/reports/'.$report['code'];

        $parts = [$this->t('intro', [
            'zone' => $zone,
            'date' => $end->format('l, F j'),
            'duration' => $duration,
        ])];

        if ($bosses = $this->bosses($report['fights'] ?? [])) {
            $parts[] = "### ⚔️ {$this->t('bosses_heading')}\n\n".$bosses;
        }

        if ($parses = $this->topParses((string) $report['code'])) {
            $parts[] = "### 🎯 {$this->t('parses_heading')}\n\n".$parses;
        }

        $parts[] = '[**'.$this->t('report_link').'**]('.$url.')';
        $parts[] = '*'.$this->t('footer').'*';

        return implode("\n\n", $parts);
    }

    /** Per boss+difficulty: kill with pull count, or wipes with the best pull. */
    protected function bosses(array $fights): ?string
    {
        $byBoss = [];
        foreach ($fights as $f) {
            if (! is_array($f) || ($f['name'] ?? '') === '') {
                continue;
            }
            $key = $f['name'].'|'.($f['difficulty'] ?? 0);
            $byBoss[$key] ??= ['name' => $f['name'], 'difficulty' => (int) ($f['difficulty'] ?? 0), 'pulls' => 0, 'kill' => false, 'best' => 100.0];
            $byBoss[$key]['pulls']++;
            if (! empty($f['kill'])) {
                $byBoss[$key]['kill'] = true;
            }
            $pct = (float) ($f['bossPercentage'] ?? 100);
            $byBoss[$key]['best'] = min($byBoss[$key]['best'], $pct);
        }
        if (! $byBoss) {
            return null;
        }

        $lines = [];
        foreach ($byBoss as $b) {
            $mode = self::DIFFICULTIES[$b['difficulty']] ?? '';
            $label = '**'.$b['name'].'**'.($mode !== '' ? ' ('.$mode.')' : '');
            $lines[] = $b['kill']
                ? '- ✅ '.$this->t('boss_kill', ['boss' => $label, 'pulls' => $b['pulls']])
                : '- ❌ '.$this->t('boss_wipe', ['boss' => $label, 'pulls' => $b['pulls'], 'best' => number_format($b['best'], 1)]);
        }

        return implode("\n", $lines);
    }

    /** Best rank percent per character across the report's kill fights. */
    protected function topParses(string $code): ?string
    {
        $rankings = $this->wcl->reportRankings($code);
        $fightRows = $rankings['data'] ?? null;
        if (! is_array($fightRows)) {
            return null;
        }

        $best = [];
        foreach ($fightRows as $fight) {
            $roles = $fight['roles'] ?? [];
            foreach (['dps', 'healers', 'tanks'] as $role) {
                foreach ((array) ($roles[$role]['characters'] ?? []) as $ch) {
                    $name = (string) ($ch['name'] ?? '');
                    $pct = $ch['rankPercent'] ?? null;
                    if ($name === '' || ! is_numeric($pct)) {
                        continue;
                    }
                    $pct = (float) $pct;
                    if (! isset($best[$name]) || $pct > $best[$name]['pct']) {
                        $best[$name] = ['pct' => $pct, 'role' => $role, 'class' => (string) ($ch['class'] ?? '')];
                    }
                }
            }
        }
        if (! $best) {
            return null;
        }

        uasort($best, fn ($a, $b) => $b['pct'] <=> $a['pct']);
        $lines = [];
        foreach (array_slice($best, 0, 5, true) as $name => $row) {
            $lines[] = '- **'.$name.'** — '.number_format($row['pct'], 0).' ('.$row['role'].')';
        }

        return implode("\n", $lines);
    }

    /** ---- plumbing ------------------------------------------------- */

    protected function ledger(): array
    {
        return array_values(array_filter(explode(',', (string) $this->settings->get(self::LEDGER_KEY))));
    }

    protected function remember(string $code): void
    {
        $codes = array_slice(array_unique(array_merge($this->ledger(), [$code])), -self::LEDGER_MAX);
        $this->settings->set(self::LEDGER_KEY, implode(',', $codes));
    }

    protected function tagRelationship(): array
    {
        $slug = trim((string) $this->settings->get('armory.recap_tag_slug'));
        if ($slug === '') {
            $slug = trim((string) $this->settings->get('armory.briefing_tag_slug'));
        }
        if ($slug === '' || ! $this->db->getSchemaBuilder()->hasTable('tags')) {
            return [];
        }
        $id = $this->db->table('tags')->where('slug', $slug)->value('id');

        return $id ? ['tags' => ['data' => [['type' => 'tags', 'id' => (string) $id]]]] : [];
    }

    protected function actor(): ?User
    {
        return User::query()
            ->whereHas('groups', fn ($q) => $q->where('id', Group::ADMINISTRATOR_ID))
            ->orderBy('id')
            ->first();
    }

    protected function t(string $key, array $params = []): string
    {
        return $this->translator->trans(
            'ernestdefoe-armory.recap.'.$key,
            array_combine(array_map(fn ($k) => '{'.$k.'}', array_keys($params)), array_values($params))
        );
    }
}
