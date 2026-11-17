<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use PDO;
use Source\Services\LoginThrottle;

/**
 * Trava da conta durante a tentativa de login (ADR 0348): enquanto uma conexão confere a senha, outra que tenta a mesma
 * conta espera, e o limite por conta não estoura com pedidos simultâneos. Contas diferentes não esperam umas pelas outras.
 */
final class LoginThrottleLockIntegrationTest extends TestCase
{
    public function testSameAccountWaitsAndOtherAccountsDoNot(): void
    {
        $other = new PDO('mysql:host=' . CONF_DB_HOST . ';port=' . CONF_DB_PORT . ';dbname=' . CONF_DB_NAME, CONF_DB_USER, CONF_DB_PASS);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $mine = new LoginThrottle($this->pdo);
        $theirs = new LoginThrottle($other);
        $ana = LoginThrottle::subjects('ana@connect.lab', '203.0.113.7');
        $bia = LoginThrottle::subjects('bia@connect.lab', '203.0.113.7');

        $release = $mine->hold($ana);
        $started = microtime(true);
        $waited = $theirs->hold($ana, 1);
        self::assertGreaterThanOrEqual(0.9, microtime(true) - $started, 'A mesma conta precisa esperar a trava.');
        $waited();

        $started = microtime(true);
        $free = $theirs->hold($bia, 1);
        self::assertLessThan(0.5, microtime(true) - $started, 'Outra conta (mesmo IP) não espera.');
        $free();

        $release();
        $release(); // soltar duas vezes não quebra
        $started = microtime(true);
        $again = $theirs->hold($ana, 1);
        self::assertLessThan(0.5, microtime(true) - $started, 'Depois de soltar, a conta fica livre.');
        $again();
    }

    public function testWithoutAccountOrMariaDbItIsANoOp(): void
    {
        $started = microtime(true);
        (new LoginThrottle($this->pdo))->hold(LoginThrottle::subjects(null, '203.0.113.7'))();
        (new LoginThrottle(new PDO('sqlite::memory:')))->hold(LoginThrottle::subjects('ana@connect.lab', null))();
        self::assertLessThan(0.5, microtime(true) - $started);
    }
}
