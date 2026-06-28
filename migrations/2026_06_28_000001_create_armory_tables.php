<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('armory_battlenet_accounts')) {
            $schema->create('armory_battlenet_accounts', function (Blueprint $table) {
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
        }

        if (! $schema->hasTable('armory_characters')) {
            $schema->create('armory_characters', function (Blueprint $table) {
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
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('armory_characters');
        $schema->dropIfExists('armory_battlenet_accounts');
    },
];
