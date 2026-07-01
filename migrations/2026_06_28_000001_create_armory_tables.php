<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * The two Armory tables. Migration::createTable() wraps each table individually
 * (there is no multi-table helper), composed here so both create/roll back
 * together as one migration step.
 */
$accounts = Migration::createTable('armory_battlenet_accounts', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->string('battletag')->nullable();
    $table->string('bnet_id');
    $table->string('region', 8)->default('us');
    $table->text('access_token')->nullable();
    $table->dateTime('token_expires_at')->nullable();
    $table->dateTime('synced_at')->nullable();
    $table->timestamps();
    $table->unique('bnet_id');
    $table->unique('user_id');
});

$characters = Migration::createTable('armory_characters', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->string('region', 8)->default('us');
    $table->string('realm_slug');
    $table->string('name');
    $table->unsignedBigInteger('character_id')->nullable();
    $table->unsignedSmallInteger('level')->default(0);
    $table->string('class')->nullable();
    $table->string('race')->nullable();
    $table->string('faction')->nullable();
    $table->string('spec')->nullable();
    $table->unsignedInteger('item_level')->default(0);
    $table->string('guild')->nullable();
    $table->string('avatar_url')->nullable();
    $table->string('render_url')->nullable();
    $table->boolean('is_main')->default(false);
    $table->boolean('is_visible')->default(true);
    $table->dateTime('synced_at')->nullable();
    $table->timestamps();
    $table->unique(['region', 'realm_slug', 'name']);
    $table->index('user_id');
});

return [
    'up' => function (Builder $schema) use ($accounts, $characters) {
        $accounts['up']($schema);
        $characters['up']($schema);
    },
    'down' => function (Builder $schema) use ($accounts, $characters) {
        $characters['down']($schema);
        $accounts['down']($schema);
    },
];
