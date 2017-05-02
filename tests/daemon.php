<?php
/**
 * Created by IntelliJ IDEA.
 * User: rek
 * Date: 2017/5/1
 * Time: PM7:14
 */

namespace x2ts\parallel;

use x2ts\ComponentFactory as X;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/** @var Runner $p */
$p = X::getInstance(Runner::class, [], [
    'sock'        => __DIR__ . '/parallel.sock',
    'pid'         => __DIR__ . '/parallel.pid',
    'lock'        => __DIR__ . '/parallel.lock',
    'worker_num'  => 4,
    'backlog'     => 128,
    'max_request' => 50,

], 'parallel');

$p->start();
