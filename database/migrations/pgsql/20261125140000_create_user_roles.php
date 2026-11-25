<?php
declare(strict_types=1);

use Osoni\Migration;

return new class extends Migration {
    public function up(PDO $db): void
    {
        $db->exec('CREATE TABLE user_roles (
            user_id BIGINT NOT NULL,
            role_name VARCHAR(64) COLLATE "C" NOT NULL,
            PRIMARY KEY (user_id, role_name),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE user_roles');
    }
};
