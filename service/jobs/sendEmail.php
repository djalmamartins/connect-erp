<?php

require dirname(__DIR__, 2) . "/vendor/autoload.php";

/**
 * SEND QUEUE
 */
$emailQueue = new \Source\Support\Email();
$emailQueue->sendQueue();
