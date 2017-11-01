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

    public static function name(): string {
        return self::NAME;
    }

    public $code;

    public $args;

    public $name;

    public $profile;

    public function __construct(
        array $props = [
            'dispatcher' => null,
            'code'       => '',
            'args'       => [],
            'name'       => 'anonymous',
            'profile'    => false,
        ]
    ) {
        parent::__construct($props);
    }
}