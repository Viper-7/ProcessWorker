<?php
/*
Listens on $control_port for commands
Commands should be JSON encoded.

=== Run Task ===

Request: {
    "type": "open",
    "cmd": "php -f /home/steve/Projects/PHP/ProcessWorker/test.php",
    "working_path": "/home/steve/Projects/PHP/ProcessWorker",
    "env": {
        "FOO": "BAR"
    }
}

Response: {
    "type": "open",
    "pid": 12345,
    "ports": {
        "event": 40000,
        "stdin": 40001,
        "stdout": 40002,
        "stderr": 40003
    }
}


=== Close Task ===

Request: {
    "type": "close",
    "pid": 12345
}

Response: {
    "type": "close",
    "pid": 12345
}


=== Get Task Status ===

Request: {
    "type": "status",
    "pid": 12345
}

Response: {
    "type": "status",
    "pid": 12345,
    "status": {
        "command": "exec php -f /home/steve/Projects/PHP/ProcessWorker/test.php",
        "pid": 12345,
        "running": 1,
        "signaled": "",
        "stopped": "",
        "exitcode": -1
    }
}


=== End Input ===
Closes the STDIN stream for a process, required for some commands to begin processing

Request: {
    "type": "endInput",
    "pid": 12345
}

Response: {
    "type": "endInput",
    "pid": 12345
}


*/


class ProcessWorker {
    protected $process_handles = [];        // [pid][control|stdin|stdout|stderr] = resource
    protected $zmq_context = null;          // ZMQContext
    protected $control_port = 7777;         // int
    protected $control_socket = null;       // ZMQSocket
    protected $portrange = '40000-60000';   // string [port-port]
    protected $temp_files = [];             // [pid][stdout|stderr] = string [path]
    protected $socket_map = [];             // [pid][event|stdin|stdout|stderr] = ZMQSocket
    protected $port_map = [];               // [pid][event|stdin|stdout|stderr] = int [port]
    protected $port_usage = [];             // [port] = int [0|1|time]
    protected $inactive = [];               // [pid] = int [time]
    protected $pipes = [];                  // [pid][stdin|stdout|stderr] = ZMQSocket
    protected $incoming = [];               // [pid][stdout|stderr] = string [buffer]
    protected $active = false;          

    public $inactive_timeout = 300;         // int [seconds]
    public $logLevel = 0;                   // int [0-3]

    /**
     * Log a message to error handler (stdout)
     * 
     * @param $msg string|mixed
     * @param $prefix string
     * @param $level int
     */
    function debug($msg, $prefix = null, $level=0) {
        if($level > $this->logLevel) return;
        if($prefix) {
            echo "$prefix: ";
        }
        if(is_string($msg)) {
            echo trim($msg) . "\n";
        } else {
            var_dump($msg);
        }
    }

    /**
     * Setup the worker
     * 
     * @param $control_port int
     * @param $port_range string [port-port]
     * @param $context ZMQContext
     */
    public function __construct($control_port = 7777, $port_range = null, $context = null) {
        if(!$context) $context = new ZMQContext();
        $this->zmq_context = $context;
        $this->control_port = $control_port;
        if($port_range)
            $this->portrange = $port_range;
        $this->control_socket = $this->zmq_context->getSocket(ZMQ::SOCKET_REP);
        $this->control_socket->bind("tcp://*:{$this->control_port}");
        $this->debug("Listening on port {$this->control_port}");
    }

    /**
     * Run the process worker endlessly
     */
    public function run() {
        while(1) {
            $this->poll();
        }
    }

    /**
     * Poll for incoming messages for 100ms
     */
    public function poll() {
        $time = microtime(true);
        $this->active = false;

        do {
            $this->pollInbound();
            $this->pollOutbound();

            if(!$this->active) {
                $diff = microtime(true) - $time;
                if($diff < 0.1) {
                    usleep(100000 - ($diff * 1000000));
                    break;
                }
            }
        } while(microtime(true) - $time < 0.1);
    }

    /**
     * Configures another process to receive all stdout from this one
     * 
     * @param $pid int
     * @param $channel string [stdout|stderr]
     * @param $target string [host:port]
     * @return $this
     */
    public function pipe($pid, $channel, $target) {
        if(!isset($this->process_handles[$pid][$channel])) {
            throw new Exception("Invalid PID/channel");
        }
        
        $socket = new ZMQSocket($this->zmq_context, ZMQ::SOCKET_PUSH);
        $socket->connect("tcp://{$target}");
        $this->pipes[$pid][$channel] = $socket;

        return $this;
    }

