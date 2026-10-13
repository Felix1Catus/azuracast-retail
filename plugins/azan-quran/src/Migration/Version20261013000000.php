<?php

declare(strict_types=1);

namespace Plugin\AzanQuran\Migration;

use App\Entity\Migration\AbstractMigration; // core base: not transactional (MySQL DDL auto-commits)
use Doctrine\DBAL\Schema\Schema;

final class Version20261013000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Azan & Quran: settings, per-station settings and cached prayer times';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE azan_quran_settings (
                setting_key VARCHAR(50) NOT NULL,
                setting_value LONGTEXT NOT NULL,
                PRIMARY KEY(setting_key)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB'
        );
        $this->addSql(
            'CREATE TABLE azan_quran_station (
                station_id INT NOT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 0,
                city VARCHAR(100) NOT NULL DEFAULT \'\',
                country VARCHAR(100) NOT NULL DEFAULT \'\',
                method SMALLINT NOT NULL DEFAULT 4,
                prayers VARCHAR(100) NOT NULL DEFAULT \'fajr,dhuhr,asr,maghrib,isha\',
                last_planned_at INT DEFAULT NULL,
                last_error LONGTEXT DEFAULT NULL,
                last_fade_date DATE DEFAULT NULL,
                updated_at INT NOT NULL,
                PRIMARY KEY(station_id),
                CONSTRAINT FK_AZAN_QURAN_STATION FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB'
        );
        $this->addSql(
            'CREATE TABLE azan_quran_times (
                station_id INT NOT NULL,
                day DATE NOT NULL,
                fajr CHAR(5) NOT NULL,
                dhuhr CHAR(5) NOT NULL,
                asr CHAR(5) NOT NULL,
                maghrib CHAR(5) NOT NULL,
                isha CHAR(5) NOT NULL,
                timezone VARCHAR(64) NOT NULL DEFAULT \'\',
                PRIMARY KEY(station_id, day),
                CONSTRAINT FK_AZAN_QURAN_TIMES FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE azan_quran_times');
        $this->addSql('DROP TABLE azan_quran_station');
        $this->addSql('DROP TABLE azan_quran_settings');
    }
}
