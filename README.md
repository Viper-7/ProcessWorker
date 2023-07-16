# ProcessWorker

ProcessWorker is a PHP library for running processes within remote containers, and reading their output.

## Usage

### Running a process

    $p = new RemoteProcess('localhost');
    $p->open('echo hi');
    echo $p->stdout();

```
hi
```

### Running a process with input

    $p = new RemoteProcess('localhost');
    $p->open('cat');
    $p->stdin('hi');
    echo $p->stdout();

```
hi
```

### Running a process with input, and waiting for it to finish

    $p = new RemoteProcess('localhost');
    $p->open('cat');
    $p->stdin('sleep 2 && hi');
    echo $p->wait(true)->stdout();

```
hi
```

### Running a process and progressively processing output

    $p = new RemoteProcess('localhost');
    $p->open('echo hi && sleep 2 && echo hi');
    echo $p->stdout();
    echo $p->wait()->stdout();

```
hi
hi
```

### Converting a png to jpg

    $p = new RemoteProcess('localhost');
    $p->open('convert - JPEG:-');
    $p->stdin(file_get_contents('test.png'));
    file_put_contents('test.jpg', $p->stdout(true));

### Running a process with a working path

    $p = new RemoteProcess('localhost');
    $p->open('pwd', '/tmp');
    echo $p->stdout();

```
/tmp
```

### Running a process with an environment

    $p = new RemoteProcess('localhost');
    $p->open('echo $FOO', '', ['FOO' => 'bar']);
    echo $p->stdout();

```
bar
```


## Notes

* The process will be closed automatically when the object is destroyed, but you can close it manually with `$p->close()`
* The default timeout is 60 seconds, but you can change it with `$p->timeout = 10;`
* `$p->stdout(true)` and `$p->stderr(true)` will wait for the process to finish before returning
* There is a difference between `$p->wait(true)->stdout()` and `$p->wait()->stdout(true)`. The first will BLOCK if the process generates more data than the buffers can store.
* `$p->wait(true)` will wait forever as long as the process is still connected and running, if you do not specify a timeout.
* `$p->stdin('hi')` will close STDIN, so can only be used once per process, while `$p->stdin('hi', true)` and `$p->write('hi')` are reusable.
* Most methods are chainable, so you can do `$p->open('cat')->stdin('Hello, World!')->stdout();`
* You can check if the process has output with `$p->status()['has_stdout']` and `$p->status()['has_stderr']`
* You can check if the process is still running with `$p->status()['running']`
* You can get the PID of the process with `$p->status()['pid']`
