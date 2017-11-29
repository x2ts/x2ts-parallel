<?php
/**
 * Created by IntelliJ IDEA.
 * User: rek
 * Date: 2017/5/1
 * Time: AM1:08
 */

namespace x2ts\parallel;


use Closure;
use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use ReflectionFunction;
use Swoole\Client;
use Swoole\Server;
use swoole_serialize;
use x2ts\Component;
use x2ts\ComponentFactory as X;

class Runner extends Component {
    protected static $_conf = [
        'name'       => 'parallel-runner',
        'sock'       => '/var/run/parallel.sock',
        'pid'        => '/var/run/parallel.pid',
        'lock'       => '/var/run/parallel.lock',
        'daemonize'  => true,
        'workerNum'  => 4,
        'backlog'    => 128,
        'maxRequest' => 50,
    ];

    private $code;

    private $profile = false;

    private $name = 'anonymous';

    /**
     * @var Server
     */
    private $server;

    private $locker;

    public function __construct($closure = null) {
        if ($closure instanceof Closure) {
            $this->code = $this->parseClosure($closure);
        }
    }

    public function __reconstruct($closure = null) {
        if ($closure instanceof Closure) {
            $this->code = $this->parseClosure($closure);
        }
    }

    private function parseClosure(Closure $func): string {
        $rf = new ReflectionFunction($func);
        $sourceFile = $rf->getFileName();
        $sl = $rf->getStartLine();
        $el = $rf->getEndLine();
        $code = file_get_contents($sourceFile);
        $stmts = (new ParserFactory)->create(ParserFactory::ONLY_PHP7, new Lexer([
            'usedAttributes' => ['startLine', 'endLine', 'startFilePos', 'endFilePos'],
        ]))->parse($code);
//        ini_set('xdebug.var_display_max_depth', 500);
//        var_dump($stmts);
//        exit();
        $closureSource = '';
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($code, [$sl, $el], $closureSource) extends NodeVisitorAbstract {
            private $code;

            private $range;

            private $closure;

            private $namespace = '';

            private $uses = [];

            public function __construct($code, $range, &$closure) {
                $this->code = $code;
                $this->range = $range;
                $this->closure = &$closure;
            }

            private function node2code(Node $node) {
                $a = $node->getAttributes();
                return substr($this->code, $a['startFilePos'], $a['endFilePos'] - $a['startFilePos'] + 1);
            }

            private $inClosure = false;

            private $inClass = false;

            private $replaces = [];

            public function enterNode(Node $node) {
                if ($this->inClosure && $node instanceof Node\Name) {
                    $class = (string) $node;
                    if (isset($this->uses[$class])) {
                        $this->replaces[] = $node;
                    } else if (class_exists("$this->namespace\\$class")) {
                        $this->uses[$class] = ltrim("$this->namespace\\$class", '\\');
                        $this->replaces[] = $node;
                    }
                } elseif ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = (string) $node->name;
                } elseif ($node instanceof Node\Stmt\UseUse) {
                    $this->uses[$node->alias] = (string) $node->name;
                } elseif ($this->inClosure && $node instanceof Node\Stmt\Class_) {
                    $this->inClass = true;
                } elseif (
                    $this->inClosure &&
                    !$this->inClass &&
                    $node instanceof Node\Expr\Variable
                ) {
                    if ($node->name === 'this') {
                        throw new ScopeException(
                            'Using $this in the parallel closure is prohibited. Line: ' .
                            $node->getAttribute('startLine')
                        );
                    }
                } elseif ($node instanceof Node\Stmt\Echo_) {
                    X::logger()->warn(
                        'echo is used in the closure on line ' . $node->getAttribute('startLine') .
                        '. Output would be shown in the console of parallel runner.'
                    );
                } elseif ($node instanceof Node\Expr\Closure) {
                    $attrs = $node->getAttributes();
                    if ($this->range === [$attrs['startLine'], $attrs['endLine']]) {
                        $this->inClosure = true;
                    }

                    if ($this->inClosure && !empty($node->uses)) {
                        throw new ScopeException(
                            'Cannot use variables outside closure scope with parallel runner. Line: ' .
                            $node->getAttribute('startLine')
                        );
                    }
                }
            }

            public function leaveNode(Node $node) {
                if ($this->inClosure && $node instanceof Node\Stmt\Class_) {
                    $this->inClass = false;
                }
                if (!$node instanceof Node\Expr\Closure) {
                    return;
                }
                $attrs = $node->getAttributes();
                if ($this->range === [$attrs['startLine'], $attrs['endLine']]) {
                    $originCode = $this->node2code($node);
                    $replaces = array_map(function (Node $node) use ($attrs) {
                        $a = $node->getAttributes();
                        return [
                            $a['startFilePos'] - $attrs['startFilePos'],
                            $a['endFilePos'] - $attrs['startFilePos'] + 1,
                            (string) $node,
                        ];
                    }, $this->replaces);
                    usort($replaces, function ($a, $b) {
                        return $a[0] - $b[0];
                    });
                    $code = '';
                    $lastEndPos = 0;
                    foreach ($replaces as $replace) {
                        $code .= substr($originCode, $lastEndPos, $replace[0] - $lastEndPos);
                        $code .= '\\' . $this->uses[$replace[2]];
                        $lastEndPos = $replace[1];
                    }
                    $code .= substr($originCode, $lastEndPos);
                    $this->closure = $code;
                    $this->inClosure = false;
                    return NodeTraverser::STOP_TRAVERSAL;
                }
            }
        });
        $traverser->traverse($stmts);

        return $closureSource;
    }

    public function name(string $name) {
        $this->name = $name;
        return $this;
    }

    public function profile(bool $enable = true) {
        $this->profile = $enable;
        return $this;
    }

    public function run(...$args) {
        if (empty($this->code)) {
            X::logger()->crit('You must init parallel runner with closure before run');
            return;
        }

        X::bus()->dispatch(new BeforeInvoke(['dispatcher' => $this]));

        $msg = swoole_serialize::pack([
            'function' => $this->code,
            'args'     => $args,
            'name'     => $this->name,
            'profile'  => $this->profile,
        ]);
        $header = 'R';
        $len = strlen($msg);
        $header .= pack('N', $len);
        $client = new Client(SWOOLE_SOCK_UNIX_STREAM, SWOOLE_SOCK_SYNC);
        $client->connect($this->conf['sock'], 0);
        $client->send($header . $msg);
        $client->close();
        $this->name = 'anonymous';
        $this->profile = false;
        X::bus()->dispatch(new AfterInvoke(['dispatcher' => $this]));
    }

    public function start() {
        if ($this->code) {
            X::logger()->crit('Do not call start() on the client side!');
            return;
        }
        $this->server = new Server($this->conf['sock'], 0, SWOOLE_PROCESS, SWOOLE_SOCK_UNIX_STREAM);
        $this->server->set([
            'reactor_num'           => 1,
            'worker_num'            => $this->conf['workerNum'],
            'backlog'               => $this->conf['backlog'],
            'max_request'           => $this->conf['maxRequest'],
            'daemonize'             => $this->conf['daemonize'],
            'open_length_check'     => true,
            'package_length_type'   => 'N',
            'package_length_offset' => 1,
            'package_body_offset'   => 5,
            'package_max_length'    => 52428800, // 50M
        ]);
        $this->server->on('start', function (Server $server) {
            X::logger()->notice('Parallel runner start ' . $server->master_pid);
            @swoole_set_process_name($this->conf['name'] . ': master');
            if ($this->conf['pid']) {
                if (false === @file_put_contents($this->conf['pid'], $server->master_pid)) {
                    X::logger()->warn('Cannot put pid file. ' .
                        (error_get_last() ?? '')
                    );
                }
            }
        });
        $this->server->on('ShutDown', function () {
            if (is_resource($this->locker)) {
                flock($this->locker, LOCK_UN);
                fclose($this->locker);
                unlink($this->conf['lock']);
            }
        });
        $this->server->on('WorkerStart', function (Server $server) {
            X::logger()->notice('Parallel worker start ' . $server->worker_pid);
            @swoole_set_process_name($this->conf['name'] . ': worker');
        });
        $this->server->on('WorkerStop', function (Server $server) {
            X::logger()->notice("Worker {$server->worker_pid} exit");
        });
        $this->server->on('receive', function (Server $server, int $fd, $fromId, $data) {
            $this->runInWorker($data);
        });

        if ($this->lock()) {
            $this->server->start();
        }
    }

    protected function runInWorker($data) {
        $msg = substr($data, 5);
        $call = swoole_serialize::unpack($msg);
//        X::logger()->trace("Code to be run:\n" . $call['function']);
//        X::logger()->trace($call['args']);
        X::bus()->dispatch(new PreRun([
            'dispatcher' => $this,
            'code'       => $call['function'],
            'args'       => $call['args'],
            'name'       => $call['name'],
            'profile'    => $call['profile'],
        ]));
        /** @var Closure $f */
        eval("\$f = {$call['function']};");
        $r = $f(...$call['args']);
        if ($r !== null) {
            X::logger()->warn('The closure run in parallel process should not return a value');
        }
        X::bus()->dispatch(new PostRun([
            'dispatcher' => $this,
            'code'       => $call['function'],
            'args'       => $call['args'],
            'name'       => $call['name'],
            'profile'    => $call['profile'],
            'result'     => $r,
        ]));
    }

    private function lock() {
        $lockFile = $this->conf['lock'];
        if (empty($lockFile)) {
            return true;
        }

        X::logger()->trace('Try to take the lock.');
        if (!is_file($lockFile)) {
            $r = touch($lockFile);
            if (!$r) {
                X::logger()->error('Failed to create lock file ' . $lockFile);
                return false;
            }
        }
        $this->locker = @fopen($lockFile, 'wb');
        $locked = is_resource($this->locker) ? flock($this->locker, LOCK_EX | LOCK_NB) : false;
        if (!$locked) {
            X::logger()->error('Parallel start failed since lock has been taken');
            return false;
        }

        return true;
    }
}