    /**
     * Returns the status of a process
     * 
     * @param $pid int
     * @return array
     */
    public function getChildStatus($pid) {
        if (!isset($this->process_handles[$pid]['control'])) {
            throw new Exception("Invalid pid");
        }
        $process = $this->process_handles[$pid]['control'];
        $status = proc_get_status($process);

        $r = $w = $e = [];
        $r[] = $this->process_handles[$pid]['stdout'];
        $r[] = $this->process_handles[$pid]['stderr'];
        stream_select($r, $w, $e, 0);
        $status['has_stdout'] = false;
        $status['has_stderr'] = false;
        foreach($r as $s) {
            if($s === $this->process_handles[$pid]['stdout']) {
                $status['has_stdout'] = true;
            } else if($s === $this->process_handles[$pid]['stderr']) {
                $status['has_stderr'] = true;
            }
        }
        $status['feof_stdout'] = feof($this->process_handles[$pid]['stdout']);
        return $status;
    }
    
    /**
     * Close a process and cleanup
     * 
     * @param $pid int
     * @param $reason string
     */
    public function closeChild($pid, $reason = null) {
        if (!isset($this->process_handles[$pid]['control'])) {
            throw new Exception("Invalid pid");
        }
        if($reason) $reason = " due to: " . $reason;
        $this->debug("Closing child $pid{$reason}", null, 2);
        $process = $this->process_handles[$pid]['control'];
        $status = proc_get_status($process);
        
        $ports = $this->port_map[$pid];
        $this->debug($ports, "Closing ports", 3);

        if ($status['running']) {
            proc_terminate($process);
        }
        proc_close($process);
        $status = shell_exec("ps -o pid | grep $pid");
        if($status) {
            $this->debug("Killing $pid", null, 3);
            shell_exec("kill -9 $pid");
        }
        if(isset($this->inactive[$pid])) {
            unset($this->inactive[$pid]);
        }
        if(isset($this->pipes[$pid])) {
            unset($this->pipes[$pid]);
        }
        $this->handleClose($pid);
    }

    /**
     * Callback for handling process open events
     * 
     * @param $pid int
     * @param $cmd string
     * @param $working_path string
     * @param $env array
     */
    protected function handleOpen($pid, $cmd, $working_path, $env) {
        $this->sendJSONMessage($pid, [
            'type' => 'open',
            'cmd' => $cmd,
            'working_path' => $working_path,
            'env' => $env
        ]);
    }

    /**
     * Callback for handling process close events
     * 
     * @param $pid int
     */
    protected function handleClose($pid) {
        $this->sendJSONMessage($pid, [
            'type' => 'close'
        ]);
        foreach($this->port_map[$pid] as $key => $port) {
            $socket = $this->socket_map[$pid][$key];
            $socket->unbind("tcp://*:" . $port);
            $this->port_usage[$port] = time();
        }
        unset($this->process_handles[$pid]);
        if(PHP_OS == 'WINNT') {
            unlink($this->temp_files[$pid]['stdout']);
            unlink($this->temp_files[$pid]['stderr']);
            unset($this->temp_files[$pid]);
        }
        unset($this->socket_map[$pid]);
        unset($this->port_map[$pid]);
        $this->debug("Closed child $pid", null, 3);
    }

    /**
     * Find missing required parameters
     * 
     * @param $command array
     * @param $fields array
     * @return array
     */
    protected function validateCommand($command, $fields) {
        $keys = array_keys($command);
        $missing = array_diff($fields, $keys);
        return $missing;
    }

