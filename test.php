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

    public function __construct($host = 'localhost', $context = null) {
        $this->context = $context ?: new ZMQContext();
        $this->control = $this->context->getSocket(ZMQ::SOCKET_REQ);
        $this->control->connect("tcp://{$host}:7777");
        //$this->control->setSockOpt(ZMQ::SOCKOPT_LINGER, 1000);
        //$this->control->setSockOpt(ZMQ::SOCKOPT_SNDBUF, 4000);
        $this->host = $host;
    }

    public static function run($cmd, $working_path = null, $env = null) {
        $p = new RemoteProcess();
        $p->open($cmd, $working_path, $env);
        return $p;
    }

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

    public function wait($closed = false) {
        if(!$closed) {
            $poll = new ZMQPoll();
            $poll->add($this->sockets['stdout'], ZMQ::POLL_IN);
            $r = $w = [];
            $poll->poll($r, $w, 10);
            if($r) return $this;
        }

        $status = $this->status();
        if($closed) {
            while($status['running']) {
                sleep(1);
                $status = $this->status();
            }
        } else {
            while(!$status['has_stdout'] && $status['running']) {
                sleep(1);
                $status = $this->status();
            }
        }

        return $this;
    }

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


file_put_contents('public/test.jpg', (new RemoteProcess('localhost'))->open('convert - JPEG:-')->stdin(file_get_contents('test.png'))->stdout(true));

$t = $p->open('echo hi && sleep 5 && echo hi');
echo $t->stdout();
echo $t->wait()->stdout();
