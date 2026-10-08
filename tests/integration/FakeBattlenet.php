<?php

namespace ErnestDefoe\Armory\Tests\integration;

use ErnestDefoe\Armory\BlizzardApi;

/** Battle.net's token endpoint replaced by a recorder, so no test reaches it. */
class FakeBattlenet extends BlizzardApi
{
    /** @var list<string> codes the callback tried to exchange */
    public array $exchanged = [];

    public function exchangeCode(string $code, string $redirectUri): ?array
    {
        $this->exchanged[] = $code;

        return null;
    }
}