    /**
     * Process a socket control instruction
     * 
     * @param $command array
     * @return array
     */
    protected function handleCommand($command) {
        $this->debug(json_encode($command), 'Received Command', 1);

        if(!isset($command['type'])) {
            return [
                'type' => 'error',
                'message' => 'Invalid command'
            ];
        }

        switch($command['type']) {
            /*
             * Open a new process
             * 
             * Required fields:
             * - type           string      'open'
             * - cmd            string      Command to execute
             * - working_path   string      Working directory
             * - env            array       Environment variables
             * 
             * Returns:
             * - type           string      'open'
             * - pid            int         Process ID
             * - ports          array       [event, stdin, stdout, stderr]
             */
            case 'open':
                $errors = $this->validateCommand($command, ['cmd', 'working_path', 'env']);
                if($errors) {
                    return [
                        'type' => 'error',
                        'message' => 'Missing fields: ' . implode(', ', $errors)
                    ];
                }
                $result = $this->attemptSpawn($command['cmd'], $command['working_path'], $command['env']);
                if($result) {
                    return [
                        'type' => 'open',
                        'pid' => $result,
                        'ports' => $this->port_map[$result]
                    ];
                } else {
                    return [
                        'type' => 'error',
                        'message' => 'Failed to spawn process'
                    ];
                }
                break;

            /*
             * Pipe a process's stdout or stderr to another process
             * 
             * Required fields:
             * - type           string      'pipe'
             * - pid            int         Process ID
             * - channel        string      'stdout' or 'stderr'
             * - target         string      'host:port'
             *  
             * Returns:
             * - type           string      'pipe'
             * - pid            int         Process ID
             * - channel        string      'stdout' or 'stderr'
             * - target         string      'host:port'
             */
            case 'pipe':
                $errors = $this->validateCommand($command, ['pid', 'channel', 'target']);
                if($errors) {
                    return [
                        'type' => 'error',
                        'message' => 'Missing fields: ' . implode(', ', $errors)
                    ];
                }
                $pid = $command['pid'];
                $channel = $command['channel'];
                $target = $command['target'];
                $this->pipe($pid, $channel, $target);
                return [
                    'type' => 'pipe',
                    'pid' => $pid,
                    'channel' => $channel,
                    'target' => $target
                ];
                break;
            /*
             * Close a process
             * 
             * Required fields:
             * - type           string      'close'
             * - pid            int         Process ID
             * 
             * Returns:
             * - type           string      'close'
             * - pid            int         Process ID
             */
            case 'close':
                $errors = $this->validateCommand($command, ['pid']);
                if($errors) {
                    return [
                        'type' => 'error',
                        'message' => 'Missing fields: ' . implode(', ', $errors)
                    ];
                }
                $this->closeChild($command['pid'], 'requested');
                return [
                    'type' => 'close',
                    'pid' => $command['pid']
                ];
                break;
            /*
             * Complete writing to a process's STDIN
             * 
             * Required fields:
             * - type           string      'endInput'
             * - pid            int         Process ID
             * 
             * Returns:
             * - type           string      'endInput'
             * - pid            int         Process ID
             */
            case 'endInput':
                $errors = $this->validateCommand($command, ['pid']);
                if($errors) {
                    return [
                        'type' => 'error',
                        'message' => 'Missing fields: ' . implode(', ', $errors)
                    ];
                }
                $pid = $command['pid'];
                $this->debug("Ending input for $pid", null, 3);
                fclose($this->process_handles[$pid]['stdin']);
                unset($this->process_handles[$pid]['stdin']);
                return [
                    'type' => 'endInput',
                    'pid' => $pid
                ];
                break;
            /*
             * Get the status of a process
             * 
             * Required fields:
             * - type           string      'status'
             * - pid            int         Process ID
             * 
             * Returns:
             * - type           string      'status'
             * - pid            int         Process ID
             * - status         array       Process status {command, pid, running, signaled, stopped, exitcode}
             */
            case 'status':
                $errors = $this->validateCommand($command, ['pid']);
                if($errors) {
                    return [
                        'type' => 'error',
                        'message' => 'Missing fields: ' . implode(', ', $errors)
                    ];
                }
                $pid = $command['pid'];
                $status = $this->getChildStatus($pid);
                return [
                    'type' => 'status',
                    'pid' => $pid,
                    'status' => [
                        'command' => $status['command'],
                        'pid' => $status['pid'],
                        'running' => $status['running'],
                        'signaled' => $status['signaled'],
                        'stopped' => $status['stopped'],
                        'exitcode' => $status['exitcode'],
                        'has_stdout' => !empty($this->incoming[$pid]['stdout']),
                        'has_stderr' => !empty($this->incoming[$pid]['stderr']),
                    ]
                ];
                break;
        }
    }

    protected function sendJSONMessage($pid, $message, $channel='event') {
        $this->sendMessage($pid, json_encode($message), $channel);
    }

    /**
     * Send a message to a socket listener
     * 
     * @param $pid int
     * @param $message string
     * @param $channel string [event|stdin|stdout|stderr]
     */
    protected function sendMessage($pid, $message, $channel='event') {
        if(!isset($this->socket_map[$pid][$channel])) {
            throw new Exception("Invalid channel");
        }
        $socket = $this->socket_map[$pid][$channel];
        $poll = new ZMQPoll();
        $poll->add($socket, ZMQ::POLL_OUT);
        $r = $w = [];
        $poll->poll($r, $w, 0);
        if($w) {
            $socket->send($message);
        }
    }

