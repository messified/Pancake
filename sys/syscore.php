<?php
    
    /****************************************************************/
    /* dreamServ                                                    */
    /* syscore.php                                                  */
    /* 2012 Yussuf "pp3345" Khalil                                  */
    /* License: http://creativecommons.org/licenses/by-nc-sa/3.0/   */
    /****************************************************************/
    
    declare(ticks = 1);
    const VERSION = '0.1';
    
    // Include files necessary to run dreamServ
    require_once 'functions.php';
    require_once 'thread.class.php';
    require_once 'communicationPipe.class.php';
    require_once 'configuration.class.php';
    
    set_error_handler('errorHandler');
     
    $startOptions = getopt('h', array('benchmark::', 'debug', 'daemon', 'live', 'help'));
    
    if(isset($startOptions['h']) || isset($startOptions['help'])) {
        out('dreamServ '.VERSION, SYSTEM, false);
        out('2012 Yussuf "pp3345" Khalil', SYSTEM, false);
        echo "\n";
        out('dreamServ is a simple and lightweight HTTP-server. These are the start-options you may use:', SYSTEM, false);
        out('-h --help                          Displays this help', SYSTEM, false);
        out('--benchmark=100                    Runs dreamServ in benchmark-mode - It will then benchmark the speed needed for handling requests. You can specify a custom amount for the number of requests to be executed', SYSTEM, false);
        out('--debug                            Runs dreamServ in debug-mode - It will output further information on errors, which may prove helpful for developers', SYSTEM, false);
        out('--daemon                           Runs dreamServ in daemon-mode - It will then run as a process in background', SYSTEM, false);
        out('--live                             Runs dreamServ in live-mode - It will not run in background and output every piece of information directly to the user', SYSTEM, false);
        exit;
    }
    
    out('Loading dreamServ '.VERSION.'...', SYSTEM, false);
    
    // Check for POSIX-compliance
    if(!is_callable('posix_getpid')) {
        out('dreamServ can\'t run on this system. Either your operating system isn\'t POSIX-compliant or PHP was compiled with --disable-posix', SYSTEM, false);
        abort();
    }
    
    // Check for available PCNTL-functions
    if(!is_callable('pcntl_signal')) {
        out('dreamServ can\'t run on this system. You need to recompile PHP with --enable-pcntl', SYSTEM, false);
        abort();
    }
    
    // Check if PECL-extension for YAML-support is installed
    if(!extension_loaded('yaml')) {
        out('You need to install the PECL-extension for YAML-support in order to run dreamServ.', SYSTEM, false);
        abort();
    }
    
    // Check for root-user
    if(posix_getuid() !== 0) {
        out('You need to run dreamServ as root.', SYSTEM, false);
        abort();
    }
    
    // Load configuration
    Config::load();
    
    // Set DEBUG_MODE
    if(isset($startOptions['debug']) || Config::get('main.debugmode') === true)
        define('DEBUG_MODE', true);
    
    out('Basic configuration loaded');
    if(DEBUG_MODE === true)
        out('Running in debugmode');
        
    // Check if configured user exists
    if(posix_getpwnam(Config::get('main.user')) === false || posix_getgrnam(Config::get('main.group')) === false) {
        out('The configured user/group doesn\'t exist.', SYSTEM, false);
        abort();
    }
    
    pcntl_signal(SIGUSR2, 'abort');
    
    if(isset($startOptions['daemon'])) {
        ignore_user_abort(true);
        
        if(is_resource(STDIN))  fclose(STDIN);
        if(is_resource(STDOUT)) fclose(STDOUT);
        if(is_resource(STDERR)) fclose(STDERR);
        define('DAEMONIZED', true);
    }
    
    while(true) {
        sleep(1);
    }
?>
