<?php

declare(strict_types=1);

use App\Controller;
use App\Enums\StationFeatures;
use App\Enums\StationPermissions;
use App\Middleware;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $group) {
    $group->group(
        '/nowplaying/{station_id}',
        function (RouteCollectorProxy $group) {
            $group->get(
                '',
                Controller\Api\NowPlayingAction::class
            )->setName('api:nowplaying:station')
                ->add(new Middleware\Cache\SetCache(15));

            $group->get(
                '/art[/{timestamp}.jpg]',
                Controller\Api\NowPlayingArtAction::class
            )->setName('api:nowplaying:art')
                ->add(new Middleware\Cache\SetCache(15));
        }
    )->add(Middleware\StationPublicApi::class)
        ->add(Middleware\RequireStation::class)
        ->add(Middleware\GetStation::class);

    $group->group(
        '/station/{station_id}',
        function (RouteCollectorProxy $group) {
            /*
             * Anonymous Functions (hidden if station isn't public)
             */

            $group->group(
                '',
                function (RouteCollectorProxy $group) {
                    $group->get('', Controller\Api\Stations\IndexController::class . ':indexAction')
                        ->setName('api:stations:index')
                        ->add(new Middleware\RateLimit('api', 5, 2));

                    $group->get('/nowplaying', Controller\Api\NowPlayingAction::class);

                    $group->get('/schedule', Controller\Api\Stations\ScheduleAction::class)
                        ->setName('api:stations:schedule');

                    // Media Art
                    $group->get(
                        '/art/{media_id:[a-zA-Z0-9\-]+}[-{timestamp}.jpg]',
                        Controller\Api\Stations\Art\GetArtAction::class
                    )->setName('api:stations:media:art')
                        ->add(new Middleware\Cache\SetStaticFileCache());
                }
            )->add(Middleware\StationPublicApi::class);

            /*
             * Authenticated Functions
             */
            $group->group(
                '',
                function (RouteCollectorProxy $group) {
                    $group->get('/dashboard', Controller\Api\Stations\GetDashboardAction::class)
                        ->add(new Middleware\Permissions(StationPermissions::View, true));

                    $group->map(
                        ['GET', 'POST'],
                        '/nowplaying/update',
                        Controller\Api\Stations\UpdateMetadataAction::class
                    )->add(new Middleware\Permissions(StationPermissions::Broadcasting, true));

                    $group->get('/profile', Controller\Api\Stations\ProfileAction::class)
                        ->setName('api:stations:profile')
                        ->add(new Middleware\Permissions(StationPermissions::View, true));

                    $group->group(
                        '',
                        function (RouteCollectorProxy $group) {
                            $group->get(
                                '/profile/edit',
                                Controller\Api\Stations\ProfileEditController::class . ':getProfileAction'
                            )->setName('api:stations:profile:edit');

                            $group->put(
                                '/profile/edit',
                                Controller\Api\Stations\ProfileEditController::class . ':putProfileAction'
                            );

                            $group->get(
                                '/custom_assets/{type}',
                                Controller\Api\Stations\CustomAssets\GetCustomAssetAction::class
                            )->setName('api:stations:custom_assets');

                            $group->post(
                                '/custom_assets/{type}',
                                Controller\Api\Stations\CustomAssets\PostCustomAssetAction::class
                            );
                            $group->delete(
                                '/custom_assets/{type}',
                                Controller\Api\Stations\CustomAssets\DeleteCustomAssetAction::class
                            );
                        }
                    )->add(new Middleware\Permissions(StationPermissions::Profile, true));

                    // Upcoming Song Queue
                    $group->group(
                        '/queue',
                        function (RouteCollectorProxy $group) {
                            $group->get('', Controller\Api\Stations\QueueController::class . ':listAction')
                                ->setName('api:stations:queue');

                            $group->post('/clear', Controller\Api\Stations\QueueController::class . ':clearAction')
                                ->setName('api:stations:queue:clear');

                            $group->delete('/{id}', Controller\Api\Stations\QueueController::class . ':deleteAction')
                                ->setName('api:stations:queue:record');
                        }
                    )->add(new Middleware\Permissions(StationPermissions::Broadcasting, true));

                    // Files/Media
                    $group->get('/quota[/{type}]', Controller\Api\Stations\GetQuotaAction::class)
                        ->setName('api:stations:quota')
                        ->add(new Middleware\Permissions(StationPermissions::View, true));

                    $group->get(
                        '/waveform/{media_id:[a-zA-Z0-9\-]+}[-{timestamp}.json]',
                        Controller\Api\Stations\Waveform\GetWaveformAction::class
                    )->setName('api:stations:media:waveform')
                        ->add(new Middleware\Cache\SetStaticFileCache());

                    $group->post(
                        '/waveform/{media_id:[a-zA-Z0-9\-]+}',
                        Controller\Api\Stations\Waveform\PostCacheWaveformAction::class
                    )->setName('api:stations:media:waveform-cache');

                    $group->post('/art/{media_id:[a-zA-Z0-9]+}', Controller\Api\Stations\Art\PostArtAction::class)
                        ->add(new Middleware\Permissions(StationPermissions::Media, true));

                    $group->delete('/art/{media_id:[a-zA-Z0-9]+}', Controller\Api\Stations\Art\DeleteArtAction::class)
                        ->add(new Middleware\Permissions(StationPermissions::Media, true));

                    $group->group(
                        '',
                        function (RouteCollectorProxy $group) {
                            $group->group(
                                '/files',
                                function (RouteCollectorProxy $group) {
                                    $group->get(
                                        '',
                                        Controller\Api\Stations\FilesController::class . ':listAction'
                                    )->setName('api:stations:files');

                                    $group->post(
                                        '',
                                        Controller\Api\Stations\FilesController::class . ':createAction'
                                    );

                                    $group->get('/list', Controller\Api\Stations\Files\ListAction::class)
                                        ->setName('api:stations:files:list');

                                    $group->get(
                                        '/directories',
                                        Controller\Api\Stations\Files\ListDirectoriesAction::class
                                    )
                                        ->setName('api:stations:files:directories');

                                    $group->put('/rename', Controller\Api\Stations\Files\RenameAction::class)
                                        ->setName('api:stations:files:rename');

                                    $group->put('/batch', Controller\Api\Stations\Files\BatchAction::class)
                                        ->setName('api:stations:files:batch');

                                    $group->post('/mkdir', Controller\Api\Stations\Files\MakeDirectoryAction::class)
                                        ->setName('api:stations:files:mkdir');

                                    $group->get('/bulk', Controller\Api\Stations\BulkMedia\DownloadAction::class)
                                        ->setName('api:stations:files:bulk');

                                    $group->post(
                                        '/bulk[/{preview:preview}]',
                                        Controller\Api\Stations\BulkMedia\UploadAction::class
                                    );

                                    $group->get('/download', Controller\Api\Stations\Files\DownloadAction::class)
                                        ->setName('api:stations:files:download');

                                    $group->map(
                                        ['GET', 'POST'],
                                        '/upload',
                                        Controller\Api\Stations\Files\FlowUploadAction::class
                                    )->setName('api:stations:files:upload');
                                }
                            )->add(new Middleware\Permissions(StationPermissions::Media, true));

                            $group->group(
                                '/file/{id}',
                                function (RouteCollectorProxy $group) {
                                    $group->get(
                                        '',
                                        Controller\Api\Stations\FilesController::class . ':getAction'
                                    )->setName('api:stations:file')
                                        ->add(new Middleware\Permissions(StationPermissions::Media, true));

                                    $group->put(
                                        '',
                                        Controller\Api\Stations\FilesController::class . ':editAction'
                                    )->add(new Middleware\Permissions(StationPermissions::Media, true));

                                    $group->delete(
                                        '',
                                        Controller\Api\Stations\FilesController::class . ':deleteAction'
                                    )->add(new Middleware\Permissions(StationPermissions::DeleteMedia, true));

                                    $group->get('/play', Controller\Api\Stations\Files\PlayAction::class)
                                        ->setName('api:stations:files:play')
                                        ->add(new Middleware\Permissions(StationPermissions::Media, true));
                                }
                            );
                        }
                    )->add(new Middleware\StationSupportsFeature(StationFeatures::Media));

                    // Mount Points
                    $group->group(
                        '',
                        function (RouteCollectorProxy $group) {
                            $group->group(
                                '/mounts',
                                function (RouteCollectorProxy $group) {
                                    $group->get(
                                        '',
                                        Controller\Api\Stations\MountsController::class . ':listAction'
                                    )->setName('api:stations:mounts');

                                    $group->post(
                                        '',
                                        Controller\Api\Stations\MountsController::class . ':createAction'
                                    );

                                    $group->post(
                                        '/intro',
                                        Controller\Api\Stations\Mounts\Intro\PostIntroAction::class
                                    )->setName('api:stations:mounts:new-intro');
                                }
                            );

                            $group->group(
                                '/mount/{id}',
                                function (RouteCollectorProxy $group) {
                                    $group->get(
                                        '',
                                        Controller\Api\Stations\MountsController::class . ':getAction'
                                    )->setName('api:stations:mount');

                                    $group->put(
                                        '',
                                        Controller\Api\Stations\MountsController::class . ':editAction'
                                    );

                                    $group->delete(
                                        '',
                                        Controller\Api\Stations\MountsController::class . ':deleteAction'
                                    );

                                    $group->get(
                                        '/intro',
                                        Controller\Api\Stations\Mounts\Intro\GetIntroAction::class
                                    )->setName('api:stations:mounts:intro');

                                    $group->post(
                                        '/intro',
                                        Controller\Api\Stations\Mounts\Intro\PostIntroAction::class
                                    );

                                    $group->delete(
                                        '/intro',
                                        Controller\Api\Stations\Mounts\Intro\DeleteIntroAction::class
                                    );
                                }
                            );
                        }
                    )->add(new Middleware\StationSupportsFeature(StationFeatures::MountPoints))
                        ->add(new Middleware\Permissions(StationPermissions::MountPoints, true));

                    // Playlist
                    $group->group(
                        '',
                        function (RouteCollectorProxy $group) {
                            $group->get(
                                '/playlists',
                                Controller\Api\Stations\PlaylistsController::class . ':listAction'
                            )->setName('api:stations:playlists');

                            $group->post(
                                '/playlists',
                                Controller\Api\Stations\PlaylistsController::class . ':createAction'
                            );

                            $group->get(
                                '/playlists/schedule',
                                Controller\Api\Stations\PlaylistsController::class . ':scheduleAction'
                            )->setName('api:stations:playlists:schedule');

                            $group->get(
                                '/playlists/export-config',
                                Controller\Api\Stations\Playlists\ExportConfigAction::class
                            )->setName('api:stations:playlists:export_config');

                            $group->post(
                                '/playlists/import-config',
                                Controller\Api\Stations\Playlists\ImportConfigAction::class
                            )->setName('api:stations:playlists:import_config');

                            $group->group(
                                '/playlist/{id}',
                                function (RouteCollectorProxy $group) {
                                    $group->get(
                                        '',
                                        Controller\Api\Stations\PlaylistsController::class . ':getAction'
                                    )->setName('api:stations:playlist');

                                    $group->put(
                                        '',
                                        Controller\Api\Stations\PlaylistsController::class . ':editAction'
                                    );

                                    $group->delete(
                                        '',
                                        Controller\Api\Stations\PlaylistsController::class . ':deleteAction'
                                    );

                                    $group->put(
                                        '/toggle',
                                        Controller\Api\Stations\Playlists\ToggleAction::class
                                    )->setName('api:stations:playlist:toggle');

                                    $group->put(
                                        '/reshuffle',
                                        Controller\Api\Stations\Playlists\ReshuffleAction::class
                                    )->setName('api:stations:playlist:reshuffle');

                                    $group->get(
                                        '/order',
                                        Controller\Api\Stations\Playlists\GetOrderAction::class
                                    )->setName('api:stations:playlist:order');

                                    $group->put(
                                        '/order',
                                        Controller\Api\Stations\Playlists\PutOrderAction::class
                                    );

                                    $group->get(
                                        '/queue',
                                        Controller\Api\Stations\Playlists\GetQueueAction::class
                                    )->setName('api:stations:playlist:queue');

                                    $group->delete(
                                        '/queue',
                                        Controller\Api\Stations\Playlists\DeleteQueueAction::class
                                    );

                                    $group->post(
                                        '/clone',
                                        Controller\Api\Stations\Playlists\CloneAction::class
                                    )->setName('api:stations:playlist:clone');

                                    $group->post(
                                        '/import',
                                        Controller\Api\Stations\Playlists\ImportAction::class
                                    )->setName('api:stations:playlist:import');

                                    $group->get(
                                        '/export[/{format}]',
                                        Controller\Api\Stations\Playlists\ExportAction::class
                                    )->setName('api:stations:playlist:export');

                                    $group->get(
                                        '/export-config',
                                        Controller\Api\Stations\Playlists\ExportConfigAction::class
                                    )->setName('api:stations:playlist:export_config');

                                    $group->get(
                                        '/apply-to',
                                        Controller\Api\Stations\Playlists\GetApplyToAction::class
                                    )->setName('api:stations:playlist:applyto');

                                    $group->put(
                                        '/apply-to',
                                        Controller\Api\Stations\Playlists\PutApplyToAction::class
                                    );

                                    $group->delete(
                                        '/empty',
                                        Controller\Api\Stations\Playlists\EmptyAction::class
                                    )->setName('api:stations:playlist:empty');

                                    $group->put(
                                        '/members',
                                        Controller\Api\Stations\Playlists\PutMembersAction::class
                                    )->setName('api:stations:playlist:members');
                                }
                            );
                        }
                    )->add(new Middleware\StationSupportsFeature(StationFeatures::Media))
                        ->add(new Middleware\Permissions(StationPermissions::Media, true));

                    // Reports
                    $group->get('/history', Controller\Api\Stations\HistoryAction::class)
                        ->setName('api:stations:history')
                        ->add(new Middleware\Permissions(StationPermissions::Reports, true));

                    $group->get('/listeners', Controller\Api\Stations\ListenersAction::class)
                        ->setName('api:listeners:index')
                        ->add(new Middleware\Permissions(StationPermissions::Reports, true));

                    $group->group(
                        '/reports',
                        function (RouteCollectorProxy $group) {
                            $group->get(
                                '/overview/charts',
                                Controller\Api\Stations\Reports\Overview\ChartsAction::class
                            )->setName('api:stations:reports:overview-charts');

                            $group->get(
                                '/overview/best-and-worst',
                                Controller\Api\Stations\Reports\Overview\BestAndWorstAction::class
                            )->setName('api:stations:reports:best-and-worst');

                            $group->get(
                                '/overview/by-browser',
                                Controller\Api\Stations\Reports\Overview\ByBrowser::class
                            )->setName('api:stations:reports:by-browser');

                            $group->get(
                                '/overview/by-country',
                                Controller\Api\Stations\Reports\Overview\ByCountry::class
                            )->setName('api:stations:reports:by-country');

                            $group->get(
                                '/overview/by-stream',
                                Controller\Api\Stations\Reports\Overview\ByStream::class
                            )->setName('api:stations:reports:by-stream');

                            $group->get(
                                '/overview/by-client',
                                Controller\Api\Stations\Reports\Overview\ByClient::class
                            )->setName('api:stations:reports:by-client');

                            $group->get(
                                '/overview/by-listening-time',
                                Controller\Api\Stations\Reports\Overview\ByListeningTime::class
                            )->setName('api:stations:reports:by-listening-time');
                        }
                    )->add(new Middleware\Permissions(StationPermissions::Reports, true));

                    $group->get('/status', Controller\Api\Stations\ServicesController::class . ':statusAction')
                        ->setName('api:stations:status')
                        ->add(new Middleware\Permissions(StationPermissions::View, true));

                    $group->post('/backend/{do}', Controller\Api\Stations\ServicesController::class . ':backendAction')
                        ->setName('api:stations:backend')
                        ->add(new Middleware\Permissions(StationPermissions::Broadcasting, true));

                    $group->post(
                        '/frontend/{do}',
                        Controller\Api\Stations\ServicesController::class . ':frontendAction'
                    )->setName('api:stations:frontend')
                        ->add(new Middleware\Permissions(StationPermissions::Broadcasting, true));

                    $group->post('/reload', Controller\Api\Stations\ServicesController::class . ':reloadAction')
                        ->setName('api:stations:reload')
                        ->add(new Middleware\Permissions(StationPermissions::Broadcasting, true));

                    $group->post('/restart', Controller\Api\Stations\ServicesController::class . ':restartAction')
                        ->setName('api:stations:restart')
                        ->add(new Middleware\Permissions(StationPermissions::Broadcasting, true));

                    // Custom Fallback File
                    $group->group(
                        '/fallback',
                        function (RouteCollectorProxy $group) {
                            $group->get(
                                '[/{do}]',
                                Controller\Api\Stations\Fallback\GetFallbackAction::class
                            )->setName('api:stations:fallback');

                            $group->post(
                                '',
                                Controller\Api\Stations\Fallback\PostFallbackAction::class
                            );

                            $group->delete(
                                '',
                                Controller\Api\Stations\Fallback\DeleteFallbackAction::class
                            );
                        }
                    )->add(new Middleware\Permissions(StationPermissions::Broadcasting, true));

                    // Custom Liquidsoap Configuration
                    $group->group(
                        '/liquidsoap-config',
                        function (RouteCollectorProxy $group) {
                            $group->get(
                                '',
                                Controller\Api\Stations\LiquidsoapConfig\GetAction::class
                            )->setName('api:stations:liquidsoap-config');

                            $group->put(
                                '',
                                Controller\Api\Stations\LiquidsoapConfig\PutAction::class
                            );

                            $group->get(
                                '/export',
                                Controller\Api\Stations\LiquidsoapConfig\ExportAction::class
                            )->setName('api:stations:liquidsoap-config:export');

                            $group->post(
                                '/import',
                                Controller\Api\Stations\LiquidsoapConfig\ImportAction::class
                            )->setName('api:stations:liquidsoap-config:import');
                        }
                    )->add(new Middleware\Permissions(StationPermissions::Broadcasting, true));

                    // Logs
                    $group->group(
                        '',
                        function (RouteCollectorProxy $group) {
                            $group->get('/logs', Controller\Api\Stations\LogsAction::class)
                                ->setName('api:stations:logs');

                            $group->get('/log/{log}', Controller\Api\Stations\LogsAction::class)
                                ->setName('api:stations:log');
                        }
                    )->add(new Middleware\Permissions(StationPermissions::Logs, true));

                    // Vue Properties
                    call_user_func(include(__DIR__ . '/api_station_vue.php'), $group);
                }
            )->add(Middleware\RequireLogin::class);
        }
    )->add(Middleware\RequireStation::class)
        ->add(Middleware\GetStation::class);
};
