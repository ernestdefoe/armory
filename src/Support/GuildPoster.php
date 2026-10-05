<?php

namespace ErnestDefoe\Armory\Support;

use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared plumbing for armory's automated guild posts (briefing, raid recaps,
 * guild news, patch notes, strategy hubs): posts a discussion through the
 * in-process JSON:API, tagged by the first non-empty slug in a settings
 * fallback chain.
 *
 * 🚨 WHO POSTS, AND WHY THE PERMISSION CHECK IS OURS.
 *
 * These are system posts with no member behind them, so they are written as
 * the forum's FIRST ADMINISTRATOR (lowest user id in the Admin group) — always
 * that one account, never the member who triggered a job or anyone chosen from
 * data. It is the closest thing a stock Flarum has to a site account, and it
 * is the account the README tells operators these threads come from.
 *
 * `JsonApi::process()` runs the endpoint's ACTION directly. The endpoint's own
 * gate — `Create::make()->authenticated()->can('startDiscussion')` on
 * DiscussionResource — lives in `Endpoint::handle()`, which an HTTP request
 * goes through and `process()` does not. Field-level checks (flarum/tags'
 * per-tag `startDiscussion`) still run. For an administrator every check
 * passes anyway, so nothing is actually bypassed today; the checks below
 * exist so that stays TRUE rather than incidental, and so a refusal is a line
 * in the log naming the reason instead of a thread appearing somewhere nobody
 * meant it to.
 */
class GuildPoster
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db,
        protected JsonApi $jsonApi,
        protected LoggerInterface $log,
    ) {
    }

    /**
     * @param string[] $tagSlugSettings settings keys tried in order for the tag slug
     *
     * @return int|null the new discussion id, or null when nothing was posted (reason logged)
     */
    public function post(string $title, string $content, array $tagSlugSettings = []): ?int
    {
        $actor = $this->actor();
        if (! $actor) {
            $this->log->warning('[armory] guild post skipped: the forum has no administrator to post as');

            return null;
        }

        [$slug, $setting] = $this->configuredSlug($tagSlugSettings);
        $tag = null;

        if ($slug !== '' && $this->tagsInstalled()) {
            $tag = $this->tagModel()::query()->where('slug', $slug)->first();

            // A configured tag that does not exist is a typo or a deleted tag.
            // Posting untagged instead would drop the thread outside every tag
            // the guild reads — say so and post nothing.
            if (! $tag) {
                $this->log->warning("[armory] guild post skipped: tag '{$slug}' (setting {$setting}) does not exist");

                return null;
            }
        }

        $refusal = $this->refusal($actor, $tag);
        if ($refusal !== null) {
            $this->log->warning("[armory] guild post skipped: {$actor->username} {$refusal}");

            return null;
        }

        try {
            /** @var Discussion $discussion */
            $discussion = $this->jsonApi
                ->forResource(DiscussionResource::class)
                ->forEndpoint('create')
                ->process([
                    'data' => [
                        'attributes' => ['title' => $title, 'content' => $content],
                        'relationships' => $tag
                            ? ['tags' => ['data' => [['type' => 'tags', 'id' => (string) $tag->id]]]]
                            : [],
                    ],
                ], [], ['actor' => $actor]);
        } catch (\Throwable $e) {
            $this->log->error('[armory] guild post failed: '.$e->getMessage());

            return null;
        }

        return (int) $discussion->id;
    }

    /**
     * The checks `process()` skips, made here: may this account start a
     * discussion at all, and in this tag. Null when it may.
     */
    protected function refusal(User $actor, $tag): ?string
    {
        if (! $actor->isAdmin()) {
            return 'is not an administrator';
        }

        if ($tag && $actor->cannot('startDiscussion', $tag)) {
            return "may not start discussions in tag '{$tag->slug}'";
        }

        if (! $tag && $actor->cannot('startDiscussion')) {
            return 'may not start discussions';
        }

        return null;
    }

    /** @return array{0: string, 1: string} the first non-empty slug and the setting it came from */
    protected function configuredSlug(array $tagSlugSettings): array
    {
        foreach ($tagSlugSettings as $key) {
            $slug = trim((string) $this->settings->get($key));
            if ($slug !== '') {
                return [$slug, $key];
            }
        }

        return ['', ''];
    }

    protected function tagsInstalled(): bool
    {
        return class_exists($this->tagModel()) && $this->db->getSchemaBuilder()->hasTable('tags');
    }

    /** A string, so this class loads on a forum without flarum/tags. */
    protected function tagModel(): string
    {
        return 'Flarum\\Tags\\Tag';
    }

    protected function actor(): ?User
    {
        return User::query()
            ->whereHas('groups', fn ($q) => $q->where('id', Group::ADMINISTRATOR_ID))
            ->orderBy('id')
            ->first();
    }
}
