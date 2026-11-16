<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Support\Db;

final class StaffRepository
{
    public function __construct(private Db $db)
    {
    }

    public function find(int $userId): StaffMember
    {
        $rows = $this->db->all('SELECT sector_id, role FROM talk_staff WHERE user_id = ?', [$userId]);
        $sup = false;
        $agent = $manager = [];
        foreach ($rows as $r) {
            if ($r['role'] === 'supervisor') {
                $sup = true;
            } elseif ($r['role'] === 'manager' && $r['sector_id'] !== null) {
                $manager[] = (int) $r['sector_id'];
            } elseif ($r['sector_id'] !== null) {
                $agent[] = (int) $r['sector_id'];
            }
        }
        return new StaffMember($userId, $sup, $agent, $manager);
    }
}
