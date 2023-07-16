# ProcessWorker

ProcessWorker is a PHP library for running processes within remote containers, and reading their output.

## Usage

### Running a process

    $p = new RemoteProcess('localhost');
    $p->open('echo hi');
    echo $p->stdout();

### Running a process with input

    $p = new RemoteProcess('localhost');
    $p->open('cat');
    $p->stdin('hi');
    echo $p->stdout();

### Running a process with input, and waiting for it to finish

    $p = new RemoteProcess('localhost');
    $p->open('cat');
    $p->stdin('sleep 2 && hi');
    echo $p->wait(true)->stdout();

### Running a process and progressively processing output

    $p = new RemoteProcess('localhost');
    $p->open('echo hi && sleep 2 && echo hi');
    echo $p->stdout();
    echo $p->wait()->stdout();
