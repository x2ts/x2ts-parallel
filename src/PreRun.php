<?php
/**
 * Created by IntelliJ IDEA.
 * User: rek
 * Date: 2017/5/2
 * Time: PM8:47
 */

namespace x2ts\parallel;


use x2ts\event\Event;

class PreRun extends Event {
    const NAME = 'x2ts.parallel.PreRun';

    public $code;

    public $args;

    public function __construct(
        array $props = [
            'dispatcher' => null,
            'code'       => '',
            'args'       => [],
        ]
    ) {
        parent::__construct(self::NAME, $props);
    }
}