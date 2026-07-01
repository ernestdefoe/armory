<?php

use Flarum\Database\Migration;

// Whether the member has explicitly confirmed their primary character (the
// signup onboarding asks them to pick one; syncs auto-assign a provisional
// main until then).
return Migration::addColumns('armory_battlenet_accounts', [
    'main_confirmed' => ['boolean', 'default' => false],
]);
