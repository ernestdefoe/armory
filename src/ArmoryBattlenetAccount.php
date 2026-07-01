<?php

namespace ErnestDefoe\Armory;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's linked Battle.net account. `access_token` is stored encrypted at
 * rest (see Armory::encryptToken / decryptToken).
 *
 * @property int         $id
 * @property int         $user_id
 * @property string|null $battletag
 * @property string      $bnet_id
 * @property string      $region
 * @property string|null $access_token
 * @property \Carbon\Carbon|null $token_expires_at
 * @property \Carbon\Carbon|null $synced_at
 * @property bool        $main_confirmed
 */
class ArmoryBattlenetAccount extends AbstractModel
{
    protected $table = 'armory_battlenet_accounts';

    public $timestamps = true;

    protected $casts = [
        'user_id' => 'integer',
        'token_expires_at' => 'datetime',
        'synced_at' => 'datetime',
        'main_confirmed' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
