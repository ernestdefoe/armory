<?php

namespace ErnestDefoe\Armory\Tests\integration\api;

use ErnestDefoe\Armory\BlizzardApi;
use ErnestDefoe\Armory\Tests\integration\FakeBattlenet;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Battle.net sign-in: the redirect, the callback's state check, and the sign-up gate. */
class BattlenetTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-armory');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            'armory_battlenet_accounts' => [
                ['id' => 1, 'user_id' => 2, 'bnet_id' => '1001', 'region' => 'us', 'main_confirmed' => false],
            ],
            'armory_characters' => [
                ['id' => 1, 'user_id' => 2, 'region' => 'us', 'realm_slug' => 'area-52', 'name' => 'Frostbolt', 'is_visible' => true],
            ],
        ]);
    }

    private function configure(): void
    {
        $this->setting('armory.client_id', 'test-client');
        $this->setting('armory.client_secret', 'test-secret');
    }

    /** A guest's write needs a session and its CSRF token, as a browser has. */
    private function withGuestSession(ServerRequestInterface $request): ServerRequestInterface
    {
        $initial = $this->send($this->request('GET', '/api'));

        return $this->requestWithCookiesFrom($request->withHeader('X-CSRF-Token', $initial->getHeaderLine('X-CSRF-Token')), $initial);
    }

    private function forum(?int $actor = null): array
    {
        $response = $this->send($this->request('GET', '/api', $actor ? ['authenticatedAs' => $actor] : []));

        return json_decode((string) $response->getBody(), true)['data']['attributes'];
    }

    #[Test]
    public function sign_in_goes_nowhere_until_battlenet_is_configured()
    {
        $response = $this->send($this->request('GET', '/auth/battlenet'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaderLine('Location'));
        $this->assertFalse($this->forum()['armory.configured']);
    }

    #[Test]
    public function sign_in_sends_the_browser_to_battlenet_with_a_state_bound_to_it()
    {
        $this->configure();

        $response = $this->send($this->request('GET', '/auth/battlenet'));
        $location = $response->getHeaderLine('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith('https://oauth.battle.net/authorize?', $location);
        $this->assertSame('test-client', $query['client_id']);
        $this->assertSame('openid wow.profile', $query['scope']);
        $this->assertNotEmpty($query['state']);
        $this->assertTrue($this->forum()['armory.configured']);
        $this->assertArrayNotHasKey('armory.client_secret', $this->forum(), 'The secret never reaches the browser');
    }

    #[Test]
    public function a_callback_is_honoured_only_in_the_browser_that_started_it()
    {
        $this->configure();
        $container = $this->app()->getContainer();
        $battlenet = $container->make(FakeBattlenet::class);
        $container->instance(BlizzardApi::class, $battlenet);

        $start = $this->send($this->request('GET', '/auth/battlenet'));
        parse_str((string) parse_url($start->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        $callback = fn (ResponseInterface $session) => $this->send(
            $this->requestWithCookiesFrom($this->request('GET', '/auth/battlenet/callback'), $session)
                ->withQueryParams(['code' => 'the-code', 'state' => $query['state']])
        );

        // Another browser presenting this one's state: refused before any
        // code is exchanged.
        $response = $callback($this->send($this->request('GET', '/')));
        $this->assertSame('/', $response->getHeaderLine('Location'));
        $this->assertSame([], $battlenet->exchanged);

        // The browser that started it.
        $callback($start);
        $this->assertSame(['the-code'], $battlenet->exchanged);
    }

    #[Test]
    public function battlenet_only_registration_is_enforced_on_the_server()
    {
        $signUp = fn () => $this->send($this->withGuestSession($this->request('POST', '/api/users', ['json' => ['data' => ['attributes' => [
            'username' => 'newcomer', 'email' => 'newcomer@machine.local', 'password' => 'too-obscure',
        ]]]])))->getStatusCode();

        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set('armory.bnet_only', '1');
        $this->assertSame(422, $signUp());
        $this->assertTrue($this->forum()['armory.bnetOnly']);

        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set('armory.bnet_only', '0');
        $this->assertSame(201, $signUp());
    }

    #[Test]
    public function a_member_with_characters_and_no_chosen_main_is_nudged()
    {
        $this->assertTrue($this->forum(2)['armoryNeedsMain']);
        $this->assertFalse($this->forum()['armoryNeedsMain']);

        $this->database()->table('armory_battlenet_accounts')->where('user_id', 2)->update(['main_confirmed' => true]);
        $this->assertFalse($this->forum(2)['armoryNeedsMain']);
    }
}
