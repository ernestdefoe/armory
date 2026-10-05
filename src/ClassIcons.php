<?php

namespace ErnestDefoe\Armory;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The official Blizzard icon URL for every playable class, as one cached map
 * (slug => URL|null). Shared by the recruiting forum attribute and the admin
 * class picker so both hit the API at most once a week — and a pre-credentials
 * all-null map is never cached, so icons appear as soon as the API client is
 * configured.
 */
class ClassIcons
{
    public function __construct(
        protected BlizzardApi $api,
        protected SettingsRepositoryInterface $settings,
        protected Cache $cache
    ) {
    }

    /** @return array<string, string|null> slug => icon URL */
    public function map(): array
    {
        try {
            $region = (string) $this->settings->get('armory.region');
            $region = in_array($region, ['us', 'eu', 'kr', 'tw'], true) ? $region : 'us';
            $key = 'armory.class-icons.' . $region;

            $icons = $this->cache->get($key);
            if (is_array($icons)) {
                return $icons;
            }

            // 🚨 A failed fetch is remembered briefly too. This runs while the
            // forum payload is built, on every page; with credentials wrong or
            // Blizzard down, an empty map that was never cached meant a token
            // request and a dozen API calls (12s timeout each) on every page
            // load. Now that retry happens at most every five minutes.
            if ($this->cache->get($key.'.miss')) {
                return array_fill_keys(array_keys(PlayableClasses::ALL), null);
            }

            $icons = [];
            foreach (PlayableClasses::ALL as $slug => [$id, $name]) {
                $icons[$slug] = $this->api->playableClassIcon($region, $id);
            }

            if (array_filter($icons) !== []) {
                $this->cache->put($key, $icons, 604800);
            } else {
                $this->cache->put($key.'.miss', true, 300);
            }

            return $icons;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
