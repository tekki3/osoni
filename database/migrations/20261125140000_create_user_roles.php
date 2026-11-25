<?php
declare(strict_types=1);

use Osoni\Migration;

return new class extends Migration {
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $foreign = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $db->exec("CREATE TABLE user_roles (
            user_id $foreign NOT NULL,
            role_name VARCHAR(64) NOT NULL,
            PRIMARY KEY (user_id, role_name),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )$suffix");
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE user_roles');
    }
};
