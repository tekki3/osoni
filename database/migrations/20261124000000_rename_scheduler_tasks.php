<?php
declare(strict_types=1);

use Osoni\Migration;

return new class extends Migration {
    public function up(PDO $db): void
    {
        $this->rename($db, 'pbreeze_scheduler_tasks', 'osoni_scheduler_tasks');
    }

    public function down(PDO $db): void
    {
        $this->rename($db, 'osoni_scheduler_tasks', 'pbreeze_scheduler_tasks');
    }

    private function rename(PDO $db, string $from, string $to): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlsrv') {
            $db->exec("EXEC sp_rename '$from', '$to'");
            return;
        }
        $db->exec("ALTER TABLE $from RENAME TO $to");
    }
};
