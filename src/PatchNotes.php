<?php

namespace ErnestDefoe\Armory;

use ErnestDefoe\Armory\Support\GuildPoster;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Auto-posts WoW patch notes / hotfix articles from the Wowhead news feed
 * (Blizzard retired their public RSS). Only titles that look like patch
 * content post — hotfixes, patch notes, class tuning — never general news.
 * The first run after enabling seeds silently, mirroring GuildNews.
 */
class PatchNotes
{
    protected const FEED_URL = 'https://www.wowhead.com/news/rss/all';
    protected const SEEN_KEY = 'armory.patchnotes_seen';
    protected const SEEDED_KEY = 'armory.patchnotes_seeded';
    protected const MATCH = '/hotfix|patch\s+notes|class\s+tuning|patch\s+\d+\.\d+.*(notes|changes)/i';
    protected const MAX_POSTS_PER_RUN = 2;
    protected const LEDGER_MAX = 40;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected GuildPoster $poster,
        protected TranslatorInterface $translator,
        protected LoggerInterface $log,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('armory.patchnotes_enabled');
    }

    /** Returns the number of articles posted. */
    public function run(): int
    {
        $items = array_values(array_filter(
            $this->feed(),
            fn ($i) => preg_match(self::MATCH, $i['title']) === 1
        ));
        if (! $items) {
            return 0;
        }

        $seen = $this->ledger();

        if (! $this->settings->get(self::SEEDED_KEY)) {
            $this->remember(array_map(fn ($i) => $i['guid'], $items));
            $this->settings->set(self::SEEDED_KEY, '1');

            return 0;
        }

        $fresh = array_filter($items, fn ($i) => ! in_array($i['guid'], $seen, true));
        if (! $fresh) {
            return 0;
        }

        usort($fresh, fn ($a, $b) => $a['time'] <=> $b['time']);

        $posted = 0;
        $newGuids = [];
        foreach (array_slice($fresh, 0, self::MAX_POSTS_PER_RUN) as $item) {
            $newGuids[] = $item['guid'];
            if ($this->post($item)) {
                $posted++;
            }
        }
        $this->remember($newGuids);

        return $posted;
    }

    /** ---- content -------------------------------------------------- */

    protected function post(array $item): bool
    {
        $title = '🛠️ '.$item['title'];
        $content = implode("\n\n", array_filter([
            $item['excerpt'] !== '' ? $item['excerpt'] : null,
            '[**'.$this->t('read_more').'**]('.$item['link'].')',
            '*'.$this->t('footer').'*',
        ]));

        return (bool) $this->poster->post($title, $content, [
            'armory.patchnotes_tag_slug',
            'armory.news_tag_slug',
            'armory.briefing_tag_slug',
        ]);
    }

    /** ---- plumbing ------------------------------------------------- */

    /** @return array<int, array{guid: string, title: string, link: string, excerpt: string, time: int}> */
    protected function feed(): array
    {
        try {
            $http = new Client(['timeout' => 12, 'http_errors' => false]);
            $r = $http->get(self::FEED_URL, ['headers' => ['User-Agent' => 'Mozilla/5.0 (armory guild hub)']]);
            if ($r->getStatusCode() !== 200) {
                return [];
            }
            $xml = @simplexml_load_string((string) $r->getBody(), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if (! $xml || ! isset($xml->channel->item)) {
                return [];
            }

            $out = [];
            foreach ($xml->channel->item as $item) {
                $title = trim((string) $item->title);
                $link = trim((string) $item->link);
                if ($title === '' || $link === '') {
                    continue;
                }
                $excerpt = trim(html_entity_decode(strip_tags((string) $item->description)));
                if (mb_strlen($excerpt) > 400) {
                    $excerpt = mb_substr($excerpt, 0, 400).'…';
                }
                $out[] = [
                    'guid' => (string) ($item->guid ?: $link),
                    'title' => $title,
                    'link' => $link,
                    'excerpt' => $excerpt,
                    'time' => (int) strtotime((string) $item->pubDate) ?: 0,
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            $this->log->warning('[armory] patch-notes feed error: '.$e->getMessage());

            return [];
        }
    }

    protected function ledger(): array
    {
        $list = json_decode((string) $this->settings->get(self::SEEN_KEY), true);

        return is_array($list) ? $list : [];
    }

    protected function remember(array $guids): void
    {
        $list = array_slice(array_values(array_unique(array_merge($this->ledger(), $guids))), -self::LEDGER_MAX);
        $this->settings->set(self::SEEN_KEY, json_encode($list));
    }

    protected function t(string $key, array $params = []): string
    {
        return $this->translator->trans(
            'ernestdefoe-armory.patchnotes.'.$key,
            array_combine(array_map(fn ($k) => '{'.$k.'}', array_keys($params)), array_values($params))
        );
    }
}
