<?php
declare(strict_types=1);

namespace Osoni;

use InvalidArgumentException;

final class Roles
{
    public function __construct(private readonly Database $db)
    {
    }

    public static function validate(string $role): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $role) !== 1) {
            throw new InvalidArgumentException('Role names must be 1-64 lowercase ASCII letters, digits, underscores, or hyphens, starting with a letter.');
        }
    }

    public function assign(int $userId, string $role): void
    {
        $this->validateUser($userId, $role);
        $this->db->insert('user_roles', ['user_id' => $userId, 'role_name' => $role], null);
    }

    public function revoke(int $userId, string $role): void
    {
        $this->validateUser($userId, $role);
        if ($this->db->execute('DELETE FROM user_roles WHERE user_id = ? AND role_name = ?', [$userId, $role])->rowCount() !== 1) {
            throw new RecordNotFoundException('User role does not exist.');
        }
    }

    public function has(int $userId, string $role): bool
    {
        $this->validateUser($userId, $role);
        return $this->db->execute('SELECT user_id FROM user_roles WHERE user_id = ? AND role_name = ?', [$userId, $role])->fetchColumn() !== false;
    }

    private function validateUser(int $userId, string $role): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('User ID must be positive.');
        }
        self::validate($role);
    }
}
