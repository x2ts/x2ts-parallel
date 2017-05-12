<?php
/**
 * Created by IntelliJ IDEA.
 * User: rek
 * Date: 2017/5/2
 * Time: PM8:50
 */

namespace x2ts\parallel;


use x2ts\event\Event;

class PostRun extends Event {
    const NAME = 'x2ts.parallel.PostRun';

    public $code;

    public $args;

    public $result;

    public function __construct(
        array $props = [
            'dispatcher' => null,
            'code'       => '',
            'args'       => [],
            'result'     => null,
        ]
    ) {
        parent::__construct($props);
    }

    public static function name(): string {
        return self::NAME;
    }
}