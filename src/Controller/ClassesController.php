<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\ClassIcons;
use ErnestDefoe\Armory\PlayableClasses;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/armory/classes → the full playable-class catalog with official
 * Blizzard icons: { data: [ { slug, name, icon } ] }. Public metadata (class
 * names/icons aren't secrets); icons are null until the API client is
 * configured. Powers the admin recruiting picker.
 */
class ClassesController implements RequestHandlerInterface
{
    public function __construct(protected ClassIcons $icons)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $icons = $this->icons->map();

        $data = [];
        foreach (PlayableClasses::ALL as $slug => [$id, $name]) {
            $data[] = [
                'slug' => $slug,
                'name' => $name,
                'icon' => $icons[$slug] ?? null,
            ];
        }

        return new JsonResponse(['data' => $data]);
    }
}
