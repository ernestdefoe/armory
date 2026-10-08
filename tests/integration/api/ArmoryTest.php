<?php

namespace ErnestDefoe\Armory\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Armory\GuildLeaderboard;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Store;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The armory's own endpoints and attributes. Battle.net is left unconfigured,
 * so nothing here reaches Blizzard: every remote call short-circuits on the
 * missing client id.
 */
class ArmoryTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-armory');

        $char = fn (int $id, int $user, string $name, array $extra = []) => $extra + [
            'id' => $id, 'user_id' => $user, 'region' => 'us', 'realm_slug' => 'area-52', 'name' => $name,
            'level' => 80, 'class' => 'Mage', 'item_level' => 600, 'is_main' => false, 'is_visible' => true,
            'avatar_url' => "https://render.worldofwarcraft.com/$name.jpg",
        ];

        $this->prepareDatabase([
            User::class => [
                ['avatar_url' => null] + $this->normalUser(),
                ['id' => 3, 'username' => 'carol', 'email' => 'carol@machine.local', 'is_email_confirmed' => 1, 'avatar_url' => 'carol.png'],
            ],
            'armory_battlenet_accounts' => [
                ['id' => 1, 'user_id' => 3, 'bnet_id' => '1001', 'battletag' => 'Carol#1234', 'region' => 'us', 'main_confirmed' => false],
            ],
            'armory_characters' => [
                $char(1, 3, 'Frostbolt', ['is_main' => true]),
                $char(2, 3, 'Alt', ['item_level' => 610, 'is_visible' => false]),
                $char(3, 3, 'Second', ['item_level' => 590]),
                $char(4, 2, 'Normalmage'),
            ],
        ]);
    }

    private function json(string $method, string $path, ?int $actor = null, array $query = [], ?array $body = null): array
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $request = $this->request($method, $path, $options)->withQueryParams($query);
        if ($method !== 'GET' && ! $actor) {
            $request = $this->withGuestSession($request);
        }

        $response = $this->send($request);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    /** A guest's write needs a session and its CSRF token, as a browser has. */
    private function withGuestSession(ServerRequestInterface $request): ServerRequestInterface
    {
        $initial = $this->send($this->request('GET', '/api'));

        return $this->requestWithCookiesFrom($request->withHeader('X-CSRF-Token', $initial->getHeaderLine('X-CSRF-Token')), $initial);
    }

    private function character(int $id): object
    {
        return $this->database()->table('armory_characters')->where('id', $id)->first();
    }

    #[Test]
    public function member_only_endpoints_refuse_guests()
    {
        foreach (['/api/armory/me', '/api/armory/item-search', '/api/armory/vault'] as $path) {
            [$status] = $this->json('GET', $path);
            $this->assertSame(401, $status, $path);
        }

        foreach (['/api/armory/sync', '/api/armory/guild/refresh', '/api/armory/character/1/main'] as $path) {
            [$status] = $this->json('POST', $path);
            $this->assertSame(401, $status, $path);
        }
    }

    #[Test]
    public function the_public_endpoints_answer_without_battlenet()
    {
        [$status, $body] = $this->json('GET', '/api/armory/config');
        $this->assertSame(200, $status);
        $this->assertSame(['configured' => false, 'region' => 'us'], $body);

        [$status, $body] = $this->json('GET', '/api/armory/classes');
        $this->assertSame(200, $status);
        $this->assertContains('mage', array_column($body['data'], 'slug'));

        foreach (['/api/armory/guild', '/api/armory/token', '/api/armory/item/19019'] as $path) {
            [$status, $body] = $this->json('GET', $path);
            $this->assertSame(200, $status, $path);
            $this->assertFalse($body['ok'], $path);
        }

        [, $body] = $this->json('GET', '/api/armory/guild/mplus');
        $this->assertSame(['ok' => false, 'reason' => 'pending'], $body);
        [, $body] = $this->json('GET', '/api/armory/guild/progression');
        $this->assertSame(['ok' => false, 'reason' => 'pending'], $body);
    }

    #[Test]
    public function a_hidden_character_is_hidden_everywhere()
    {
        [, $body] = $this->json('GET', '/api/armory/user/3');
        $this->assertSame(['Frostbolt', 'Second'], array_column($body['characters'], 'name'));

        [$status, $body] = $this->json('GET', '/api/armory/full/1');
        $this->assertSame(200, $status);
        $this->assertTrue($body['ok']);
        $this->assertSame('Frostbolt', $body['character']['name']);

        [, $body] = $this->json('GET', '/api/armory/full/2');
        $this->assertSame(['ok' => false], $body);
        [, $body] = $this->json('GET', '/api/armory/extra/2/pvp');
        $this->assertSame(['ok' => false], $body);
        [, $body] = $this->json('GET', '/api/armory/extra/1/not-a-tab');
        $this->assertSame(['ok' => false], $body);

        [$status] = $this->json('GET', '/api/armory/vault', 2, ['id' => 2]);
        $this->assertSame(404, $status);
        [$status, $body] = $this->json('GET', '/api/armory/vault', 2, ['id' => 1]);
        $this->assertSame(200, $status);
        $this->assertSame('Frostbolt', $body['character']['name']);
    }

    #[Test]
    public function a_member_manages_only_their_own_characters()
    {
        // Someone else's character: refused, nothing changes.
        [$status, $body] = $this->json('POST', '/api/armory/character/3/main', 2);
        $this->assertSame(200, $status);
        $this->assertFalse($body['ok']);
        $this->assertSame(0, (int) $this->character(3)->is_main);

        [, $body] = $this->json('POST', '/api/armory/character/1/visible', 2);
        $this->assertFalse($body['ok']);
        $this->assertSame(1, (int) $this->character(1)->is_visible);

        // Their own.
        [, $body] = $this->json('POST', '/api/armory/character/3/main', 3);
        $this->assertTrue($body['ok']);
        $this->assertSame(1, (int) $this->character(3)->is_main);
        $this->assertSame(0, (int) $this->character(1)->is_main, 'One main at a time');
        $this->assertSame(1, (int) $this->database()->table('armory_battlenet_accounts')->where('user_id', 3)->value('main_confirmed'));

        [, $body] = $this->json('POST', '/api/armory/character/3/visible', 3);
        $this->assertTrue($body['ok']);
        $this->assertSame(0, (int) $this->character(3)->is_visible);

        [, $body] = $this->json('POST', '/api/armory/character/3/disconnect', 2);
        $this->assertTrue($body['ok']);
        $this->assertSame(3, $this->database()->table('armory_characters')->where('user_id', 3)->count(), 'Disconnecting removes only the actor\'s own');
    }

    #[Test]
    public function the_member_view_and_sync_say_what_is_linked()
    {
        [$status, $body] = $this->json('GET', '/api/armory/me', 3);
        $this->assertSame(200, $status);
        $this->assertTrue($body['connected']);
        $this->assertSame('Carol#1234', $body['battletag']);
        $this->assertCount(3, $body['characters'], 'The owner sees their hidden character too');

        [, $body] = $this->json('GET', '/api/armory/me', 2);
        $this->assertFalse($body['connected']);

        [, $body] = $this->json('POST', '/api/armory/sync', 2);
        $this->assertSame(['ok' => false, 'reason' => 'not_linked'], $body);
    }

    #[Test]
    public function the_open_lookup_is_rate_limited_per_visitor()
    {
        for ($n = 0; $n < 10; $n++) {
            [$status] = $this->json('GET', '/api/armory/search', null, ['region' => 'us', 'realm' => 'area-52', 'name' => 'x']);
            $this->assertSame(200, $status);
        }

        [$status, $body] = $this->json('GET', '/api/armory/search', null, ['region' => 'us', 'realm' => 'area-52', 'name' => 'x']);
        $this->assertSame(429, $status);
        $this->assertSame('rate_limited', $body['reason']);
    }

    #[Test]
    public function each_visitor_has_a_lookup_budget_of_their_own()
    {
        for ($n = 0; $n < 10; $n++) {
            $this->json('GET', '/api/armory/search', 2, ['region' => 'us', 'realm' => 'area-52', 'name' => 'x']);
        }

        [$status] = $this->json('GET', '/api/armory/search', null, ['region' => 'us', 'realm' => 'area-52', 'name' => 'x']);
        $this->assertSame(200, $status, 'A member\'s lookups do not spend a guest\'s budget');
        [$status] = $this->json('GET', '/api/armory/search', 2, ['region' => 'us', 'realm' => 'area-52', 'name' => 'x']);
        $this->assertSame(200, $status, 'A member is allowed more than a guest');
    }

    #[Test]
    public function the_mythic_plus_board_shows_forum_avatars_as_urls()
    {
        $cache = $this->app()->getContainer()->make(Store::class);
        foreach ([1 => 2900.5, 2 => 3100, 3 => 2500, 4 => 2100] as $id => $rating) {
            $cache->put("armory.mplus.char.$id", $rating, 600);
        }
        $this->app()->getContainer()->make(GuildLeaderboard::class)->buildMplusBoard();

        [, $body] = $this->json('GET', '/api/armory/guild/mplus');
        $this->assertTrue($body['ok']);
        $this->assertSame(['Frostbolt', 'Second', 'Normalmage'], array_column($body['board'], 'name'), 'Visible characters only, best rating first');
        $this->assertSame('carol', $body['board'][0]['username']);
        $this->assertStringEndsWith('/assets/avatars/carol.png', $body['board'][0]['avatarUrl']);
        $this->assertNull($body['board'][2]['avatarUrl']);
    }

    #[Test]
    public function every_user_carries_their_main_character_in_one_query()
    {
        $users = $chars = [];
        for ($id = 10; $id < 22; $id++) {
            $users[] = ['id' => $id, 'username' => "raider$id", 'email' => "raider$id@machine.local", 'is_email_confirmed' => 1];
            $chars[] = ['id' => $id, 'user_id' => $id, 'region' => 'us', 'realm_slug' => 'area-52', 'name' => "Raider$id", 'level' => 80, 'class' => 'Rogue', 'item_level' => 500, 'is_main' => true, 'is_visible' => true];
        }
        // Raider 21's only character is hidden.
        $chars[11]['is_visible'] = false;
        $this->prepareDatabase([User::class => $users, 'armory_characters' => $chars]);

        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();
        [$status, $body] = $this->json('GET', '/api/users', 1);
        $mainQueries = array_filter(array_column($db->getQueryLog(), 'query'), fn ($q) => str_contains($q, 'armory_characters'));

        $this->assertSame(200, $status);
        $mains = array_column(array_column($body['data'], 'attributes'), 'armoryMain', 'username');
        $this->assertSame('Frostbolt', $mains['carol']['name'], 'The flagged main, though a hidden alt has more item level');
        $this->assertSame('Raider15', $mains['raider15']['name']);
        $this->assertNull($mains['admin']);
        $this->assertNull($mains['raider21'], 'A hidden character is never shown as anyone\'s main');
        $this->assertCount(1, $mainQueries);
    }

    #[Test]
    public function item_tags_in_posts_become_item_links()
    {
        $this->prepareDatabase([
            \Flarum\Discussion\Discussion::class => [['id' => 1, 'title' => 'Loot', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1]],
            \Flarum\Post\Post::class => [['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>x</p></t>']],
        ]);

        [$status, $body] = $this->json('POST', '/api/posts', 1, [], ['data' => [
            'type' => 'posts',
            'attributes' => ['content' => 'Look: [item=19019]'],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]],
        ]]);

        $this->assertSame(201, $status);
        $this->assertStringContainsString('data-wow-item="19019"', $body['data']['attributes']['contentHtml']);
        $this->assertStringContainsString('href="https://www.wowhead.com/item=19019"', $body['data']['attributes']['contentHtml']);
    }
}