    /**
     * Check if a port is available
     * 
     * @param $port int
     * @return bool
     */
    protected function checkPort($port) {
        if(!isset($this->port_usage) || $this->port_usage[$port] === 0) return false;
        if(isset($this->port_usage) && $this->port_usage[$port] !== 1 && time() - $this->port_usage[$port] < 60) {
            foreach($this->port_map as $pid => $ports) {
                if($key = array_search($port, $ports)) {
                    $socket = $this->socket_map[$pid][$key];
                    $socket->unbind("tcp://*:" . $port);
                    unset($this->port_map[$pid]);
                    unset($this->socket_map[$pid]);
                    unset($this->port_usage[$port]);
                }
            }
            return false;
        }
    }

    /**
     * Allocate a set of ports for a new process
     * 
     * @return array
     */
    protected function allocatePorts() {
        $portrange = explode('-', $this->portrange);
        foreach(range($portrange[0], $portrange[1], 4) as $port) {
            if(!in_array($port, $this->port_map) && !in_array($port+1, $this->port_map) && !in_array($port+2, $this->port_map) && !in_array($port+3, $this->port_map)) {
                if(isset($this->port_usage[$port]) && ($this->port_usage[$port] === 1 || ($this->port_usage[$port] !== 0 && time() - $this->port_usage[$port] < 60))) continue;
                if(isset($this->port_usage[$port+1]) && ($this->port_usage[$port+1] === 1 || ($this->port_usage[$port+1] !== 0 && time() - $this->port_usage[$port+1] < 60))) continue;
                if(isset($this->port_usage[$port+2]) && ($this->port_usage[$port+2] === 1 || ($this->port_usage[$port+2] !== 0 && time() - $this->port_usage[$port+2] < 60))) continue;
                if(isset($this->port_usage[$port+3]) && ($this->port_usage[$port+3] === 1 || ($this->port_usage[$port+3] !== 0 && time() - $this->port_usage[$port+3] < 60))) continue;
                
                $this->port_usage[$port] = 1;
                $this->port_usage[$port+1] = 1;
                $this->port_usage[$port+2] = 1;
                $this->port_usage[$port+3] = 1;

                return [
                    'event' => $port,
                    'stdin' => $port+1,
                    'stdout' => $port+2,
                    'stderr' => $port+3
                ];
            }
        }
    }

    protected function spawnInboundSocket($port) {
        $this->debug("Binding inbound to port $port", null, 3);
        $socket = $this->zmq_context->getSocket(ZMQ::SOCKET_REP);
        $socket->bind("tcp://*:$port");
        return $socket;
    }

    protected function spawnOutboundSocket($port) {
        $this->debug("Binding outbound to port $port", null, 3);
        $socket = $this->zmq_context->getSocket(ZMQ::SOCKET_PUSH);
        $socket->bind("tcp://*:$port");
        return $socket;
    }

    /**
     * Attempt to create the sockets for a new process
     * 
     * @return array
     */
    protected function spawnSockets() {
        $ports = $this->allocatePorts();
        $sockets = [];
        if($ports) {
            $sockets['event'] = $this->spawnOutboundSocket($ports['event']);
            $sockets['stdin'] = $this->spawnInboundSocket($ports['stdin']);
            $sockets['stdout'] = $this->spawnOutboundSocket($ports['stdout']);
            $sockets['stderr'] = $this->spawnOutboundSocket($ports['stderr']);
            $this->debug(json_encode($ports), "Spawned sockets", 1);
            return [$sockets, $ports];
        }
    }
	
    /**
     * Helper function to check stream contents on Windows platforms
     * 
     * @param $conn resource
     * @param $prop string [stdout|stderr]
     * @return bool
     */
	protected function hasData($conn, $prop) {
        if(!isset($conn[$prop])) return false;
		$stream = $conn[$prop];

		if(PHP_OS == 'WINNT') {
			$loc = ftell($stream);
			$data = fread($stream, 1);
			fseek($stream, $loc, SEEK_SET);

			return $data !== '' && $data !== FALSE;
		} else {
            $r = $w = $e = [];
            $r[] = $stream;
            $ready = stream_select($r, $w, $e, 0);
            return count($r);
        }
	}

