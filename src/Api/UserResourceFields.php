<?php

namespace ErnestDefoe\Armory\Api;

use ErnestDefoe\Armory\ArmoryCharacter;
use Flarum\Api\Context;
use Flarum\Api\Schema\Attribute;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * `armoryMain`: the author's main visible character on every serialized user,
 * so the post stream can show a character pane beside each post. Only fields
 * the public armory page already exposes.
 *
 * 🚨 One query per request, for every user on the page. The getter queues its
 * user and returns a deferred value; the first one the serializer resolves
 * loads every queued user's characters at once. Eager loading a relation
 * instead cost one query per include path (`user`, `lastPostedUser`,
 * `mostRelevantPost.user`), and a getter querying for itself cost one per user.
 *
 * Queue and results are keyed by the request in a WeakMap, never set on a
 * model, so a long-lived worker cannot hand one request's characters to the
 * next.
 */
class UserResourceFields
{
    /** @var WeakMap<ServerRequestInterface, array{queued: array<int, true>, mains: array<int, array<string, mixed>|null>}> */
    private WeakMap $byRequest;

    public function __construct()
    {
        $this->byRequest = new WeakMap();
    }

    public function __invoke(): array
    {
        return [
            Attribute::make('armoryMain')
                ->get(function (User $user, Context $context) {
                    $request = $context->request;
                    $id = (int) $user->id;
                    $state = $this->byRequest[$request] ?? ['queued' => [], 'mains' => []];

                    if (! array_key_exists($id, $state['mains'])) {
                        $state['queued'][$id] = true;
                        $this->byRequest[$request] = $state;
                    }

                    return fn () => $this->load($request)[$id] ?? null;
                }),
        ];
    }

    /** @return array<int, array<string, mixed>|null> */
    private function load(ServerRequestInterface $request): array
    {
        $state = $this->byRequest[$request];

        if (! $state['queued']) {
            return $state['mains'];
        }

        $ids = array_keys($state['queued']);

        // Same choice as before: the flagged main, else the highest item
        // level; the id breaks ties so equal characters always resolve alike.
        // Lowest sort position first, so the first row per user wins.
        $characters = ArmoryCharacter::query()
            ->whereIn('user_id', $ids)
            ->where('is_visible', true)
            ->orderByDesc('is_main')
            ->orderByDesc('item_level')
            ->orderBy('id')
            ->get(['user_id', 'name', 'realm_slug', 'level', 'class', 'race', 'spec', 'item_level', 'guild', 'avatar_url', 'render_url']);

        foreach ($ids as $id) {
            $state['mains'][$id] = null;
        }

        foreach ($characters as $c) {
            if ($state['mains'][$c->user_id] !== null) {
                continue;
            }

            $state['mains'][$c->user_id] = [
                'name' => $c->name,
                'realm' => $c->realm_slug,
                'level' => $c->level,
                'class' => $c->class,
                'race' => $c->race,
                'spec' => $c->spec,
                'itemLevel' => $c->item_level,
                'guild' => $c->guild,
                'avatarUrl' => $c->avatar_url,
                'renderUrl' => $c->render_url,
            ];
        }

        $state['queued'] = [];
        $this->byRequest[$request] = $state;

        return $state['mains'];
    }
}
