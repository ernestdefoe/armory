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
 * Shared plumbing for armory's automated guild posts (briefing, raid
 * recaps, guild news): posts a discussion as the first admin via the
 * in-process JSON:API, tagged by the first non-empty slug in a settings
 * fallback chain.
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

    /** @param string[] $tagSlugSettings settings keys tried in order for the tag slug */
    public function post(string $title, string $content, array $tagSlugSettings = []): ?int
    {
        $actor = $this->actor();
        if (! $actor) {
            $this->log->warning('[armory] guild post: no admin user to post as');

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
                        'relationships' => $this->tagRelationship($tagSlugSettings),
                    ],
                ], [], ['actor' => $actor]);
        } catch (\Throwable $e) {
            $this->log->error('[armory] guild post failed: '.$e->getMessage());

            return null;
        }

        return (int) $discussion->id;
    }

    protected function tagRelationship(array $tagSlugSettings): array
    {
        $slug = '';
        foreach ($tagSlugSettings as $key) {
            $slug = trim((string) $this->settings->get($key));
            if ($slug !== '') {
                break;
            }
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
}
