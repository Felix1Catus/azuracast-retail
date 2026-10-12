<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

/**
 * Acacia Radio rebrand, phase 2: the container's data root moved from the original project's folder to
 * /var/acacia, and the Liquidsoap function namespace was renamed. Stored paths and custom Liquidsoap code follow.
 * The image also links the old folder to the new one (util/docker/common/scripts/my_init), so anything not
 * covered here still resolves.
 */
final class Version20261012120000 extends AbstractMigration
{
    private const string OLD_ROOT = '/var/azuracast/'; // rebrand:keep
    private const string NEW_ROOT = '/var/acacia/';

    // Custom Liquidsoap code (station.backend_config) used the old namespace: settings.<old>.x, <old>.fn(), <old>_x.
    private const array LIQUIDSOAP_RENAMES = [
        'settings.azuracast.' => 'settings.acacia.', // rebrand:keep
        'azuracast.' => 'acacia.', // rebrand:keep
        '"azuracast_' => '"acacia_', // rebrand:keep
    ];

    public function getDescription(): string
    {
        return 'Rebrand: move stored paths to /var/acacia and rename Liquidsoap identifiers in custom code.';
    }

    public function up(Schema $schema): void
    {
        $this->movePaths(self::OLD_ROOT, self::NEW_ROOT);
        $this->renameLiquidsoap(self::LIQUIDSOAP_RENAMES);
    }

    public function down(Schema $schema): void
    {
        $this->movePaths(self::NEW_ROOT, self::OLD_ROOT);
        $this->renameLiquidsoap(array_flip(self::LIQUIDSOAP_RENAMES));
    }

    private function movePaths(string $from, string $to): void
    {
        foreach (['station' => 'radio_base_dir', 'storage_location' => 'path'] as $table => $column) {
            $this->addSql(
                sprintf(
                    'UPDATE %1$s SET %2$s = CONCAT(:to, SUBSTRING(%2$s, :len)) WHERE %2$s LIKE :prefix',
                    $table,
                    $column
                ),
                ['to' => $to, 'len' => strlen($from) + 1, 'prefix' => $from . '%']
            );
        }
    }

    /** @param array<string, string> $renames */
    private function renameLiquidsoap(array $renames): void
    {
        foreach ($renames as $old => $new) {
            $this->addSql(
                'UPDATE station SET backend_config = REPLACE(backend_config, :old, :new) WHERE backend_config LIKE :match',
                ['old' => $old, 'new' => $new, 'match' => '%' . addcslashes($old, '%_\\') . '%']
            );
        }
    }
}
