<?php

declare(strict_types=1);

namespace Plugin\AzanQuran\Migration;

use App\Entity\Migration\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261013010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Azan & Quran: remember the last cues sent per station (replaces last_fade_date)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE azan_quran_station ADD recent_cues LONGTEXT DEFAULT NULL, DROP last_fade_date');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE azan_quran_station ADD last_fade_date DATE DEFAULT NULL, DROP recent_cues');
    }
}
