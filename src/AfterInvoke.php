<?php
/**
 * Created by IntelliJ IDEA.
 * User: rek
 * Date: 2017/11/3
 * Time: 下午6:21
 */

namespace x2ts\parallel;


use x2ts\event\Event;

class AfterInvoke extends Event {
    public static function name(): string {
        return 'x2ts.parallel.AfterInvoke';
    }
}