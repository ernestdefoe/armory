<?php

namespace ErnestDefoe\Armory\Api;

use ErnestDefoe\Armory\ArmoryBattlenetAccount;
use ErnestDefoe\Armory\ArmoryCharacter;
use ErnestDefoe\Armory\ClassIcons;
use ErnestDefoe\Armory\PlayableClasses;
use Flarum\Api\Context;
use Flarum\Api\Schema\Attribute;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Forum-level armory attributes, extracted from extend.php so their
 * dependencies (settings, the class-icon service) are constructor-injected
 * rather than resolved inline inside the getter closure.
 *
 * - armoryNeedsMain: per-actor "choose your primary character" onboarding nudge.
 * - armoryRecruiting: the guild's now-recruiting list, each class decorated with
 *   its official Blizzard icon (same payload for every visitor).
 */
class ForumResourceFields
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ClassIcons $icons,
    ) {
    }

    public function __invoke(): array
    {
        return [
            Attribute::make('armoryNeedsMain')
                ->get(function ($forum, Context $context) {
                    $actor = $context->getActor();
                    if (! $actor || $actor->isGuest()) {
                        return false;
                    }
                    $acct = ArmoryBattlenetAccount::query()
                        ->where('user_id', $actor->id)
                        ->first(['id', 'main_confirmed']);
                    if ($acct) {
                        return ! $acct->main_confirmed
                            && ArmoryCharacter::query()->where('user_id', $actor->id)->exists();
                    }

                    // Fresh social signup whose link completes lazily on the
                    // first armory visit — nudge them there.
                    return $actor->loginProviders()->where('provider', 'battlenet')->exists();
                }),

            Attribute::make('armoryRecruiting')
                ->get(function () {
                    try {
                        $classes = PlayableClasses::parseRecruiting((string) $this->settings->get('armory.recruiting'));
                        if ($classes === []) {
                            return [];
                        }

                        // Whole-catalog icon map via the shared service (cached
                        // a week; never caches a pre-credentials all-null map).
                        $icons = $this->icons->map();

                        return array_map(fn ($c) => [
                            'slug' => $c['slug'],
                            'name' => $c['name'],
                            'note' => $c['note'],
                            'icon' => $icons[$c['slug']] ?? null,
                        ], $classes);
                    } catch (\Throwable $e) {
                        return [];
                    }
                }),
        ];
    }
}
