<?php
ini_set('display_errors', 1);
error_reporting(-1);

class RemoteProcess {
    protected $ports = [];
    protected $sockets = [];
    protected $cmd;
    protected $working_path;
    protected $env;
    protected $context;
    protected $control;
    protected $pid;
    protected $host;

    public $timeout = 60;

    /**
     * @param string $host
     * @param ZMQContext $context
     */
    public function __construct($host = 'localhost', $context = null) {
        $this->context = $context ?: new ZMQContext();
        $this->control = $this->context->getSocket(ZMQ::SOCKET_REQ);
        $this->control->connect("tcp://{$host}:7777");
        $this->host = $host;
    }

    /**
     * @param string $cmd
     * @param string|null $working_path
     * @param array|null $env
     * @return RemoteProcess
     */
    public static function run($cmd, $working_path = null, $env = null) {
        $p = new RemoteProcess();
        $p->open($cmd, $working_path, $env);
        return $p;
    }

    /**
     * Start a new process
     * 
     * @param string $cmd
     * @param string|null $working_path
     * @param array|null $env
     * @return RemoteProcess
     * @throws Exception
     */
    public function open($cmd, $working_path = null, $env = null) {
        $this->cmd = $cmd;
        $this->working_path = $working_path;
        $this->env = $env;

        $this->control->send(json_encode([
            'type' => 'open',
            'cmd' => $cmd,
            'working_path' => $working_path,
            'env' => $env
        ]));
        $message = $this->control->recv();
        $result = json_decode($message, true);

        if($result['type'] == 'pending') {
            do {
                sleep(1);
                $this->control->send(json_encode([
                    'type' => 'getPID',
                    'uuid' => $result['uuid']
                ]));
                $message = $this->control->recv();
                $result = json_decode($message, true);
            } while($result['type'] == 'pending');
        }

        if($result['type'] == 'open') {
            $this->pid = $result['pid'];
            $this->ports = $result['ports'];

            $socket = $this->context->getSocket(ZMQ::SOCKET_PULL);
            $socket->connect("tcp://{$this->host}:{$this->ports['stdout']}");
            $this->sockets['stdout'] = $socket;

            $socket = $this->context->getSocket(ZMQ::SOCKET_PULL);
            $socket->connect("tcp://{$this->host}:{$this->ports['stderr']}");
            $this->sockets['stderr'] = $socket;

            $socket = $this->context->getSocket(ZMQ::SOCKET_REQ);
            $socket->connect("tcp://{$this->host}:{$this->ports['stdin']}");
            $this->sockets['stdin'] = $socket;

            return $this;
        } else {
            throw new Exception($result['message']);
        }
    }

    /**
     * Wait for the process to have more output, for $closed seconds if specified, or until process end if $closed is true
     * 
     * @param bool|int $closed
     * @return RemoteProcess
     */
    public function wait($closed = false) {
        $time = microtime(true);
        if(!$closed) {
            $poll = new ZMQPoll();
            $poll->add($this->sockets['stdout'], ZMQ::POLL_IN);
            $r = $w = [];
            $poll->poll($r, $w, 10);
            if($r) return $this;
        }

        $status = $this->status();
        if($closed === true) {
            while($status['running']) {
                sleep(1);
                $status = $this->status();
            }
        } else {
            while(!$status['has_stdout'] && $status['running']) {
                sleep(1);
                $status = $this->status();
                if(is_int($closed) && microtime(true) - $time > $closed) {
                    break;
                }
            }
        }

        return $this;
    }

    /**
     * Send input to the process, and close STDIN if $moreInput is false
     * 
     * @param string $content
     * @param bool $moreInput
     * @return RemoteProcess
     */
    public function stdin($content, $moreInput = false) {
        $socket = $this->sockets['stdin'];
        $socket->send($content);
        $socket->recv();
        if(!$moreInput) {
            $this->control->send(json_encode([
                'type' => 'endInput',
                'pid' => $this->pid
            ]));
            $this->control->recv();
        }

        return $this;
    }

    /**
     * Write to STDIN
     * 
     * @param string $content
     */
    public function write($content) {
        $this->stdin($content, true);
    }

    /**
     * Read from STDOUT, wait for the process to finish if $complete is true
     * 
     * @param bool $complete
     * @return string
     */
    public function stdout($complete = false) {
        $socket = $this->sockets['stdout'];
        $message = '';
        $poll = new ZMQPoll();
        $poll->add($socket, ZMQ::POLL_IN);
        $new = '';
        do {
            $r = $w = [];
            $poll->poll($r, $w, 10);
            if($r) {
                $new = $socket->recv();
                $message .= $new;
                $r = $w = [];
                $poll->poll($r, $w, 10);
                if(!$r && !$complete) break;
            } else {
                if($complete) {
                    $status = $this->status();
                    if($status['has_stdout'] || $status['running']) {
                        $r = $w = [];
                        $poll->poll($r, $w, $this->timeout * 1000);
                        if($r) continue;
                    } else {
                        break;
                    }
                } else {
                    if($message) break;
                }
            }
        } while($r || $complete);

        return $message;
    }

    /**
     * Read from STDERR, wait for the process to finish if $complete is true
     * 
     * @param bool $complete
     * @return string
     */
    public function stderr($complete=false) {
       $socket = $this->sockets['stderr'];
        $message = '';
        $poll = new ZMQPoll();
        $poll->add($socket, ZMQ::POLL_IN);
        $new = '';
        do {
            $r = $w = [];
            $poll->poll($r, $w, 10);
            if($r) {
                $new = $socket->recv();
                $message .= $new;
                $r = $w = [];
                $poll->poll($r, $w, 10);
                if(!$r && !$complete) break;
            } else {
                if($complete) {
                    $status = $this->status();
                    if($status['has_stderr'] || $status['running']) {
                        $r = $w = [];
                        $poll->poll($r, $w, $this->timeout * 1000);
                        if($r) continue;
                    } else {
                        break;
                    }
                } else {
                    if($message) break;
                }
            }
        } while($r || $complete);

        return $message;
    }

    /**
     * Close the process
     * 
     * @return array
     * @throws Exception
     */
    public function close() {
        $this->control->send(json_encode([
            'type' => 'close',
            'pid' => $this->pid
        ]));
        $message = $this->control->recv();
        $result = json_decode($message, true);

        if($result['type'] == 'close') {
            unset($this->pid);
            unset($this->ports);
            unset($this->sockets);

            return $result;
        } else {
            throw new Exception($result['message']);
        }
    }

    /**
     * Get the status of the process
     * 
     * @return array
     * @throws Exception
     */
    public function status() {
        $this->control->send(json_encode([
            'type' => 'status',
            'pid' => $this->pid
        ]));
        $message = $this->control->recv();
        $result = json_decode($message, true);

        if($result['type'] == 'status') {
            return $result['status'];
        } else {
            throw new Exception($result['message']);
        }
    }

    public function __destruct() {
        if(isset($this->pid)) {
           $this->close();
        }
    }
}



/*file_put_contents('public/test.jpg', (new RemoteProcess('localhost'))->open('convert - JPEG:-')->stdin(file_get_contents('test.png'))->stdout(true));
*/
$p = new RemoteProcess('localhost');
$t = $p->open('echo hi');
echo $t->stdout();
echo $t->wait()->stdout();
