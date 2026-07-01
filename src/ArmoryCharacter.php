<?php

namespace ErnestDefoe\Armory;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A synced World of Warcraft character.
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $region
 * @property string      $realm_slug
 * @property string      $name
 * @property int|null    $character_id
 * @property int         $level
 * @property string|null $class
 * @property string|null $race
 * @property string|null $faction
 * @property string|null $spec
 * @property int         $item_level
 * @property string|null $guild
 * @property string|null $avatar_url
 * @property string|null $render_url
 * @property bool        $is_main
 * @property bool        $is_visible
 */
class ArmoryCharacter extends AbstractModel
{
    protected $table = 'armory_characters';

    // This table has created_at/updated_at columns; let Eloquent manage them.
    public $timestamps = true;

    protected $casts = [
        'user_id' => 'integer',
        'character_id' => 'integer',
        'level' => 'integer',
        'item_level' => 'integer',
        'is_main' => 'boolean',
        'is_visible' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
