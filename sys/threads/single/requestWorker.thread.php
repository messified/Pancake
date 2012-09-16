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
    
    #.macro 'REQUEST_TYPE' '$requestObject->requestType'
    #.macro 'GET_PARAMS' '$requestObject->GETParameters'
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
    
    global $Pancake_sockets;
    global $Pancake_vHosts;
    
    // Precalculate post_max_size in bytes
    // It is impossible to keep this in a more readable way thanks to the nice Zend Tokenizer
   	#.define 'POST_MAX_SIZE' /* .eval '$size = strtolower(ini_get("post_max_size")); if(strpos($size, "k")) $size = (int) $size * 1024; else if(strpos($size, "m")) $size = (int) $size * 1024 * 1024; else if(strpos($size, "g")) $size = (int) $size * 1024 * 1024 * 1024; return $size;' */

    #.include 'invalidHTTPRequest.exception.php'
    #.include 'mime.class.php'
    
    // Do not rename properties in HTTPRequest class as the properties in the PHPWorkers will have different names
    #.if /* .config 'compressproperties' */
    	#.config 'compressproperties' false
    #.endif
    #.include 'HTTPRequest.class.php'
    
    MIME::load();
    
    $listenSockets = $listenSocketsOrig = $Pancake_sockets;
    
    // Initialize some variables
    #.if /* .eval 'return Pancake\Config::get("main.maxconcurrent");' */
    $decliningNewRequests = false;
    #.endif
    $liveWriteSocketsOrig = array();
    $liveReadSockets = array();
    $socketData = array();
    $postData = array();
    $processedRequests = 0;
    $requests = array();
    $requestFileHandle = array();
    $gzipPath = array();
    $writeBuffer = array();
    $phpSockets = array();
    $waitSlots = array();
    $waits = array();

    // Ready
    $Pancake_currentThread->parentSignal(/* .constant 'SIGUSR1' */);
    
    // Set user and group
    setUser();
    
    // Wait for incoming requests     
    while(socket_select($listenSockets, $liveWriteSockets, $x
    #.if /* .eval 'return Pancake\Config::get("main.waitslottime");' */
    , $waitSlots ? 0 : null, $waitSlots ? /* .eval 'return Pancake\Config::get("main.waitslottime");' */ : null
    #.endif
    ) !== false) {
    	// If there are jobs left in the queue at the end of the job-run, we're going to jump back to this point to execute the jobs that are left
    	cycle:
    	
    	// Check if there are requests waiting for a PHPWorker
    	foreach((array) $waitSlots as $socketID => $requestSocket) {
			unset($waitSlots[$socketID]);
			$requestObject = $requests[$socketID];
    		goto load;
    	}
    	
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
        	
            if(isset($liveReadSockets[(int) $socket]) || socket_get_option($socket, /* .constant 'SOL_SOCKET' */, /* .constant 'SO_KEEPALIVE' */)) {
                $socketID = (int) $socket;
                $requestSocket = $socket;
                $requestObject = $requests[$socketID];
                break;
            }
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
                
                if($obj instanceof HTTPRequest && !(/* .VHOST */->shouldCompareObjects() && (string) $requestObject != (string) $obj))
                	$requestObject = $requests[$socketID] = $obj;
                else
                	$requestObject->invalidRequest(new invalidHTTPRequestException('An internal server error occured while trying to handle your request.', 500));
                
                unset($listenSocketsOrig[array_search($socket, $listenSocketsOrig)]);
                unset($phpSockets[(int) $socket]);
                
                socket_close($socket);
                unset($socket);
                unset($obj);
                
                goto write;
            }

            if(
            #.if /* .eval 'return Pancake\Config::get("main.maxconcurrent");' */ != 0
            /* .eval 'return Pancake\Config::get("main.maxconcurrent");' */ < count($listenSocketsOrig) - count($Pancake_sockets) || 
            #.endif
            !($requestSocket = @socket_accept($socket)))
                goto clean;
            $socketID = (int) $requestSocket;

            $socketData[$socketID] = "";
            
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
                $requests[$socketID]->readPOSTData($postData[$socketID]);
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
        
        #.if /* .eval 'return Pancake\Config::get("main.allowtrace");' */
	        if(/* .REQUEST_TYPE */ == 'TRACE')
	            goto write;
	    #.endif
        
	    #.if /* .eval 'return Pancake\Config::get("main.allowoptions");' */
	        // Check for "OPTIONS"-requestmethod
	        if(/* .REQUEST_TYPE */ == 'OPTIONS')
	            $requests[$socketID]->setHeader('Allow', 
	            /* .eval '$allow = "GET, POST, OPTIONS";
	            if(Pancake\Config::get("main.allowhead") === true)
	                $allow .= ", HEAD";
	            if(Pancake\Config::get("main.allowtrace") === true)
	                $allow .= ", TRACE";
	            return $allow;'
	             */);
	    #.endif
        
        // Output debug information
        #.if Pancake\DEBUG_MODE === true
        if(array_key_exists('pancakedebug', /* .GET_PARAMS */)) {
            $requests[$socketID]->setHeader('Content-Type', 'text/plain');
                                                    
            $body = 'Received Headers:'."\r\n";
            $body .= $requests[$socketID]->getRequestLine()."\r\n";
            $body .= $requests[$socketID]->getRequestHeaders()."\r\n";
            $body .= 'Received POST content:'."\r\n";
            $body .= $postData[$socketID] . "\r\n\r\n";
            $body .= 'Dump of RequestObject:'."\r\n";
            $body .= print_r($requests[$socketID], true);
            $requests[$socketID]->setAnswerBody($body);
            
            goto write;
        }
        #.endif
        
        #.if /* .eval 'return ini_get("expose_php");' */
        if(array_key_exists("", /* .GET_PARAMS */)) {
            $_GET = /* .GET_PARAMS */;
            switch($_GET[""]) {
                case 'PHPE9568F34-D428-11d2-A769-00AA001ACF42':
                    $logo = file_get_contents('logo/php.gif');
                    $requests[$socketID]->setHeader('Content-Type', 'image/gif');
                break;
                case 'PHPE9568F35-D428-11d2-A769-00AA001ACF42':
                    $logo = file_get_contents('logo/zend.gif');
                    $requests[$socketID]->setHeader('Content-Type', 'image/gif');
                break;
                case 'PHPE9568F36-D428-11d2-A769-00AA001ACF42':
                    $logo = file_get_contents('logo/php_egg.gif');
                    $requests[$socketID]->setHeader('Content-Type', 'image/gif');
                break;
                case 'PHPB8B5F2A0-3C92-11d3-A3A9-4C7B08C10000':
                    ob_start();
                    phpcredits();
                    $requests[$socketID]->setHeader('Content-Type', 'text/html');
                    $logo = ob_get_contents();
                    PHPFunctions\OutputBuffering\endClean();
                break;
                #.if /* .eval 'return Pancake\Config::get("main.exposepancake");' */ === true
                case 'PAN8DF095AE-6639-4C6F-8831-5AB8FBD64D8B':
                    $logo = file_get_contents('logo/pancake.png');
                    $requests[$socketID]->setHeader('Content-Type', 'image/png');
                    break;
          		#.endif
                default:
                    goto load;
            }
            $requests[$socketID]->setAnswerBody($logo);
            unset($logo);
            unset($_GET);
            goto write;
        }
        #.endif
        
        load:
        
        // Check for PHP
        if(/* .MIME_TYPE */ == 'text/x-php' && /* .VHOST */->getPHPWorkerAmount()) {
            $socket = socket_create(/* .constant 'AF_UNIX' */, /* .constant 'SOCK_SEQPACKET' */, 0);
            socket_set_nonblock($socket);
            // @ - Do not spam errorlog with Resource temporarily unavailable if there is no PHPWorker available
            @socket_connect($socket, /* .VHOST */->getSocketName());
            
            if(socket_last_error($socket) == 11) {
            	#.if /* .eval 'return Pancake\Config::get("main.waitslotwaitlimit");' */
	      			$waits[$socketID]++;
	            	
	            	if($waits[$socketID] > /* .eval 'return Pancake\Config::get("main.waitslotwaitlimit");' */) {
	            		$requests[$socketID]->invalidRequest(new invalidHTTPRequestException('There was no worker available to serve your request. Please try again later.', 500));
	            		goto write;
	            	}	
	            	
	            	$waitSlotsOrig[$socketID] = $requestSocket;
	            	
	            	goto clean;
	        	#.else
	            	$requests[$socketID]->invalidRequest(new invalidHTTPRequestException('There was no worker available to serve your request. Please try again later.', 500));
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
        
        // Get time of last modification
        $modified = filemtime(/* .VHOST */->getDocumentRoot()./* .REQUEST_FILE_PATH */);
        // Set Last-Modified-Header as RFC 2822
        $requests[$socketID]->setHeader('Last-Modified', date('r', $modified));
        
        // Check for If-Modified-Since
        if(strtotime(/* .SIMPLE_GET_REQUEST_HEADER '"If-Modified-Since"' */) == $modified) {
        	$requests[$socketID]->setAnswerCode(304);
            goto write;
        }
        
        // Check for directory
        if(is_dir(/* .VHOST */->getDocumentRoot()./* .REQUEST_FILE_PATH */)) {
            $directory = scandir(/* .VHOST */->getDocumentRoot(). /* .REQUEST_FILE_PATH */);
            $requests[$socketID]->setHeader('Content-Type', 'text/html; charset=utf-8');
            
            // Build directory listing
            $body =  '<!doctype html>';
            $body .= '<html>';
            $body .= '<head>';
            $body .= '<title>Index of ' . /* .REQUEST_FILE_PATH */ . '</title>';
            $body .= '<style>';
                $body .= 'body{font-family:"Arial"}';
                $body .= 'thead{font-weight:bold}';
                $body .= 'hr{border:1px solid #000}';
            $body .= '</style>';
            $body .= '</head>';
            $body .= '<body>';
            $body .= '<h1>Index of ' . /* .REQUEST_FILE_PATH */ . '</h1>';
            $body .= '<hr/>';
            $body .= '<table>';
                $body .= '<thead><tr>';
                    $body .= '<th>Filename</th>';
                    $body .= '<th>Type</th>';
                    $body .= '<th>Last Modified</th>';
                    $body .= '<th>Size</th>';
                $body .= '</tr></thead>';
                $body .= '<tbody>';
                    $body .= '<tr>';
                        $body .= '<td>';
                            $dirname = dirname(/* .REQUEST_FILE_PATH */);
                            $body .= '<a href="http://' . /* .SIMPLE_GET_REQUEST_HEADER '"Host"' */ . $dirname . '">../</a>';
                        $body .= '</td>';
                        $body .= '<td>';
                            $body .= 'Directory';
                        $body .= '</td>';
                    $body .= '</tr>';
                    foreach($directory as $file) {
                        if($file == '.' 
                        || $file == '..'
                        || !is_readable(/* .VHOST */->getDocumentRoot()./* .REQUEST_FILE_PATH*/.'/'.$file))
                            continue;
                        
                        $body .= '<tr>';
                            $body .= '<td>';
                                if(substr(/* .REQUEST_FILE_PATH*/, -1) != '/') $add = '/';
                                if(is_dir(/* .VHOST */->getDocumentRoot()./* .REQUEST_FILE_PATH*/.'/'.$file))
                                    $body .= '<a href="http://'./* .SIMPLE_GET_REQUEST_HEADER '"Host"' */./* .REQUEST_FILE_PATH*/.$add.$file.'/">'.$file.'/</a>';
                                else
                                    $body .= '<a href="http://'./* .SIMPLE_GET_REQUEST_HEADER '"Host"' */./* .REQUEST_FILE_PATH*/.$add.$file.'">'.$file.'</a>';
                            $body .= '</td>';
                            $body .= '<td>';
                                if(is_dir(/* .VHOST */->getDocumentRoot()./* .REQUEST_FILE_PATH*/.'/'.$file))
                                    $body .= 'Directory';
                                else
                                    $body .= MIME::typeOf(/* .VHOST */->getDocumentRoot() . /* .REQUEST_FILE_PATH*/ . '/' . $file);
                            $body .= '</td>';
                            $body .= '<td>';
                                $body .= date(/* .eval 'return Pancake\Config::get("main.dateformat");' */, filemtime(/* .VHOST */->getDocumentRoot()./* .REQUEST_FILE_PATH*/.'/'.$file));
                            $body .= '</td>';
                            if(!is_dir(/* .VHOST */->getDocumentRoot()./* .REQUEST_FILE_PATH*/.'/'.$file)) {
                                $body .= '<td>';
                                    $body .= formatFilesize(filesize(/* .VHOST */->getDocumentRoot()./* .REQUEST_FILE_PATH*/.'/'.$file));
                                $body .= '</td>';
                            }
                        $body .= '</tr>';
                    }
                $body .= '</tbody>';
            $body .= '</table>';
            
            
            #.if /* .eval 'return Pancake\Config::get("main.exposepancake");' */ === true
                $body .= '<hr/>';
                $body .= /* .eval 'return "Pancake " . Pancake\VERSION;' */;
            #.endif
            
            $body .= '</body>';
            $body .= '</html>';
            $requests[$socketID]->setAnswerBody($body);
        } else {
            $requests[$socketID]->setHeader('Content-Type', /* .MIME_TYPE */); 
            $requests[$socketID]->setHeader('Accept-Ranges', 'bytes'); 
            
            // Check if GZIP-compression should be used  
            if($requests[$socketID]->acceptsCompression('gzip') && /* .VHOST */->allowGZIPCompression() === true && filesize(/* .VHOST */->getDocumentRoot()./* .REQUEST_FILE_PATH */) >= /* .VHOST */->getGZIPMimimum()) {
                // Set encoding-header
                $requests[$socketID]->setHeader('Content-Encoding', 'gzip');
                // Create temporary file
                $gzipPath[$socketID] = tempnam(/* .eval 'return Pancake\Config::get("main.tmppath");' */, 'GZIP');
                $gzipFileHandle = gzopen($gzipPath[$socketID], 'w' . /* .VHOST */->getGZIPLevel());
                // Load uncompressed requested file
                $requestedFileHandle = fopen(/* .VHOST */->getDocumentRoot()./* .REQUEST_FILE_PATH */, 'r');
                // Compress file
                while(!feof($requestedFileHandle))
                    gzwrite($gzipFileHandle, fread($requestedFileHandle, /* .VHOST */->getWriteLimit()));
                // Close GZIP-resource and open normal file-resource
                gzclose($gzipFileHandle);
                $requestFileHandle[$socketID] = fopen($gzipPath[$socketID], 'r');
                // Set Content-Length
                $requests[$socketID]->setHeader('Content-Length', filesize($gzipPath[$socketID]) - /* .RANGE_FROM */);
            } else {
                $requests[$socketID]->setHeader('Content-Length', filesize(/* .VHOST */->getDocumentRoot()./* .REQUEST_FILE_PATH */) - /* .RANGE_FROM */);
                $requestFileHandle[$socketID] = fopen(/* .VHOST */->getDocumentRoot()./* .REQUEST_FILE_PATH */, 'r');
            }
            
            // Check if a specific range was requested
            if(/* .RANGE_FROM */) {
                $requests[$socketID]->setAnswerCode(206);
                fseek($requestFileHandle[$socketID], /* .RANGE_FROM */);
                if($gzipPath[$socketID])
                    $requests[$socketID]->setHeader('Content-Range', 'bytes ' . /* .RANGE_FROM */.'-'.(filesize($gzipPath[$socketID]) - 1).'/'.filesize($gzipPath[$socketID]));
                else
                    $requests[$socketID]->setHeader('Content-Range', 'bytes ' . /* .RANGE_FROM */.'-'.(filesize(/* .VHOST */->getDocumentRoot()./* .REQUEST_FILE_PATH */) - 1).'/'.filesize(/* .VHOST */->getDocumentRoot()./* .REQUEST_FILE_PATH */));
            }
        }
        
        write:

        // Get Answer Headers
        $writeBuffer[$socketID] = /* .BUILD_ANSWER_HEADERS */;

        #.if /* .eval 'return Pancake\Config::get("main.allowhead");' */
	        // Get Answer Body if set and request method isn't HEAD
	        if(/* .REQUEST_TYPE */ != 'HEAD')
	    #.endif
	    $writeBuffer[$socketID] .= /* .ANSWER_BODY */;
	        
        // Output request information
        out('REQ './* .ANSWER_CODE */.' './* .REMOTE_IP */.': './* .REQUEST_LINE */.' on vHost '.((/* .VHOST */) ? /* .VHOST */->getName() : null).' (via './* .SIMPLE_GET_REQUEST_HEADER '"Host"' */.' from './* .SIMPLE_GET_REQUEST_HEADER "'Referer'" */.') - './* .SIMPLE_GET_REQUEST_HEADER '"User-Agent"' */, /* .constant 'Pancake\REQUEST' */);

        // Check if user wants keep-alive connection
        if($requests[$socketID]->getAnswerHeader('Connection') == 'keep-alive')
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
        	$writeBuffer[$socketID] = fread($requestFileHandle[$socketID], /* .eval 'return Pancake\Config::get("main.writebuffermin");' */);
        
        // Write data to socket
        if(($writtenLength = @socket_write($requestSocket, $writeBuffer[$socketID])) === false)
            goto close;
        // Remove written data from buffer
        $writeBuffer[$socketID] = substr($writeBuffer[$socketID], $writtenLength);
        
        // Add data to buffer if not all data was sent yet
        if(strlen($writeBuffer[$socketID]) < /* .eval 'return Pancake\Config::get("main.writebuffermin");' */ 
        && is_resource($requestFileHandle[$socketID]) 
        && !feof($requestFileHandle[$socketID]) 
        #.if /* .eval 'return Pancake\Config::get("main.writebufferhardmaxconcurrent");' */
        && count($writeBuffer) < /* .eval 'return Pancake\Config::get("main.writebufferhardmaxconcurrent");' */
        #.endif
        #.if /* .eval 'return Pancake\Config::get("main.allowhead");' */
        && /* .REQUEST_TYPE */ != 'HEAD' 
       	#.endif
        && $writtenLength)
        	$writeBuffer[$socketID] .= fread($requestFileHandle[$socketID], 
        			#.if /* .eval 'return Pancake\Config::get("main.writebuffersoftmaxconcurrent");' */
        			(count($writeBuffer) > /* .eval 'return Pancake\Config::get("main.writebuffersoftmaxconcurrent");' */ ? /* .eval 'return Pancake\Config::get("main.writebuffermin");' */ : $requests[$socketID]->getvHost()->getWriteLimit())
					#.else
        			/* .VHOST */->getWriteLimit()
        			#.endif
        			- strlen($writeBuffer[$socketID]));

        // Check if more data is available
        if(strlen($writeBuffer[$socketID]) || (is_resource($requestFileHandle[$socketID]) && !feof($requestFileHandle[$socketID])
		#.if /* .eval 'return Pancake\Config::get("main.allowhead");' */
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
        if(!isset($requests[$socketID]) || $requests[$socketID]->getAnswerHeader('Connection') != 'keep-alive') {
            @socket_shutdown($requestSocket);
            socket_close($requestSocket);

            if($key = array_search($requestSocket, $listenSocketsOrig))
                unset($listenSocketsOrig[$key]);
        }
        
        if(isset($requests[$socketID])) {
            if(!in_array($requestSocket, $listenSocketsOrig, true) && $requests[$socketID]->getAnswerHeader('Connection') == 'keep-alive')
                $listenSocketsOrig[] = $requestSocket;
               
            foreach((array) /* .UPLOADED_FILES */ as $file)
                @unlink($file['tmp_name']); 
        }
        
        unset($waitSlotsOrig[$socketID]);
        unset($waits[$socketID]);
        unset($socketData[$socketID]);
        unset($postData[$socketID]);
        unset($liveReadSockets[$socketID]);
        unset($requests[$socketID]);
        unset($writeBuffer[$socketID]);
        if(isset($gzipPath[$socketID])) {
            unlink($gzipPath[$socketID]);
            unset($gzipPath[$socketID]);
        }
        
        if(($key = array_search($requestSocket, $liveWriteSocketsOrig)) !== false)
            unset($liveWriteSocketsOrig[$key]);
        
        if(is_resource($requestFileHandle[$socketID])) {
            fclose($requestFileHandle[$socketID]);
            unset($requestFileHandle[$socketID]);
        }
        
        // Check if request-limit is reached
        #.if /* .eval 'return Pancake\Config::get("main.requestworkerlimit");' */ > 0
        if($processedRequests >= /* .eval 'return Pancake\Config::get("main.requestworkerlimit");' */ && !$socketData && !$postData && !$requests) {
            IPC::send(9999, 1);
            exit;
        }
        #.endif
        
        clean:
        
        #.if /* .eval 'return Pancake\Config::get("main.maxconcurrent");' */
        if($decliningNewRequests && /* .eval 'return Pancake\Config::get("main.maxconcurrent");' */ > count($listenSocketsOrig))
            $listenSocketsOrig = array_merge($Pancake_sockets, $listenSocketsOrig);
        
        if(/* .eval 'return Pancake\Config::get("main.maxconcurrent");' */ < count($listenSocketsOrig) - count($Pancake_sockets)) {
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
        if($listenSockets || $liveWriteSockets || $waitSlots)
        	goto cycle;
        
        $listenSockets = $listenSocketsOrig;
        $liveWriteSockets = $liveWriteSocketsOrig;
        $waitSlots = $waitSlotsOrig;
        
        gc_collect_cycles();
        
        // Reset statcache
        clearstatcache();
    }
?>
