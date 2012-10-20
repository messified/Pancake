<?php
  
    /****************************************************************/
    /* Pancake                                                      */
    /* requestWorker.thread.php                                     */
    /* 2012 Yussuf Khalil                                           */
    /* License: http://pancakehttp.net/license/                     */
    /****************************************************************/
    
    namespace Pancake;

    #.if 0
    if(PANCAKE !== true)
        exit;
   	#.endif

    #.mapVariable '$Pancake_sockets' '$Pancake_sockets'
    #.mapVariable '$Pancake_vHosts' '$Pancake_vHosts'
    #.mapVariable '$Pancake_currentThread' '$Pancake_currentThread'
    #.mapVariable '$message' '$message'
    #.mapVariable '$code' '$code'
    #.mapVariable '$vHost' '$vHost'
    
    #.if /* .eval 'return (bool) Pancake\Config::get("main.secureports");' false */ && 0
    	#.define 'SUPPORT_TLS' true
    #.endif
    
    #.if /* .eval 'return (bool) Pancake\Config::get("fastcgi");' false */
    	#.define 'SUPPORT_FASTCGI' true
    #.endif
    
    #.if /* .eval 'global $Pancake_vHosts; foreach($Pancake_vHosts as $vHost) if($vHost->phpWorkers) return true; return false;' false */
    	#.define 'SUPPORT_PHP' true
    #.endif
    
    #.if /* .eval 'global $Pancake_vHosts; foreach($Pancake_vHosts as $vHost) if($vHost->allowGZIP) return true; return false;' false */
    	#.define 'SUPPORT_GZIP' true
    #.endif
    
    #.if /* .eval 'global $Pancake_vHosts; foreach($Pancake_vHosts as $vHost) if($vHost->authFiles || $vHost->authDirectories) return true; return false;' false */
    	#.define 'SUPPORT_AUTHENTICATION' true
    #.endif
    
    #.if /* .eval 'global $Pancake_vHosts; foreach($Pancake_vHosts as $vHost) if($vHost->rewriteRules) return true; return false;' false */
    	#.define 'SUPPORT_REWRITE' true
    #.endif
    
    #.if #.config 'compressvariables'
    	#.config 'compressvariables' false
    #.endif
    
    #.if #.config 'compressproperties'
    	#.config 'compressproperties' false
    #.endif
    
    #.if /* .eval 'return count(Pancake\Config::get("vhosts"));' false */ > 1
    	#.define 'SUPPORT_MULTIPLE_VHOSTS' true
    #.endif
    
    #.macro 'REQUEST_TYPE' '$requestObject->requestType'
    #.macro 'GET_PARAMS' '$requestObject->getGETParams()'
    #.macro 'MIME_TYPE' '$requestObject->mimeType'
    #.macro 'VHOST' '$requestObject->vHost'
    #.macro 'REQUEST_FILE_PATH' '$requestObject->requestFilePath'
    #.macro 'RANGE_FROM' '$requestObject->rangeFrom'
    #.macro 'RANGE_TO' '$requestObject->rangeTo'
    #.macro 'BUILD_ANSWER_HEADERS' '$requestObject->buildAnswerHeaders()'
    #.macro 'ANSWER_BODY' '$requestObject->answerBody'
    #.macro 'REMOTE_IP' '$requestObject->remoteIP'
    #.macro 'REMOTE_PORT' '$requestObject->remotePort'
    #.macro 'REQUEST_LINE' '$requestObject->requestLine'
    #.macro 'ANSWER_CODE' '$requestObject->answerCode'
    #.macro 'UPLOADED_FILES' '$requestObject->uploadedFiles'
    #.macro 'SIMPLE_GET_REQUEST_HEADER' '(isset($requestObject->requestHeaders[$headerName]) ? $requestObject->requestHeaders[$headerName] : null)' '$headerName'
    #.macro 'QUERY_STRING' '$requestObject->queryString'
    #.macro 'PROTOCOL_VERSION' '$requestObject->protocolVersion'
    #.macro 'REQUEST_URI' '$requestObject->requestURI'
    #.macro 'LOCAL_IP' '$requestObject->localIP'
    #.macro 'LOCAL_PORT' '$requestObject->localPort'
    #.macro 'RAW_POST_DATA' '$requestObject->rawPOSTData'
    #.macro 'VHOST_COMPARE_OBJECTS' '/* .VHOST */->shouldCompareObjects'
    #.macro 'VHOST_FASTCGI' '(isset(/* .VHOST */->fastCGI[/* .MIME_TYPE */]) ? /* .VHOST */->fastCGI[/* .MIME_TYPE */] : null)'
    #.macro 'VHOST_PHP_WORKERS' '/* .VHOST */->phpWorkers'
    #.macro 'VHOST_SOCKET_NAME' '/* .VHOST */->phpSocketName'
    #.macro 'VHOST_DOCUMENT_ROOT' '/* .VHOST */->documentRoot'
    #.macro 'VHOST_DIRECTORY_PAGE_HANDLER' '/* .VHOST */->directoryPageHandler'
    #.macro 'VHOST_ALLOW_GZIP_COMPRESSION' '/* .VHOST */->allowGZIP'
    #.macro 'VHOST_GZIP_MINIMUM' '/* .VHOST */->gzipMinimum'
    #.macro 'VHOST_GZIP_LEVEL' '/* .VHOST */->gzipLevel'
    #.macro 'VHOST_WRITE_LIMIT' '/* .VHOST */->writeLimit'
    #.macro 'VHOST_NAME' '/* .VHOST */->name'
    
    #.if Pancake\DEBUG_MODE === true
    	#.define 'BENCHMARK' false
    #.else
    	#.define 'BENCHMARK' false
    #.endif
    
    global $Pancake_sockets;
    global $Pancake_vHosts;
    
    // Precalculate post_max_size in bytes
    // It is impossible to keep this in a more readable way thanks to the nice Zend Tokenizer
   	#.define 'POST_MAX_SIZE' /* .eval '$size = strtolower(ini_get("post_max_size")); if(strpos($size, "k")) $size = (int) $size * 1024; else if(strpos($size, "m")) $size = (int) $size * 1024 * 1024; else if(strpos($size, "g")) $size = (int) $size * 1024 * 1024 * 1024; return $size;' false */

    #.include 'mime.class.php'
    
    #.ifdef 'SUPPORT_TLS'
    	#.include 'TLSConnection.class.php'
    #.endif
    
    #.ifdef 'SUPPORT_FASTCGI'
    	#.include 'FastCGI.class.php'
    #.endif
    
    #.include 'workerFunctions.php'
    #.include 'HTTPRequest.class.php'
    #.include 'invalidHTTPRequest.exception.php'
    #.include 'vHostInterface.class.php'
    
    vHost::$defaultvHost = null;
    MIME::load();
    
    foreach($Pancake_vHosts as &$vHost) {
    	if($vHost instanceof vHostInterface)
    		break;
    	$vHost = new vHostInterface($vHost);
    	#.ifdef 'SUPPORT_FASTCGI'
    		$vHost->initializeFastCGI();
    	#.endif
    	unset($Pancake_vHosts[$id]);
    	foreach($vHost->listen as $address)
    		$Pancake_vHosts[$address] = $vHost;
    }
    
    Config::workerDestroy();
    
    $listenSockets = $listenSocketsOrig = $Pancake_sockets;
    
    // Initialize some variables
    #.if /* .eval 'return Pancake\Config::get("main.maxconcurrent");' false */
    $decliningNewRequests = false;
    #.endif
    #.ifdef 'SUPPORT_TLS'
    $secureConnection = array();
    #.endif
    #.ifdef 'SUPPORT_FASTCGI'
    $fastCGISockets = array();
    #.endif
    $liveWriteSocketsOrig = array();
    $liveReadSockets = array();
    $socketData = array();
    $postData = array();
    $processedRequests = 0;
    $requests = array();
    $requestFileHandle = array();
    #.ifdef 'SUPPORT_GZIP'
    $gzipPath = array();
    #.endif
    $writeBuffer = array();
    #.ifdef 'SUPPORT_PHP'
    $phpSockets = array();
    #.endif
    $waitSlots = array();
    $waits = array();
    
    #.if BENCHMARK === true
    	benchmarkFunction("socket_read");
    	benchmarkFunction("socket_write");
    	benchmarkFunction("hexdec");
    #.endif
    
    // Ready
    $Pancake_currentThread->parentSignal(/* .constant 'SIGUSR1' */);
    
    // Set user and group
    setUser();
    
    // Wait for incoming requests     
    while(socket_select($listenSockets, $liveWriteSockets, $x
    #.if /* .eval 'return Pancake\Config::get("main.waitslottime");' false */
    , $waitSlots ? 0 : null, $waitSlots ? /* .eval 'return Pancake\Config::get("main.waitslottime");' false */ : null
    #.endif
    ) !== false) {
    	// If there are jobs left in the queue at the end of the job-run, we're going to jump back to this point to execute the jobs that are left
    	cycle:
    	
    	#.ifdef 'SUPPORT_PHP'
    	// Check if there are requests waiting for a PHPWorker
    	foreach((array) $waitSlots as $socketID => $requestSocket) {
			unset($waitSlots[$socketID]);
			$requestObject = $requests[$socketID];
    		goto load;
    	}
    	#.endif
    	
    	// Upload to clients that are ready to receive
        foreach((array) $liveWriteSockets as $index => $requestSocket) {
        	unset($liveWriteSockets[$index]);
        	
        	$socketID = (int) $requestSocket;
        	$requestObject = $requests[$socketID];
            goto liveWrite;
        }
        
        // New connection, downloadable content from a client or the PHP-SAPI finished a request
        foreach($listenSockets as $index => $socket) {
        	unset($listenSockets[$index]);
        	
        	#.ifdef 'SUPPORT_FASTCGI'
        	if(isset($fastCGISockets[(int) $socket])) {
        		$fastCGI = $fastCGISockets[(int) $socket];
        		do {
        			$newData = socket_read($socket, (isset($result) ? ($result & /* .constant 'FCGI_APPEND_DATA' */ ? $result ^ /* .constant 'FCGI_APPEND_DATA' */ : $result) : 8));
        			if(isset($result) && $result & /* .constant 'FCGI_APPEND_DATA' */)
        				$data .= $newData;
        			else
        				$data = $newData;
        			$result = $fastCGI->upstreamRecord($data);
        			if($result === 0) {
        				unset($fastCGISockets[(int) $socket]);
        				unset($listenSocketsOrig[array_search($socket, $listenSocketsOrig)]);
        				goto clean;
        			}
        		} while($result & /* .constant 'FCGI_APPEND_DATA' */);
        		 
        		if(is_array($result)) {
        			list($requestSocket, $requestObject) = $result;
        			$socketID = (int) $requestSocket;
        	
        			unset($result);
        			goto write;
        		}
        		 
        		unset($result);
        		goto clean;
        	}
        	#.endif
        	
            if(isset($liveReadSockets[(int) $socket]) || socket_get_option($socket, /* .constant 'SOL_SOCKET' */, /* .constant 'SO_KEEPALIVE' */)) {
                $socketID = (int) $socket;
                $requestSocket = $socket;
                $requestObject = $requests[$socketID];
                break;
            }
            
            #.ifdef 'SUPPORT_PHP'
            if(isset($phpSockets[(int) $socket])) {
                $requestSocket = $phpSockets[(int) $socket];
                $socketID = (int) $requestSocket;
                $requestObject = $requests[$socketID];
                
                socket_set_block($socket);
                
                $packages = hexdec(socket_read($socket, 8));
                $length = hexdec(socket_read($socket, 8));
                
                if($packages > 1) {
                	$sockData = "";

                	while($packages--)
                		$sockData .= socket_read($socket, $length);
                	
                	$obj = unserialize($sockData);
                	
                	unset($sockData);
                }
                else
                	$obj = unserialize(socket_read($socket, $length));
                
                if($obj instanceof HTTPRequest && !(/* .VHOST_COMPARE_OBJECTS */ && (string) $requestObject != (string) $obj)) {
                	$obj->vHost = $requests[$socketID]->vHost;
                	$requestObject = $requests[$socketID] = $obj;
                } else
                	$requestObject->invalidRequest(new invalidHTTPRequestException('An internal server error occured while trying to handle your request.', 500));
                
                unset($listenSocketsOrig[array_search($socket, $listenSocketsOrig)]);
                unset($phpSockets[(int) $socket]);
                
                socket_close($socket);
                unset($socket);
                unset($obj);
                
                goto write;
            }
            #.endif

            if(
            #.if /* .eval 'return Pancake\Config::get("main.maxconcurrent");' false */ != 0
            /* .eval 'return Pancake\Config::get("main.maxconcurrent");' false */ < count($listenSocketsOrig) - count($Pancake_sockets) || 
            #.endif
            !($requestSocket = @socket_accept($socket)))
                goto clean;
            $socketID = (int) $requestSocket;

            $socketData[$socketID] = "";
            
            #.ifdef 'SUPPORT_TLS'
            	socket_getsockname($requestSocket, $ip, $port);
            	if(in_array($port, Config::get("main.secureports")))
            		$secureConnection[$socketID] = new TLSConnection;
            #.endif
            
            // Set O_NONBLOCK-flag
            socket_set_nonblock($requestSocket);
            break;
        }
        
        // Receive data from client
        if(isset($requests[$socketID]))
            $bytes = @socket_read($requestSocket, /* .SIMPLE_GET_REQUEST_HEADER '"Content-Length"' */ - strlen($postData[$socketID]));
        else
            $bytes = @socket_read($requestSocket, 10240);
        
        // socket_read() might return string(0) "" while the socket keeps at non-blocking state - This happens when the client closes the connection under certain conditions
        // We should not close the socket if socket_read() returns bool(false) - This might lead to problems with slow connections
        if($bytes === "")
            goto close;
        
        #.ifdef 'SUPPORT_TLS'
        	if($secureConnection[$socketID] && strlen($bytes) >= 5) {
        		socket_set_block($requestSocket);
        		socket_write($requestSocket, $secureConnection[$socketID]->data($bytes));
        		goto close;
        	}
        #.endif
        
        // Check if request was already initialized and we are only reading POST-data
        if(isset($requests[$socketID])) {
            $postData[$socketID] .= $bytes;
            if(strlen($postData[$socketID]) >= /* .SIMPLE_GET_REQUEST_HEADER '"Content-Length"' */)
                goto readData;
        } else {
            $socketData[$socketID] .= $bytes;

            // Check if all headers were received
            if(strpos($socketData[$socketID], "\r\n\r\n")) {
                // Check for POST
                if(strpos($socketData[$socketID], "POST") === 0) {
                    $data = explode("\r\n\r\n", $socketData[$socketID], 2);
                    $socketData[$socketID] = $data[0];
                    $postData[$socketID] = $data[1];
                }

                goto readData;
            }
            
            // Avoid memory exhaustion by just sending random but long data that does not contain \r\n\r\n
            // I assume that no normal HTTP-header will be longer than 10 KiB
            if(strlen($socketData[$socketID]) >= 10240)
                goto close;
        }
        // Event-based reading
        if(!in_array($requestSocket, $listenSocketsOrig)) {
            $liveReadSockets[$socketID] = true;
            $listenSocketsOrig[] = $requestSocket;
        }
        goto clean;
        
        readData:
    
        if(!isset($requests[$socketID])) {
            // Get information about client
            socket_getPeerName($requestSocket, $ip, $port);
            
            // Get local IP-address and port
            socket_getSockName($requestSocket, $lip, $lport);
            
            // Create request object / Read Headers
            try {
                $requestObject = $requests[$socketID] = new HTTPRequest($ip, $port, $lip, $lport);
                $requestObject->init($socketData[$socketID]);
                unset($socketData[$socketID]);
            } catch(invalidHTTPRequestException $e) {
                $requestObject->invalidRequest($e);
                goto write;
            }
        }
        
        // Check for POST and get all POST-data
        if(/* .REQUEST_TYPE */ == 'POST') {
            if(strlen($postData[$socketID]) >= /* .SIMPLE_GET_REQUEST_HEADER '"Content-Length"' */) {
                if(strlen($postData[$socketID]) > /* .SIMPLE_GET_REQUEST_HEADER '"Content-Length"' */)
                    $postData[$socketID] = substr($postData[$socketID], 0, /* .SIMPLE_GET_REQUEST_HEADER '"Content-Length"' */);
                if($key = array_search($requestSocket, $listenSocketsOrig))
                    unset($listenSocketsOrig[$key]);
                /* .RAW_POST_DATA */ = $postData[$socketID];
                unset($postData[$socketID]);
            } else {
                // Event-based reading
                if(!in_array($requestSocket, $listenSocketsOrig)) {
                    $liveReadSockets[$socketID] = true;
                    $listenSocketsOrig[] = $requestSocket;
                }
                goto clean;
            }
        } else if($key = array_search($requestSocket, $listenSocketsOrig))
            unset($listenSocketsOrig[$key]);
        
        #.if /* .eval 'return Pancake\Config::get("main.allowtrace");' false */
	        if(/* .REQUEST_TYPE */ == 'TRACE')
	            goto write;
	    #.endif
        
	    #.if /* .eval 'return Pancake\Config::get("main.allowoptions");' false */
	        // Check for "OPTIONS"-requestmethod
	        if(/* .REQUEST_TYPE */ == 'OPTIONS')
	            $requestObject->setHeader('Allow', 
	            /* .eval '$allow = "GET, POST, OPTIONS";
	            if(Pancake\Config::get("main.allowhead") === true)
	                $allow .= ", HEAD";
	            if(Pancake\Config::get("main.allowtrace") === true)
	                $allow .= ", TRACE";
	            return $allow;' false
	             */);
	    #.endif
        
        // Output debug information
        #.if Pancake\DEBUG_MODE === true
        if(array_key_exists('pancakedebug', /* .GET_PARAMS */)) {
            $requestObject->setHeader('Content-Type', 'text/plain');
                                                    
            $body = 'Received Headers:' . "\r\n";
            $body .= /* .REQUEST_LINE */ . "\r\n";
            $body .= $requestObject->getRequestHeaders() . "\r\n";
            $body .= 'Received POST content:' . "\r\n";
            $body .= $postData[$socketID] . "\r\n\r\n";
            $body .= 'Dump of RequestObject:' . "\r\n";
            $body .= print_r($requestObject, true);
            /* .ANSWER_BODY */ = $body;
            
            goto write;
        }
        #.endif
        
        #.if /* .eval 'return ini_get("expose_php");' false */
        if(array_key_exists("", /* .GET_PARAMS */)) {
            $_GET = /* .GET_PARAMS */;
            switch($_GET[""]) {
                case 'PHPE9568F34-D428-11d2-A769-00AA001ACF42':
                    $logo = file_get_contents('logo/php.gif');
                    $requestObject->setHeader('Content-Type', 'image/gif');
                break;
                case 'PHPE9568F35-D428-11d2-A769-00AA001ACF42':
                    $logo = file_get_contents('logo/zend.gif');
                    $requestObject->setHeader('Content-Type', 'image/gif');
                break;
                case 'PHPE9568F36-D428-11d2-A769-00AA001ACF42':
                    $logo = file_get_contents('logo/php_egg.gif');
                    $requestObject->setHeader('Content-Type', 'image/gif');
                break;
                case 'PHPB8B5F2A0-3C92-11d3-A3A9-4C7B08C10000':
                    ob_start();
                    phpcredits();
                    $requests[$socketID]->setHeader('Content-Type', 'text/html');
                    $logo = ob_get_contents();
                    PHPFunctions\OutputBuffering\endClean();
                break;
                #.if /* .eval 'return Pancake\Config::get("main.exposepancake");' false */ === true
                case 'PAN8DF095AE-6639-4C6F-8831-5AB8FBD64D8B':
                    $logo = file_get_contents('logo/pancake.png');
                    $requestObject->setHeader('Content-Type', 'image/png');
                    break;
          		#.endif
                default:
                    goto load;
            }
            /* .ANSWER_BODY */ = $logo;
            unset($logo);
            unset($_GET);
            goto write;
        }
        #.endif
        
        load:
        
        #.ifdef 'SUPPORT_FASTCGI'
        	// FastCGI
        	if($fastCGI = /* .VHOST_FASTCGI */) {
        		if($fastCGI->makeRequest($requestObject, $requestSocket) === false)
        			goto write;
        		if(!in_array($fastCGI->socket, $listenSocketsOrig)) {
        			$listenSocketsOrig[] = $fastCGI->socket;
        			$fastCGISockets[(int) $fastCGI->socket] = $fastCGI;
        		}
        		goto clean;
        	}
        #.endif
        
       	#.ifdef 'SUPPORT_PHP'
        // Check for PHP
        if(/* .MIME_TYPE */ == 'text/x-php' && /* .VHOST_PHP_WORKERS */) {
            $socket = socket_create(/* .constant 'AF_UNIX' */, /* .constant 'SOCK_SEQPACKET' */, 0);
            socket_set_nonblock($socket);
            // @ - Do not spam errorlog with Resource temporarily unavailable if there is no PHPWorker available
            @socket_connect($socket, /* .VHOST_SOCKET_NAME */);
            
            if(socket_last_error($socket) == 11) {
            	#.if /* .eval 'return Pancake\Config::get("main.waitslotwaitlimit");' false */
	      			$waits[$socketID]++;
	            	
	            	if($waits[$socketID] > /* .eval 'return Pancake\Config::get("main.waitslotwaitlimit");' false */) {
	            		$requestObject->invalidRequest(new invalidHTTPRequestException('There was no worker available to serve your request. Please try again later.', 500));
	            		goto write;
	            	}	
	            	
	            	$waitSlotsOrig[$socketID] = $requestSocket;
	            	
	            	goto clean;
	        	#.else
	            	$requestObject->invalidRequest(new invalidHTTPRequestException('There was no worker available to serve your request. Please try again later.', 500));
	            	goto write;
	           	#.endif
            }
            
            unset($waitSlotsOrig[$socketID]);
            unset($waits[$socketID]);
            
            $data = serialize($requestObject);
            
            $packages = array();
            
            if(strlen($data) > (socket_get_option($socket, /* .constant "SOL_SOCKET" */, /* .constant "SO_SNDBUF" */) - 1024)
            && (socket_set_option($socket, /* .constant "SOL_SOCKET" */, /* .constant "SO_SNDBUF" */, strlen($data) + 1024) + 1)
            && strlen($data) > (socket_get_option($socket, /* .constant "SOL_SOCKET" */, /* .constant "SO_SNDBUF" */) - 1024)) {
            	$packageSize = socket_get_option($socket, /* .constant "SOL_SOCKET" */, /* .constant "SO_SNDBUF" */) - 1024;
            
            	for($i = 0;$i < ceil(strlen($data) / $packageSize);$i++)
            		$packages[] = substr($data, $i * $packageSize, $packageSize);
            } else
            		$packages[] = $data;
            		 
            // First transmit the length of the serialized object, then the object itself
            socket_write($socket, dechex(count($packages)));
            socket_write($socket, dechex(strlen($packages[0])));
            foreach($packages as $data)
            	socket_write($socket, $data);
            
            unset($packages);
            
            $listenSocketsOrig[] = $socket;
            $phpSockets[(int) $socket] = $requestSocket;
            
            goto clean;
        }
        #.endif
        
        // Get time of last modification
        $modified = filemtime(/* .VHOST_DOCUMENT_ROOT */ . /* .REQUEST_FILE_PATH */);
        // Set Last-Modified-Header as RFC 2822
        $requestObject->setHeader('Last-Modified', date('r', $modified));
        
        // Check for If-Modified-Since
        if(strtotime(/* .SIMPLE_GET_REQUEST_HEADER '"If-Modified-Since"' */) == $modified) {
        	/* .ANSWER_CODE */ = 304;
            goto write;
        }
        
        // Check for directory
        if(is_dir(/* .VHOST_DOCUMENT_ROOT */ . /* .REQUEST_FILE_PATH */)) {
            $directory = scandir(/* .VHOST_DOCUMENT_ROOT */ . /* .REQUEST_FILE_PATH */);
            $files = array();
            
            foreach($directory as $file) {
            	if($file == '.')
            		continue;
            	$isDir = is_dir(/* .VHOST_DOCUMENT_ROOT */ . /* .REQUEST_FILE_PATH*/ . $file);
            	$files[] =
            	array('name' => $file,
            			'address' => 'http://' . /* .SIMPLE_GET_REQUEST_HEADER '"Host"' */ . /* .REQUEST_FILE_PATH*/ . $file . ($isDir ? '/' : ''),
            			'directory' => $isDir,
            			'type' => MIME::typeOf($file),
            			'modified' => filemtime(/* .VHOST_DOCUMENT_ROOT */ .  /* .REQUEST_FILE_PATH*/ . $file),
            			'size' => filesize(/* .VHOST_DOCUMENT_ROOT */ . /* .REQUEST_FILE_PATH*/ . $file));
            }
            
            $requestObject->setHeader('Content-Type', 'text/html; charset=utf-8');
            
            ob_start();
            
            if(!include(/* .VHOST_DIRECTORY_PAGE_HANDLER */))
            	include 'php/directoryPageHandler.php';
             
            /* .ANSWER_BODY */ = ob_get_clean();
        } else {
            $requestObject->setHeader('Content-Type', /* .MIME_TYPE */); 
            $requestObject->setHeader('Accept-Ranges', 'bytes'); 
            
            #.ifdef 'SUPPORT_GZIP'
            // Check if GZIP-compression should be used  
            if($requestObject->acceptsCompression('gzip') && /* .VHOST_ALLOW_GZIP_COMPRESSION */ === true && filesize(/* .VHOST_DOCUMENT_ROOT */ . /* .REQUEST_FILE_PATH */) >= /* .VHOST_GZIP_MINIMUM */) {
                // Set encoding-header
                $requestObject->setHeader('Content-Encoding', 'gzip');
                // Create temporary file
                $gzipPath[$socketID] = tempnam(/* .eval 'return Pancake\Config::get("main.tmppath");' false */, 'GZIP');
                $gzipFileHandle = gzopen($gzipPath[$socketID], 'w' . /* .VHOST_GZIP_LEVEL */);
                // Load uncompressed requested file
                $requestedFileHandle = fopen(/* .VHOST_DOCUMENT_ROOT */ . /* .REQUEST_FILE_PATH */, 'r');
                // Compress file
                while(!feof($requestedFileHandle))
                    gzwrite($gzipFileHandle, fread($requestedFileHandle, /* .VHOST_WRITE_LIMIT */));
                // Close GZIP-resource and open normal file-resource
                gzclose($gzipFileHandle);
                $requestFileHandle[$socketID] = fopen($gzipPath[$socketID], 'r');
                // Set Content-Length
                $requestObject->setHeader('Content-Length', filesize($gzipPath[$socketID]) - /* .RANGE_FROM */);
            } else {
            #.endif
                $requestObject->setHeader('Content-Length', filesize(/* .VHOST_DOCUMENT_ROOT */ . /* .REQUEST_FILE_PATH */) - /* .RANGE_FROM */);
                $requestFileHandle[$socketID] = fopen(/* .VHOST_DOCUMENT_ROOT */ . /* .REQUEST_FILE_PATH */, 'r');
            #.ifdef 'SUPPORT_GZIP'
            }
            #.endif
            
            // Check if a specific range was requested
            if(/* .RANGE_FROM */) {
                /* .ANSWER_CODE */ = 206;
                fseek($requestFileHandle[$socketID], /* .RANGE_FROM */);
                #.ifdef 'SUPPORT_GZIP'
                if($gzipPath[$socketID])
                    $requestObject->setHeader('Content-Range', 'bytes ' . /* .RANGE_FROM */.'-'.(filesize($gzipPath[$socketID]) - 1).'/'.filesize($gzipPath[$socketID]));
                else
                #.endif
                    $requestObject->setHeader('Content-Range', 'bytes ' . /* .RANGE_FROM */.'-'.(filesize(/* .VHOST_DOCUMENT_ROOT */ . /* .REQUEST_FILE_PATH */) - 1).'/'.filesize(/* .VHOST_DOCUMENT_ROOT */ . /* .REQUEST_FILE_PATH */));
            }
        }
        
        write:

        // Get Answer Headers
        $writeBuffer[$socketID] = /* .BUILD_ANSWER_HEADERS */;

        #.if /* .eval 'return Pancake\Config::get("main.allowhead");' false */
	        // Get answer body if set and request method isn't HEAD
	        if(/* .REQUEST_TYPE */ != 'HEAD')
	    #.endif
	    $writeBuffer[$socketID] .= /* .ANSWER_BODY */;
	        
        // Output request information
        out('REQ './* .ANSWER_CODE */.' './* .REMOTE_IP */.': './* .REQUEST_LINE */.' on vHost '.((/* .VHOST */) ? /* .VHOST_NAME */ : null).' (via './* .SIMPLE_GET_REQUEST_HEADER '"Host"' */.' from './* .SIMPLE_GET_REQUEST_HEADER "'Referer'" */.') - './* .SIMPLE_GET_REQUEST_HEADER '"User-Agent"' */, /* .constant 'Pancake\REQUEST' */);

        // Check if user wants keep-alive connection
        if($requestObject->getAnswerHeader('Connection') == 'keep-alive')
            socket_set_option($requestSocket, /* .constant 'SOL_SOCKET' */, /* .constant 'SO_KEEPALIVE' */, 1);

        // Increment amount of processed requests
        $processedRequests++;
        
        // Clean some data now to improve RAM usage
        unset($socketData[$socketID]);
        unset($postData[$socketID]);
        unset($liveReadSockets[$socketID]);

        liveWrite:
        
        // The buffer should usually only be empty if the hard limit was reached - In this case Pancake won't allocate any buffers except when the client really IS ready to receive data
        if(!strlen($writeBuffer[$socketID]))
        	$writeBuffer[$socketID] = fread($requestFileHandle[$socketID], /* .eval 'return Pancake\Config::get("main.writebuffermin");' false */);
        
        // Write data to socket
        if(($writtenLength = @socket_write($requestSocket, $writeBuffer[$socketID])) === false)
            goto close;
        // Remove written data from buffer
        $writeBuffer[$socketID] = substr($writeBuffer[$socketID], $writtenLength);
        
        // Add data to buffer if not all data was sent yet
        if(strlen($writeBuffer[$socketID]) < /* .eval 'return Pancake\Config::get("main.writebuffermin");' false */ 
        && is_resource($requestFileHandle[$socketID]) 
        && !feof($requestFileHandle[$socketID]) 
        #.if /* .eval 'return Pancake\Config::get("main.writebufferhardmaxconcurrent");' false */
        && count($writeBuffer) < /* .eval 'return Pancake\Config::get("main.writebufferhardmaxconcurrent");' false */
        #.endif
        #.if /* .eval 'return Pancake\Config::get("main.allowhead");' */
        && /* .REQUEST_TYPE */ != 'HEAD' 
       	#.endif
        && $writtenLength)
        	$writeBuffer[$socketID] .= fread($requestFileHandle[$socketID], 
        			#.if /* .eval 'return Pancake\Config::get("main.writebuffersoftmaxconcurrent");' false */
        			(count($writeBuffer) > /* .eval 'return Pancake\Config::get("main.writebuffersoftmaxconcurrent");' false */ ? /* .eval 'return Pancake\Config::get("main.writebuffermin");' false */ : /* .VHOST_WRITE_LIMIT */)
					#.else
        			#.VHOST_WRITE_LIMIT
        			#.endif
        			- strlen($writeBuffer[$socketID]));

        // Check if more data is available
        if(strlen($writeBuffer[$socketID]) || (is_resource($requestFileHandle[$socketID]) && !feof($requestFileHandle[$socketID])
		#.if /* .eval 'return Pancake\Config::get("main.allowhead");' false */
        && /* .REQUEST_TYPE */ != 'HEAD'
        #.endif
        )) {
            // Event-based writing - In the time the client is still downloading we can process other requests
            if(!@in_array($requestSocket, $liveWriteSocketsOrig))
                $liveWriteSocketsOrig[] = $requestSocket;
            goto clean;
        }

        close:

        // Close socket
        if(!isset($requests[$socketID]) || $requestObject->getAnswerHeader('Connection') != 'keep-alive') {
            @socket_shutdown($requestSocket);
            socket_close($requestSocket);

            if($key = array_search($requestSocket, $listenSocketsOrig))
                unset($listenSocketsOrig[$key]);
        }
        
        if(isset($requests[$socketID])) {
            if(!in_array($requestSocket, $listenSocketsOrig, true) && $requestObject->getAnswerHeader('Connection') == 'keep-alive')
                $listenSocketsOrig[] = $requestSocket;
               
            foreach((array) /* .UPLOADED_FILES */ as $file)
                @unlink($file['tmp_name']); 
        }
        
        #.ifdef 'SUPPORT_PHP'
        unset($waitSlotsOrig[$socketID]);
        unset($waits[$socketID]);
        #.endif
        unset($socketData[$socketID]);
        unset($postData[$socketID]);
        unset($liveReadSockets[$socketID]);
        unset($requests[$socketID]);
        unset($writeBuffer[$socketID]);
        #.ifdef 'SUPPORT_GZIP'
        if(isset($gzipPath[$socketID])) {
            unlink($gzipPath[$socketID]);
            unset($gzipPath[$socketID]);
        }
        #.endif
        
        if(($key = array_search($requestSocket, $liveWriteSocketsOrig)) !== false)
            unset($liveWriteSocketsOrig[$key]);
        
        if(is_resource($requestFileHandle[$socketID])) {
            fclose($requestFileHandle[$socketID]);
            unset($requestFileHandle[$socketID]);
        }
        
        #.if Pancake\DEBUG_MODE === true
        if($results = benchmarkFunction(null, true)) {
        	foreach($results as $function => $functionResults) {
        		foreach($functionResults as $result)
        			$total += $result;
        
        		out('Benchmark of function ' . $function . '(): ' . count($functionResults) . ' calls' . ( $functionResults ? ' - ' . (min($functionResults) * 1000) . ' ms min - ' . ($total / count($functionResults) * 1000) . ' ms ave - ' . (max($functionResults) * 1000) . ' ms max - ' . ($total * 1000) . ' ms total' : "") , /* .constant 'Pancake\REQUEST' */);
        		unset($total);
        	}
        	 
        	unset($result);
        	unset($functionResults);
        	unset($results);
        }
        #.endif
        
        // Check if request-limit is reached
        #.if /* .eval 'return Pancake\Config::get("main.requestworkerlimit");' false */ > 0
        if($processedRequests >= /* .eval 'return Pancake\Config::get("main.requestworkerlimit");' false */ && !$socketData && !$postData && !$requests) {
            IPC::send(9999, 1);
            exit;
        }
        #.endif
        
        clean:
        
        #.if /* .eval 'return Pancake\Config::get("main.maxconcurrent");' false */
        if($decliningNewRequests && /* .eval 'return Pancake\Config::get("main.maxconcurrent");' false */ > count($listenSocketsOrig))
            $listenSocketsOrig = array_merge($Pancake_sockets, $listenSocketsOrig);
        
        if(/* .eval 'return Pancake\Config::get("main.maxconcurrent");' false */ < count($listenSocketsOrig) - count($Pancake_sockets)) {
            foreach($Pancake_sockets as $index => $socket)
                unset($listenSocketsOrig[$index]);
            $decliningNewRequests = true;
        }
        #.endif
        
        // Clean old request-data
        unset($data);
        unset($bytes);
        unset($sentTotal);
        unset($answer);
        unset($body);
        unset($directory);
        unset($requestSocket);
        unset($requestObject);
        unset($socket);
        unset($add);
        unset($continue);
        unset($index);
        
        // If jobs are waiting, execute them before select()ing again
        if($listenSockets || $liveWriteSockets
		#.ifdef 'SUPPORT_PHP'
        || $waitSlots
        #.endif
        )
        	goto cycle;
        
        $listenSockets = $listenSocketsOrig;
        $liveWriteSockets = $liveWriteSocketsOrig;
        #.ifdef 'SUPPORT_PHP'
        $waitSlots = $waitSlotsOrig;
        #.endif
        
        gc_collect_cycles();
        
        // Reset statcache
        clearstatcache();
    }
?>
