<?php
  
    /****************************************************************/
    /* Pancake                                                      */
    /* phpWorker.class.php                                          */
    /* 2012 Yussuf "pp3345" Khalil                                  */
    /* License: http://creativecommons.org/licenses/by-nc-sa/3.0/   */
    /****************************************************************/
    
    namespace Pancake;
    
    if(PANCAKE !== true)
        exit;
    
    /**
    * PHPWorker that runs PHP-scripts    
    */
    class PHPWorker extends Thread {
        static private $instances = array();
        public $id = 0;
        public $IPCid = 0;
        public $vHost = null;
        
        /**
        * Creates a new PHPWorker
        * 
        * @param vHost $vHost
        * @return PHPWorker
        */
        public function __construct(vHost $vHost) {
            $this->vHost = $vHost;
            
            // Save instance
            self::$instances[] = $this;
            
            // Set address for IPC based on vHost-ID
            $this->id = max(array_keys(self::$instances));
            $this->IPCid = PHP_WORKER_TYPE.$this->vHost->getID();
            
            $this->codeFile = 'threads/single/phpWorker.thread.php';
            $this->friendlyName = 'PHPWorker #'.($this->id+1).' ("'.$this->vHost->getName().'")';
            
            // Start worker
            $this->start(false);
            
            usleep(10000);
        }
        
        /**
        * Let a PHPWorker handle a request
        *
        * @param HTTPRequest $request
        * @return int SharedMem-key
        */
        static public function handleRequest(HTTPRequest $request) {
            if(!($key = SharedMemory::put($request)))
                return false;
            if(!IPC::send(PHP_WORKER_TYPE.$request->getvHost()->getID(), $key))
                return false;
            return $key;
        }
    }
?>