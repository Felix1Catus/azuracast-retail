<?php

declare(strict_types=1);

use App\Controller;
use App\Enums\StationFeatures;
use App\Enums\StationPermissions;
use App\Middleware;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $app) {
    $app->group('/vue', function (RouteCollectorProxy $group) {
        $group->get('/files', Controller\Api\Stations\Vue\FilesAction::class)
            ->setName('api:vue:stations:files:index')
            ->add(new Middleware\StationSupportsFeature(StationFeatures::Media))
            ->add(new Middleware\Permissions(StationPermissions::Media, true));

        $group->get('/profile', Controller\Api\Stations\Vue\ProfileAction::class)
            ->setName('api:vue:stations:profile:index');

        $group->get('/profile/edit', Controller\Api\Stations\Vue\ProfileEditAction::class)
            ->setName('api:vue:stations:profile:edit')
            ->add(new Middleware\Permissions(StationPermissions::Profile, true));
    })->add(new Middleware\Permissions(StationPermissions::View, true));
};