    /**
     * Attempt to spawn a new process
     * 
     * @param $cmd string
     * @param $path string
     * @param $env array
     * @return int|bool
     */
    protected function attemptSpawn($cmd, $path, $env) {
        $socket = $this->spawnSockets();
        if($socket) {
            $child = $this->spawnChild($cmd, $path, $env);
            list($sockets, $ports) = $socket;

            $this->socket_map[$child] = $sockets;
            $this->port_map[$child] = $ports;

            $this->handleOpen($child, $cmd, $path, $env);

            return $child;
        }
    }

    /**
     * Process data coming from the STDIN socket headed to the process
     */
    protected function pollOutbound() {
        $map = [];
        $poll = new ZMQPoll();
        foreach($this->process_handles as $pid => $process) {
            if(isset($this->socket_map[$pid])) {
                $sockets = $this->socket_map[$pid];
                $poll->add($sockets['stdin'], ZMQ::POLL_IN);
                $map[$pid] = $sockets['stdin'];
            }
        }
        $poll->add($this->control_socket, ZMQ::POLL_IN);
        $read = $write = [];
        $ready = $poll->poll($read, $write, 0);
        if ($read) {
            $this->active = true;
            foreach($read as $socket) {
                if($socket === $this->control_socket) {
                    $message = $socket->recv();
                    $this->debug($message, "Received command", 3);
                    $result = $this->handleCommand(json_decode($message, true));
                    $this->debug($result, "Sending response", 3);
                    $socket->send(json_encode($result));
                    $this->debug($result, "Sent response", 3);
                } else {
                    $pid = array_search($socket, $map);
                    $r = $w = $e = [];
                    if(isset($this->process_handles[$pid]['stdin'])) {
                        $this->debug("STDIN to $pid", null, 3);
                        $w[] = $this->process_handles[$pid]['stdin'];
                    }

                    if(($r||$w||$e) && stream_select($r, $w, $e, 0)) {
                        $bytes = fwrite($this->process_handles[$pid]['stdin'], $socket->recv());
                        $this->debug("Wrote $bytes bytes to $pid", null, 3);
                        $socket->send($bytes);
                    }
                }
            }
        }
    }

