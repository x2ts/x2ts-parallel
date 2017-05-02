<?php
/**
 * Created by IntelliJ IDEA.
 * User: rek
 * Date: 2017/5/1
 * Time: PM7:14
 */

namespace x2ts\parallel;

use x2ts\ComponentFactory as X;
use x2ts\db\DataBaseException;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/** @var Runner $p */
$p = X::getInstance(Runner::class, [
    function ($abc, $def = 'aa') {
    try {
        if (is_array($abc)) {
            $abc = serialize($abc);
        }
        (new class('hehe') {
            private $good;

            public function __construct($good) {
                $this->good = $good;
            }

            public function abc() {
                echo $this->good;
            }
        })->abc();
//        echo get_class($this);
        echo "$abc$def\n";
        sleep(1);
        echo "$abc wake up \n";
    } catch (DataBaseException $ex) {
        echo DataBaseException::class;
        echo $ex->getMessage();
    } catch (ScopeException $ex) {
        echo $ex->getMessage();
    }
}], [
    'sock'        => __DIR__ . '/parallel.sock',
    'pid'         => __DIR__ . '/parallel.pid',
    'lock'        => __DIR__ . '/parallel.lock',
    'worker_num'  => 4,
    'backlog'     => 128,
    'max_request' => 50,

], 'parallel');

for ($i = 0; $i < 5000; $i++) {
    $p->run($i);
}
