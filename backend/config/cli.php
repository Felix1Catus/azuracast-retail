<?php

declare(strict_types=1);

use App\Console\Command;

return function (App\Event\BuildConsoleCommands $event) {
    $event->addAliases([
        'acacia:acme:get-certificate' => Command\Acme\GetCertificateCommand::class,
        'acacia:acme:reload' => Command\Acme\ReloadCommand::class,
        'acacia:backup' => Command\Backup\BackupCommand::class,
        'acacia:restore' => Command\Backup\RestoreCommand::class,
        'acacia:debug:optimize-tables' => Command\Debug\OptimizeTablesCommand::class,
        'acacia:internal:on-ssl-renewal' => Command\Internal\OnSslRenewal::class,
        'acacia:internal:ip' => Command\Internal\GetIpCommand::class,
        'acacia:internal:uptime-wait' => Command\Internal\UptimeWaitCommand::class,
        'acacia:media:reprocess' => Command\Media\ReprocessCommand::class,
        'acacia:media:clear-extra' => Command\Media\ClearExtraMetadataCommand::class,
        'acacia:playlist:export' => Command\Playlists\ExportCommand::class,
        'acacia:playlist:import' => Command\Playlists\ImportCommand::class,
        'acacia:queue:process' => Command\MessageQueue\ProcessCommand::class,
        'acacia:queue:clear' => Command\MessageQueue\ClearCommand::class,
        'acacia:settings:list' => Command\Settings\ListCommand::class,
        'acacia:settings:set' => Command\Settings\SetCommand::class,
        'acacia:station-queues:clear' => Command\ClearQueuesCommand::class,
        'acacia:account:list' => Command\Users\ListCommand::class,
        'acacia:account:login-token' => Command\Users\LoginTokenCommand::class,
        'acacia:account:reset-password' => Command\Users\ResetPasswordCommand::class,
        'acacia:account:set-administrator' => Command\Users\SetAdministratorCommand::class,
        'acacia:cache:clear' => Command\ClearCacheCommand::class,
        'acacia:setup:migrate' => Command\MigrateDbCommand::class,
        'acacia:setup:fixtures' => Command\SetupFixturesCommand::class,
        'acacia:setup:rollback' => Command\RollbackDbCommand::class,
        'acacia:setup' => Command\SetupCommand::class,
        'acacia:radio:restart' => Command\RestartRadioCommand::class,
        'acacia:sync:nowplaying' => Command\Sync\NowPlayingCommand::class,
        'acacia:sync:nowplaying:station' => Command\Sync\NowPlayingPerStationCommand::class,
        'acacia:sync:run' => Command\Sync\RunnerCommand::class,
        'acacia:sync:task' => Command\Sync\SingleTaskCommand::class,
        'queue:process' => Command\MessageQueue\ProcessCommand::class,
        'queue:clear' => Command\MessageQueue\ClearCommand::class,
        'cache:clear' => Command\ClearCacheCommand::class,
        'acme:cert' => Command\Acme\GetCertificateCommand::class,
        'acme:reload' => Command\Acme\ReloadCommand::class,
    ]);

    if (!$event->getEnvironment()->isProduction()) {
        $event->addAliases([
            'acacia:api:docs' => Command\Dev\GenerateApiDocsCommand::class,
            'acacia:new-version' => Command\Dev\NewVersionCommand::class,
            'acacia:dev:generate-db-fixture' => Command\Dev\GenerateDbFixtureCommand::class,
            'acacia:dev:check-migrations' => Command\Dev\CheckMigrationsCommand::class,
            'acacia:locale:generate' => Command\Locale\GenerateCommand::class,
            'acacia:locale:import' => Command\Locale\ImportCommand::class,
            'locale:generate' => Command\Locale\GenerateCommand::class,
            'locale:import' => Command\Locale\ImportCommand::class,
        ]);
    }
};
