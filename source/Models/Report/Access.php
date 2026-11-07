<?php

namespace Source\Models\Report;

use Source\Core\Model;
use Source\Core\Session;

/**
 * ERP | Class Access
 *
 * @author Djalma Martins
 * @package Source\Models\Report
 */
class Access extends Model
{
    /**
     * Access constructor.
     */
    public function __construct()
    {
        parent::__construct("report_access", ["id"], ["users", "views", "pages"]);
    }

    /**
     * @return Access
     */
    public function report(): Access
    {
        $find = $this->find("DATE(created_at) = DATE(now())")->fetch();
        $session = new Session();

        if (!$find) {
            $this->users = 1;
            $this->views = 1;
            $this->pages = 1;

            $this->accessCookie();
            $session->set("access", true);

            $this->save();
            return $this;
        }

        if (!filter_input(INPUT_COOKIE, "access")) {
            $find->users += 1;
            $this->accessCookie();
        }

        if (!$session->has("access")) {
            $find->views += 1;
            $session->set("access", true);
        }

        $find->pages += 1;
        $find->save();
        return $this;
    }

    private function accessCookie(): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        setcookie('access', '1', ['expires' => time() + 86400, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    }
}
