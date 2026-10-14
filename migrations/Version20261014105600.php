<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 */
final class Version20261014105600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MariaDB/MySQL baseline.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_88BDF3E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE chapter (id INT AUTO_INCREMENT NOT NULL, published_at DATETIME DEFAULT NULL, summary LONGTEXT DEFAULT NULL, number NUMERIC(6, 1) NOT NULL, direction VARCHAR(16) NOT NULL, title VARCHAR(255) DEFAULT NULL, published TINYINT DEFAULT 0 NOT NULL, work_id INT NOT NULL, UNIQUE INDEX chapter_work_number (work_id, number), INDEX IDX_F981B52EBB3453DB (work_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE page (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, original_key VARCHAR(255) NOT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, status VARCHAR(16) NOT NULL, tile_order LONGTEXT DEFAULT NULL, chapter_id INT NOT NULL, UNIQUE INDEX page_chapter_position (chapter_id, position), INDEX IDX_140AB620579F4768 (chapter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE site_settings (id INT NOT NULL, bio LONGTEXT DEFAULT NULL, links LONGTEXT DEFAULT NULL, accent VARCHAR(7) NOT NULL, logo_version INT DEFAULT NULL, name VARCHAR(100) NOT NULL, tagline VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE work (id INT AUTO_INCREMENT NOT NULL, cover_version INT DEFAULT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, type VARCHAR(16) NOT NULL, description LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_534E6880989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE chapter ADD CONSTRAINT FK_F981B52EBB3453DB FOREIGN KEY (work_id) REFERENCES work (id)');
        $this->addSql('ALTER TABLE page ADD CONSTRAINT FK_140AB620579F4768 FOREIGN KEY (chapter_id) REFERENCES chapter (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chapter DROP FOREIGN KEY FK_F981B52EBB3453DB');
        $this->addSql('ALTER TABLE page DROP FOREIGN KEY FK_140AB620579F4768');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE chapter');
        $this->addSql('DROP TABLE page');
        $this->addSql('DROP TABLE site_settings');
        $this->addSql('DROP TABLE work');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
