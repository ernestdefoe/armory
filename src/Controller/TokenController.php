<?php

namespace ErnestDefoe\Armory\Controller;

use Carbon\Carbon;
use ErnestDefoe\Armory\BlizzardApi;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/armory/token — current WoW Token price plus a rolling history
 * for the sparkline. Sampling is lazy: a fresh price is appended whenever
 * this endpoint is hit and the newest sample is older than six hours, so
 * the history builds itself from normal widget traffic (max 60 samples,
 * ~2 weeks at the 6h cadence).
 */
class TokenController implements RequestHandlerInterface
{
    protected const SAMPLE_HOURS = 6;
    protected const MAX_SAMPLES = 60;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected BlizzardApi $api,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $history = json_decode((string) $this->settings->get('armory.token_history'), true);
        $history = is_array($history) ? array_values(array_filter($history, 'is_array')) : [];

        $last = end($history) ?: null;
        $stale = ! $last || (time() - (int) $last[0]) > self::SAMPLE_HOURS * 3600;

        if ($stale) {
            $copper = $this->api->tokenPrice($this->api->region());
            if ($copper) {
                $history[] = [time(), intdiv((int) $copper, 10000)];
                $history = array_slice($history, -self::MAX_SAMPLES);
                $this->settings->set('armory.token_history', json_encode($history));
            }
        }

        if (! $history) {
            return new JsonResponse(['ok' => false]);
        }

        $latest = (int) end($history)[1];
        $previous = count($history) > 1 ? (int) $history[count($history) - 2][1] : $latest;

        return new JsonResponse([
            'ok' => true,
            'price' => $latest,
            'delta' => $latest - $previous,
            'updated' => Carbon::createFromTimestamp((int) end($history)[0], 'UTC')->toIso8601String(),
            'history' => $history,
        ]);
    }
}