    /**
     * Process data coming from the process's STDOUT and STDERR streams
     */
    protected function pollInbound() {
        $read = $write = $except = array();

        // Grab all active process streams
        foreach($this->process_handles as $pid => $process) {
            $status = proc_get_status($process['control']);
            if(PHP_OS == 'WINNT') {
                if($this->hasData($process, 'stdout')) {
                    if(empty($this->incoming[$pid]['stdout']))
                        $read[] = $process;
                }
                if($this->hasData($process, 'stderr')) {
                    if(empty($this->incoming[$pid]['stderr']))
                        $read[] = $process;
                }
            } else {
                if(empty($this->incoming[$pid]['stdout']))
                    $read[] = $process['stdout'];
                if(empty($this->incoming[$pid]['stderr']))
                    $read[] = $process['stderr'];
            }
        }

        // Process data already in the buffer
        if(!empty($this->incoming)) {
            foreach($this->incoming as $pid => $channels) {
                foreach($channels as $channel => $lines) {
                    if(!isset($this->socket_map[$pid][$channel])) {
                        // If the socket is gone, just drop the data
                        unset($this->incoming[$pid][$channel]);
                        continue;
                    }

                    // Check if the socket is ready to receive data
                    $r = $w = [];
                    $poll = new ZMQPoll();
                    $poll->add($this->socket_map[$pid][$channel], ZMQ::POLL_OUT);
                    $ready = $poll->poll($r, $w, 0);
                    if($w) {
                        // It is. Flag us as active to minimize transfer delays
                        $this->active = true;
                        $line = array_shift($lines);
                        foreach($w as $s) {
                            $this->debug("Sending $channel to $pid stream", null, 3);
                            $s->send($line);

                            // If there's more data, and the client is taking it, keep relaying it
                            do {
                                $r = $w = $e = [];
                                $r[] = $this->process_handles[$pid][$channel];
                                $ready = stream_select($r, $w, $e, 0);
                                if($r) {
                                    $line = fread($this->process_handles[$pid][$channel], 3800);
                                    if($line) {
                                        // Need to recheck the client every time. @TODO @BLOCKING
                                        $s->send($line);
                                    } else {
                                        break;
                                    }
                                }
                            } while($r);
                            $this->debug("Done Sending $channel to $pid stream", null, 3);
                        }
                    }

                    // Strip the line we just processed from the buffer
                    if($lines)
                        $this->incoming[$pid][$channel] = $lines;
                    else
                        unset($this->incoming[$pid][$channel]);
                }
            }
        }

        // Check for new data on STDOUT and STDERR
        if (count($read) > 0) {
            $ready = stream_select($read, $write, $except, 0);
            if ($ready === false) {
                throw new Exception("stream_select failed");
            }
            if ($read) {
                // Flag us as active to minimize transfer delays
                $this->active = true;

                foreach($read as $process) {
                    if(feof($process)) continue;    // I don't think this is still doing anything @TODO
                    
                    // Find the PID based on the process handle
                    $pid = 0;
                    foreach($this->process_handles as $id => $channels) {
                        if($process == $channels['stdout'] || $process == $channels['stderr']) {
                            $pid = $id;
                            break;
                        }
                    }
                    $channel = $process == $this->process_handles[$pid]['stdout'] ? 'stdout' : 'stderr';

                    // Read the next chunk into the buffer
                    $line = fread($process, 3800);
                    if($line) {
                        $this->incoming[$pid][$channel][] = $line;
                        $this->debug("Received $channel from $pid", null, 3);

                        if(isset($this->pipes[$pid][$channel])) {
                            // This needs validation that the pipe is actually ready @TODO @BLOCKING
                            $this->pipes[$pid][$channel]->send($line);
                        }
                    }
                }
            }
        }

        // Cleanup inactive sockets
        foreach($this->port_usage as $port => $expiry) {
            if($expiry === 1 || time() - $expiry < 30) continue;

            foreach($this->port_map as $pid => $ports) {
                if($key = array_search($port, $ports)) {
                    $socket = $this->socket_map[$pid][$key];
                    $socket->unbind("tcp://*:" . $port);
                    unset($this->port_map[$pid]);
                    unset($this->socket_map[$pid]);
                    unset($this->port_usage[$port]);
                }
            }
            return false;
        }

        // Cleanup inactive processes
        foreach($this->process_handles as $pid => $process) {
            if(!isset($this->inactive[$pid])) {
                $status = proc_get_status($process['control']);
                if($status['running'] == false) {
                    $this->inactive[$pid] = time();
                }
            } else {
                if(time() - $this->inactive[$pid] > $this->inactive_timeout) {
                    $this->closeChild($pid, 'inactive');
                }
            }
        }
    }

    /**
     * Spawn a new process
     * 
     * @param $cmd string
     * @param $working_path string
     * @param $env array
     * @return int
     */
    protected function spawnChild($cmd, $working_path, $env = null) {
		if(PHP_OS == 'WINNT') {
			$stdoutFile = tempnam(sys_get_temp_dir(), 'out');
			$stderrFile = tempnam(sys_get_temp_dir(), 'err');

			$stdout = fopen($stdoutFile, 'w+');
			$stderr = fopen($stderrFile, 'w+');
			
			$spec = array(
				array('pipe', 'r'),
				$stdout,
				$stderr
			);
		} else {
			$spec = array(
				array('pipe', 'r'),
				array('pipe', 'w'),
				array('pipe', 'w')
			);
		}

        $pipes = [];
        $this->debug([$cmd, $spec, $working_path, $env], "Spawning child", 2);
        if(PHP_OS == 'WINNT') {
            $process = proc_open($cmd, $spec, $pipes, $working_path, $env);
        } else {
            $process = proc_open('' . $cmd, $spec, $pipes, $working_path, $env);
        }
        if (!is_resource($process)) {
            throw new Exception("Failed to open process");
        }
        $status = proc_get_status($process);
        $pid = $status['pid'];

        $this->debug($status, "Spawned child $pid", 2);
        $this->debug("Child $pid started", null, 1);

        if(PHP_OS == 'WINNT') {
            $this->process_handles[$pid] = [
                'control' => $process,
                'stdin' => $pipes[0],
                'stdout' => $spec[1],
                'stderr' => $spec[2]
            ];
            $this->temp_files[$pid] = [
                'stdout' => $stdoutFile,
                'stderr' => $stderrFile
            ];
        } else {
            $this->process_handles[$pid] = [
                'control' => $process,
                'stdin' => $pipes[0],
                'stdout' => $pipes[1],
                'stderr' => $pipes[2]
            ];
        }

        return $pid;
    }
}

ini_set('display_errors', 1);
error_reporting(-1);
$pw = new ProcessWorker();
$pw->run();

