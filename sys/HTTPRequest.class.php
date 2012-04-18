<?php
  
    /****************************************************************/
    /* Pancake                                                      */
    /* HTTPRequest.class.php                                        */
    /* 2012 Yussuf "pp3345" Khalil                                  */
    /* License: http://creativecommons.org/licenses/by-nc-sa/3.0/   */
    /****************************************************************/
    
    if(PANCAKE_HTTP !== true)
        exit;                                                       

    class Pancake_HTTPRequest {
        private $requestHeaders = array();
        private $answerHeaders = array();
        private $protocolVersion = '1.0';
        private $requestType = null;
        private $answerCode = 0;
        private $answerBody = null;
        private $requestFilePath = null;
        private $GETParameters = array();
        private $POSTParameters = array();
        private $cookies = array();
        private $setCookies = array();
        private $requestWorker = null;
        private $vHost = null;
        private $requestLine = null;
        private $rangeFrom = 0;
        private $rangeTo = 0;
        private $acceptedCompressions = array();
        private $requestURI = null;
        private $remoteIP = null;
        private $remotePort = 0;
        private $mimeType = null;
        private $uploadedFiles = array();
        private $uploadedFileTempNames = array();
        private static $answerCodes = array(
                                            100 => 'Continue',
                                            101 => 'Switching Protocols',
                                            102 => 'Processing',
                                            118 => 'Connection timed out',
                                            200 => 'OK',
                                            201 => 'Created',
                                            202 => 'Accepted',
                                            203 => 'Non-Authoritative Information',
                                            204 => 'No Content',
                                            205 => 'Reset Content',
                                            206 => 'Partial Content',
                                            207 => 'Multi-Status',
                                            300 => 'Multiple Choices',
                                            301 => 'Moved Permanently',
                                            302 => 'Found',
                                            303 => 'See Other',
                                            304 => 'Not Modified',
                                            305 => 'Use Proxy',
                                            307 => 'Temporary Redirect',
                                            400 => 'Bad Request',
                                            401 => 'Unauthorized',
                                            402 => 'Payment Required',
                                            403 => 'Forbidden',
                                            404 => 'Not Found',
                                            405 => 'Method Not Allowed',
                                            406 => 'Not Acceptable',
                                            407 => 'Proxy Authentication Required',
                                            408 => 'Request Timeout',
                                            409 => 'Conflict',
                                            410 => 'Gone',
                                            411 => 'Length Required',
                                            412 => 'Precondition Failed',
                                            413 => 'Request Entity Too Large',
                                            414 => 'Request-URI Too Long',
                                            415 => 'Unsupported Media Type',
                                            416 => 'Requested Range Not Satisfiable',
                                            417 => 'Expectation Failed',
                                            418 => 'I\'m a Pancake',
                                            421 => 'There are too many connections from your internet address',
                                            422 => 'Unprocessable Entity',
                                            423 => 'Locked',
                                            424 => 'Failed Dependency',
                                            500 => 'Internal Server Error',
                                            501 => 'Not Implemented',
                                            502 => 'Bad Gateway',
                                            503 => 'Service Unavailable',
                                            504 => 'Gateway Timeout',
                                            505 => 'HTTP Version not supported',
                                            506 => 'Variant Also Negotiates',
                                            507 => 'Insufficient Storage',
                                            509 => 'Bandwith Limit Exceeded',
                                            510 => 'Not Extended');
        
        /**
        * Creates a new HTTPRequest-Object
        * 
        * @param Pancake_RequestWorker $worker
        * @return Pancake_HTTPRequest
        */
        public function __construct(Pancake_RequestWorker $worker, $remoteIP = null, $remotePort = null) {
            $this->requestWorker = $worker;
            $this->remoteIP = $remoteIP;
            $this->remotePort = $remotePort;
            $this->vHost = Pancake_vHost::getDefault();
        }
        
        /**
        * Initialize RequestObject
        *  
        * @param string $requestHeader Headers of the request
        */
        public function init($requestHeader) { 
            try {
                // Split headers from body
                $requestParts = explode("\r\n\r\n", $requestHeader, 2);
                
                // Get single header lines
                $requestHeaders = explode("\r\n", $requestParts[0]);
                
                // Split first line
                $firstLine = explode(" ", $requestHeaders[0]);
                
                $this->requestLine = $requestHeaders[0];
                
                // HyperText CoffeePot Control Protocol :-)
                if(($firstLine[0] == 'BREW' || $firstLine[0] == 'WHEN' || $firstLine[2] == 'HTCPCP/1.0') && Pancake_Config::get('main.exposepancake') === true)
                    throw new Pancake_InvalidHTTPRequestException('It seems like you were trying to make coffee via HTCPCP, but I\'m a Pancake, not a Coffee Pot.', 418, $requestHeader);
                
                // Check request-method
                if($firstLine[0] != 'GET' && $firstLine[0] != 'POST' && $firstLine[0] != 'HEAD' && $firstLine[0] != 'OPTIONS' && $firstLine[0] != 'TRACE')
                    throw new Pancake_InvalidHTTPRequestException('Invalid request-method: '.$firstLine[0], 501, $requestHeader);
                $this->requestType = $firstLine[0];
                
                // Check if request-method is allowed
                if(($this->requestType == 'HEAD'    && Pancake_Config::get('main.allowhead')    !== true)
                || ($this->requestType == 'TRACE'   && Pancake_Config::get('main.allowtrace')   !== true)
                || ($this->requestType == 'OPTIONS' && Pancake_Config::get('main.allowoptions') !== true)) 
                    throw new Pancake_InvalidHTTPRequestException('The request-method you are trying to use is not allowed: '.$this->requestType, 405, $requestHeader); 
                
                // Check protocol version
                if(strtoupper($firstLine[2]) == 'HTTP/1.1')
                    $this->protocolVersion = '1.1';
                else if(strtoupper($firstLine[2]) == 'HTTP/1.0')
                    $this->protocolVersion = '1.0';
                else
                    throw new Pancake_InvalidHTTPRequestException('Unsupported protocol: '.$firstLine[2], 505, $requestHeader);
                unset($requestHeaders[0]);
                
                // Read Headers
                foreach($requestHeaders as $header) {
                    if(trim($header) == null)
                        continue;
                    $header = explode(':', $header, 2);
                    $this->requestHeaders[$header[0]] = trim($header[1]);
                }
                
                // Check if Content-Length is given and not too large on POST
                if($this->requestType == 'POST') {
                    global $Pancake_postMaxSize;
                    
                    if($this->getRequestHeader('Content-Length') > $Pancake_postMaxSize)
                        throw new Pancake_InvalidHTTPRequestException('The uploaded content is too large.', 413, $requestHeader);
                    if($this->getRequestHeader('Content-Length') === null)
                        throw new Pancake_InvalidHTTPRequestException('Your request can\'t be processed without a given Content-Length', 411, $requestHeader);
                } 
                
                // Enough informations for TRACE gathered
                if($this->requestType == 'TRACE')
                    return;
                
                // Check for Host-Header
                if(!$this->getRequestHeader('Host') && $this->protocolVersion == '1.1')
                    throw new Pancake_InvalidHTTPRequestException('Missing required header: Host', 400, $requestHeader);
                
                // Search for vHost
                global $Pancake_vHosts;
                if(isset($Pancake_vHosts[$this->getRequestHeader('Host')]))
                    $this->vHost = $Pancake_vHosts[$this->getRequestHeader('Host')];
                 
                $this->requestURI = $firstLine[1]; 
                 
                // Split address from request-parameters
                $path = explode('?', $firstLine[1], 2);
                $this->requestFilePath = $path[0];
                
                // Check if path begins with /
                if(substr($this->requestFilePath, 0, 1) != '/')
                    $this->requestFilePath = '/' . $this->requestFilePath;
                
                // Do not allow requests to lower paths
                if(strpos($this->requestFilePath, '../'))
                    throw new Pancake_InvalidHTTPRequestException('You are not allowed to open the requested file: '.$this->requestFilePath, 403, $requestHeader);
                
                // Check for index-files    
                if(is_dir($this->vHost->getDocumentRoot().$this->requestFilePath)) {
                    foreach($this->vHost->getIndexFiles() as $file)
                        if(file_exists($this->vHost->getDocumentRoot().$this->requestFilePath.'/'.$file)) {
                            $this->requestFilePath .= (substr($this->requestFilePath, -1, 1) == '/' ? null : '/') . $file;
                            goto checkRead;
                        }
                    // No index file found, check if vHost allows directory listings
                    if($this->vHost->allowDirectoryListings() !== true)
                        throw new Pancake_InvalidHTTPRequestException('You\'re not allowed to view the listing of the requested directory: '.$this->requestFilePath, 403, $requestHeader);
                }

                checkRead:
                
                // Check if requested file exists and is accessible
                if(!file_exists($this->vHost->getDocumentRoot() . $this->requestFilePath))
                    throw new Pancake_InvalidHTTPRequestException('File does not exist: '.$this->requestFilePath, 404, $requestHeader);
                if(!is_readable($this->vHost->getDocumentRoot() . $this->requestFilePath))
                    throw new Pancake_InvalidHTTPRequestException('You\'re not allowed to see the requested file: '.$this->requestFilePath, 403, $requestHeader);
                
                // Check if requested path needs authentication
                if($authData = $this->vHost->requiresAuthentication($this->requestFilePath)) {
                    if($this->getRequestHeader('Authorization')) {
                        if($authData['type'] == 'basic') {
                            $auth = explode(" ", $this->getRequestHeader('Authorization'));
                            $userPassword = explode(":", base64_decode($auth[1]));
                            if($this->vHost->isValidAuthentication($this->requestFilePath, $userPassword[0], $userPassword[1]))
                                goto valid;
                        } else {
                             
                        }
                    }
                    if($authData['type'] == 'basic') {
                        $this->setHeader('WWW-Authenticate', 'Basic realm="'.$authData['realm'].'"');
                        throw new Pancake_InvalidHTTPRequestException('You need to authorize in order to view this file.', 401, $requestHeader);
                    }
                }
                
                valid:
                
                // Check for If-Unmodified-Since
                if($this->getRequestHeader('If-Unmodified-Since')) {
                    if(filemtime($this->vHost->getDocumentRoot().$this->requestFilePath) != strtotime($this->getRequestHeader('If-Unmodified-Since')))
                        throw new Pancake_InvalidHTTPRequestException('File was modified since requested time.', 412, $requestHeader);
                }
                
                // Check for accepted compressions
                if($this->getRequestHeader('Accept-Encoding')) {
                    $accepted = explode(',', $this->getRequestHeader('Accept-Encoding'));
                    foreach($accepted as $format) {
                        $format = strtolower(trim($format));
                        $this->acceptedCompressions[$format] = true;
                    }
                }
                
                // Check for Range-header
                if($this->getRequestHeader('Range')) {
                    preg_match('~([0-9]+)-([0-9]+)?~', $this->getRequestHeader('Range'), $range);
                    $this->rangeFrom = $range[1];
                    $this->rangeTo = $range[2];
                }
                
                // Get MIME-type of the requested file
                $this->mimeType = Pancake_MIME::typeOf($this->vHost->getDocumentRoot() . $this->requestFilePath);
                
                // Split GET-parameters
                $get = explode('&', $path[1]);
                
                // Read GET-parameters
                foreach($get as $param) {
                    if($param == null)
                        break;
                    $param = explode('=', $param, 2);
                    $param[0] = urldecode($param[0]);
                    $param[1] = urldecode($param[1]);
                    
                    if(strpos($param[0], '[') < strpos($param[0], ']')) {
                        preg_match_all('~(.*?)(?:\[([^\]]*)\])~', $param[0], $parts);
                        
                        $paramDefinition = '$this->GETParameters[$parts[1][0]]';
                        foreach((array) $parts[2] as $index => $arrayKey) {
                            if($arrayKey == null)
                                $paramDefinition .= '[]';
                            else
                                $paramDefinition .= '[$parts[2]['.$index.']]';
                        }
                        
                        $paramDefinition .= ' = $param[1];';
                        eval($paramDefinition);
                    } else
                        $this->GETParameters[$param[0]] = $param[1];
                    
                    // GET and POST Parameters can be arrays
                    /*if(substr($param[0], strlen($param[0]) - 2, 2) == '[]') {
                        $param[0] = substr($param[0], 0, strlen($param[0]) - 2);
                        $this->GETParameters[$param[0]][] = $param[1];
                    } else if(substr($param[0], -1, 1) == ']' && $arrayBeginPos = strpos($param[0], '[')) {
                        $indexName = substr($param[0], $arrayBeginPos + 1, strlen($param[0]) - $arrayBeginPos - 2);
                        $param[0] = substr($param[0], 0, $arrayBeginPos);
                        $this->GETParameters[$param[0]][$indexName] = $param[1];
                    } else
                        $this->GETParameters[$param[0]] = $param[1];*/
                    
                }
                 
                // Check for cookies
                if($this->getRequestHeader('Cookie')) {
                    // Split cookies
                    $cookies = explode(';', $this->getRequestHeader('Cookie'));
                    
                    // Read cookies
                    foreach($cookies as $cookie) {
                        if($cookie == null)
                            break;
                            
                        $param = explode('=', trim($cookie), 2);
                        $param[0] = urldecode($param[0]);
                        $param[1] = urldecode($param[1]);
                        
                        if(strpos($param[0], '[') < strpos($param[0], ']')) {
                            preg_match_all('~(.*?)(?:\[([^\]]*)\])~', $param[0], $parts);
                            
                            $paramDefinition = '$this->cookies[$parts[1][0]]';
                            foreach((array) $parts[2] as $index => $arrayKey) {
                                if($arrayKey == null)
                                    $paramDefinition .= '[]';
                                else
                                    $paramDefinition .= '[$parts[2]['.$index.']]';
                            }
                            
                            $paramDefinition .= ' = $param[1];';
                            eval($paramDefinition);
                        } else
                            $this->cookies[$param[0]] = $param[1];
                        
                        /*$cookie = trim($cookie);
                        $cookie = explode('=', $cookie, 2);
                        $this->cookies[urldecode($cookie[0])] = urldecode($cookie[1]);*/
                    }
                }  
            } catch (Pancake_InvalidHTTPRequestException $e) {
                $this->invalidRequest($e);
                throw $e;
            }                          
        }
        
        /**
        * Processes the POST RequestBody
        * 
        * @param string $postData The RequestBody received by the client
        */
        public function readPOSTData($postData) {
            // ~(.*?)(?:\[([^\]]*)\])~
            
            // Check for url-encoded parameters
            if(strpos($this->getRequestHeader('Content-Type'), 'application/x-www-form-urlencoded') !== false) {
                // Split POST-parameters
                $post = explode('&', $postData);

                // Read POST-parameters
                foreach($post as $param) {
                    if($param == null)
                        break;
                    $param = explode('=', $param, 2);
                    $param[0] = urldecode($param[0]);
                    $param[1] = urldecode($param[1]);
                    
                    if(strpos($param[0], '[') < strpos($param[0], ']')) {
                        preg_match_all('~(.*?)(?:\[([^\]]*)\])~', $param[0], $parts);
                        
                        $paramDefinition = '$this->POSTParameters[$parts[1][0]]';
                        foreach((array) $parts[2] as $index => $arrayKey) {
                            if($arrayKey == null)
                                $paramDefinition .= '[]';
                            else
                                $paramDefinition .= '[$parts[2]['.$index.']]';
                        }
                        
                        $paramDefinition .= ' = $param[1];';
                        eval($paramDefinition);
                    } else
                        $this->POSTParameters[$param[0]] = $param[1];
                    
                    /*
                    if(substr($param[0], strlen($param[0]) - 2, 2) == '[]') {
                        $param[0] = substr($param[0], 0, strlen($param[0]) - 2);
                        $this->POSTParameters[$param[0]][] = $param[1];
                    } else if(substr($param[0], -1, 1) == ']' && $arrayBeginPos = strpos($param[0], '[')) {
                        $indexName = substr($param[0], $arrayBeginPos + 1, strlen($param[0]) - $arrayBeginPos - 2);
                        $param[0] = substr($param[0], 0, $arrayBeginPos);
                        $this->POSTParameters[$param[0]][$indexName] = $param[1];
                    } else
                        $this->POSTParameters[$param[0]] = $param[1];*/
                }
            // Check for uploaded files
            } else if(strpos($this->getRequestHeader('Content-Type'), 'multipart/form-data') !== false) {
                // Get boundary string that splits the dispositions
                preg_match('~boundary=(.*)~', $this->getRequestHeader('Content-Type'), $boundary);
                $boundary = $boundary[1];
                if(!$boundary)
                    return false;
                
                // For some strange reason the actual boundary string is -- + the specified boundary string
                $postData = str_replace("\r\n--" . $boundary . '--', null, $postData);
                
                $dispositions = explode("\r\n--" . $boundary, $postData);
                
                // The first disposition will have a boundary string at its beginning
                $disposition[0] = substr($disposition[0], strlen('--' . $boundary . "\r\n"));

                foreach($dispositions as $disposition) {
                    $dispParts = explode("\r\n\r\n", $disposition, 2);
                    preg_match('~Content-Disposition: form-data;[ ]?name="(.*?)";?[ ]?(?:filename="(.*?)")?(?:\r\n)?(?:Content-Type: (.*))?~', $dispParts[0], $data);
                    // [ 0 => string, 1 => name, 2 => filename, 3 => Content-Type ]
                    if(isset($data[2]) && isset($data[3])) {
                        $tmpFileName = tempnam(Pancake_Config::get('main.tmppath'), 'UPL');
                        file_put_contents($tmpFileName, $dispParts[1]);
                        
                        $dataArray = array(
                                            'name' => $data[2],
                                            'type' => $data[3],
                                            'error' => UPLOAD_ERR_OK,
                                            'size' => strlen($dispParts[1]),
                                            'tmp_name' => $tmpFileName);
                        
                        if(strpos($data[1], '[') < strpos($data[1], ']')) {
                            preg_match_all('~(.*?)(?:\[([^\]]*)\])~', $param[1], $parts);
                            
                            $paramDefinition = '$this->uploadedFiles[$parts[1][0]]';
                            foreach((array) $parts[2] as $index => $arrayKey) {
                                if($arrayKey == null)
                                    $paramDefinition .= '[]';
                                else
                                    $paramDefinition .= '[$parts[2]['.$index.']]';
                            }
                            
                            $paramDefinition .= ' = $dataArray;';
                            eval($paramDefinition);
                        } else
                            $this->uploadedFiles[$param[0]] = $dataArray;
                        
                        /*if(substr($data[1], strlen($data[1]) - 2, 2) == '[]') {
                            $data[1] = substr($data[1], 0, strlen($data[1]) - 2);
                            $this->uploadedFiles[$data[1]][] = $dataArray;
                        } else if(substr($data[1], -1, 1) == ']' && $arrayBeginPos = strpos($data[1], '[')) {
                            $indexName = substr($data[1], $arrayBeginPos + 1, strlen($data[1]) - $arrayBeginPos - 2);
                            $data[1] = substr($data[1], 0, $arrayBeginPos);
                            $this->uploadedFiles[$data[1]][$indexName] = $dataArray;
                        } else
                            $this->uploadedFiles[$data[1]] = $dataArray;*/
                             
                        $this->uploadedFileTempNames[] = $tmpFileName;
                    } else {
                        if(substr($data[1], strlen($data[1]) - 2, 2) == '[]') {
                            $data[1] = substr($data[1], 0, strlen($data[1]) - 2);
                            $this->POSTParameters[$data[1]][] = $dispParts[1];
                        } else if(substr($data[1], -1, 1) == ']' && $arrayBeginPos = strpos($data[1], '[')) {
                            $indexName = substr($data[1], $arrayBeginPos + 1, strlen($data[1]) - $arrayBeginPos - 2);
                            $data[1] = substr($data[1], 0, $arrayBeginPos);
                            $this->POSTParameters[$data[1]][$indexName] = $dispParts[1];
                        } else
                            $this->POSTParameters[$data[1]] = $dispParts[1];
                    }
                }
            }
            return true;
        }
        
        /**
        * Set answer on invalid request
        * 
        * @param Pancake_InvalidHTTPRequestException $exception
        */
        public function invalidRequest(Pancake_InvalidHTTPRequestException $exception) {
            $this->setHeader('Content-Type', 'text/html; charset=utf-8');
            $this->answerCode = $exception->getCode();
            $this->answerBody = '<!doctype html>';
            $this->answerBody .= '<html>';
            $this->answerBody .= '<head>';
                $this->answerBody .= '<title>'.$this->answerCode.' '.$this->getCodeString($this->answerCode).'</title>';
                $this->answerBody .= '<style>';
                    $this->answerBody .= 'body{font-family:"Arial"}';
                    $this->answerBody .= 'hr{border:1px solid #000}';
                $this->answerBody .= '</style>';
            $this->answerBody .= '</head>';
            $this->answerBody .= '<body>';
                $this->answerBody .= '<h1>'.$this->answerCode.' '.$this->getCodeString($this->answerCode).'</h1>';
                $this->answerBody .= '<hr/>';
                $this->answerBody .= '<strong>Your HTTP-Request was invalid.</strong> Error:<br/>';
                $this->answerBody .= $exception->getMessage().'<br/><br/>';
                if($exception->getHeader()) {
                    $this->answerBody .= "<strong>Headers:</strong><br/>";
                    $this->answerBody .= nl2br($exception->getHeader());
                }
                if(Pancake_Config::get('main.exposepancake') === true) {
                    $this->answerBody .= '<hr/>';
                    $this->answerBody .= 'Pancake ' . PANCAKE_VERSION;
                }
            $this->answerBody .= '</body>';
            $this->answerBody .= '</html>';
        }
       
        /**
        * Build complete answer
        *  
        */
        public function buildAnswerHeaders() {
            // Check for TRACE
            if($this->getRequestType() == 'TRACE' && $this->getAnswerCode() != 405) {
                $answer = $this->getRequestLine()."\r\n";
                $answer .= $this->getRequestHeaders()."\r\n";
                return $answer;
            }
            
            // Set AnswerCode if not set
            if(!$this->getAnswerCode())
                ($this->getAnswerBody() || $this->getAnswerHeader('Content-Length')) ? $this->setAnswerCode(200) : $this->setAnswerCode(204);
            // Set Connection-Header
            if($this->getAnswerCode() >= 200 && $this->getAnswerCode() < 400 && strtolower($this->getRequestHeader('Connection')) == 'keep-alive')
                $this->setHeader('Connection', 'keep-alive');
            else
                $this->setHeader('Connection', 'close');
            // Add Server-Header
            if(Pancake_Config::get('main.exposepancake') === true)
                $this->setHeader('Server', 'Pancake/' . PANCAKE_VERSION);
            // Set cookies
            foreach($this->setCookies as $cookie) {
                $setCookie .= ($setCookie) ? "\r\nSet-Cookie: ".$cookie : $cookie;
            }
            if($setCookie)
                $this->setHeader('Set-Cookie', $setCookie);
            // Set Content-Length
            if(!$this->getAnswerHeader('Content-Length'))
                $this->setHeader('Content-Length', strlen($this->getAnswerBody()));
            // Set Content-Type if not set
            if(!$this->getAnswerHeader('Content-Type', false) && $this->getAnswerHeader('Content-Length'))  
                $this->setHeader('Content-Type', 'text/html');                                              
            // Set Date
            if(!$this->getAnswerHeader('Date'))
                $this->setHeader('Date', date('r'));
            
            // Build Answer
            $answer = 'HTTP/'.$this->getProtocolVersion().' '.$this->getAnswerCode().' '.self::getCodeString($this->getAnswerCode())."\r\n";
            $answer .= $this->getAnswerHeaders();
            $answer .= "\r\n";
            
            return $answer;
        }
        
        /**
        * Set Answer Header
        * 
        * @param string $headerName
        * @param string $headerValue
        * @param boolean $replace
        */
        public function setHeader($headerName, $headerValue, $replace = true) {
            if($replace) {
                unset($this->answerHeaders[$headerName]);
                $this->answerHeaders[$headerName] = $headerValue;
            } else {
                if($value = $this->answerHeaders[$headerName] && !is_array($this->answerHeaders[$headerName])) {
                    unset($this->answerHeaders[$headerName]);
                    $this->answerHeaders[$headerName][] = $value;
                }
                $this->answerHeaders[$headerName][] = $headerValue;
            }
            return true;
        }
        
        /**
        * Remove Answer Header
        * 
        * @param string $headerName
        */
        public function removeHeader($headerName) {
            unset($this->answerHeaders[$headerName]);
        }
        
        /**
        * Removes all Headers to be sent
        * 
        */
        public function removeAllHeaders() {
            $this->answerHeaders = array();
        }
        
        /**
        * Sets a cookie. Parameters similar to PHPs function setcookie()
        * 
        */
        public function setCookie($name, $value = null, $expire = 0, $path = null, $domain = null, $secure = false, $httpOnly = false, $raw = false) {
            $cookie = $name.'='.($raw ? $value : urlencode($value));
            if($expire)
                $cookie .= '; Expires='.date('r', $expire);    // RFC 2822 Timestamp
            if($path)
                $cookie .= '; Path='.$path;
            if($domain)
                $cookie .= '; Domain='.$domain;
            if($secure)
                $cookie .= '; Secure';
            if($httpOnly)
                $cookie .= '; HttpOnly';
            $this->setCookies[] = $cookie;
            return true;
        }
        
        /**
        * Creates the $_SERVER-variable
        * 
        */
        public function createSERVER() {
            if(is_dir($this->vHost->getDocumentRoot() . $this->requestFilePath) && substr($this->requestFilePath, -1, 1) != '/')
                $appendSlash = '/';
            
            $_SERVER['REQUEST_TIME'] = time();
            $_SERVER['USER'] = Pancake_Config::get('main.user');
            $_SERVER['REQUEST_METHOD'] = $this->requestType;
            $_SERVER['SERVER_PROTOCOL'] = 'HTTP/' . $this->protocolVersion;
            $_SERVER['SERVER_SOFTWARE'] = 'Pancake/' . PANCAKE_VERSION;
            $_SERVER['PHP_SELF'] = $this->requestFilePath . $appendSlash;
            $_SERVER['SCRIPT_NAME'] = $this->requestFilePath . $appendSlash;
            $_SERVER['REQUEST_URI'] = $this->requestURI /*. (is_dir($this->vHost->getDocumentRoot() . $this->requestURI) && substr($this->requestURI, -1, 1) != '/' ? '/' : null)*/;
            $_SERVER['SCRIPT_FILENAME'] = (substr($this->vHost->getDocumentRoot(), -1, 1) == '/' ? substr($this->vHost->getDocumentRoot(), 0, strlen($this->vHost->getDocumentRoot()) - 1) : $this->vHost->getDocumentRoot()) . $this->requestFilePath . $appendSlash;
            $_SERVER['REMOTE_ADDR'] = $this->remoteIP;
            $_SERVER['REMOTE_PORT'] = $this->remotePort;
            
            foreach($this->requestHeaders as $name => $value)
                $_SERVER['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
                
            return $_SERVER;
        }
        
        /**
        * Get the value of a single Request-Header
        * 
        * @param string $headerName
        * @return Value of the Header
        */
        public function getRequestHeader($headerName) {
            return $this->requestHeaders[$headerName];
        }
        
        /**
        * Get formatted RequestHeaders
        * 
        */
        public function getRequestHeaders() {
            foreach($this->requestHeaders as $headerName => $headerValue) {
                $headers .= $headerName.': '.$headerValue."\r\n";
            }
            return $headers;
        }
        
        /**
        * Get the HTTP-Version used for this Request
        * 
        * @return 1.0 or 1.1
        */
        public function getProtocolVersion() {
            return $this->protocolVersion;
        }
        
        /**
        * Get the HTTP-type of this request
        * 
        */
        public function getRequestType() {
            return $this->requestType;
        }
        
        /**
        * Get the path of the requested file
        * 
        */
        public function getRequestFilePath() {
            return $this->requestFilePath;
        }
        
        /**
        * Get formatted AnswerHeaders
        * 
        */
        public function getAnswerHeaders() {
            foreach($this->answerHeaders as $headerName => $headerValue) {
                if(is_array($headerValue)) {
                    foreach($headerValue as $value)
                        $headers .= $headerName.': '.$value."\r\n";
                } else
                    $headers .= $headerName.': '.$headerValue."\r\n";
            }
            return $headers;
        }
        
        /**
        * Returns all AnswerHeaders as an array
        * 
        */
        public function getAnswerHeadersArray() {
            foreach($this->answerHeaders as $headerName => $headerValue) {
                if(is_array($headerValue)) {
                    foreach($headerValue as $value)
                        $headers[] = $headerName.': '.$value;
                } else
                    $headers[] = $headerName.': '.$headerValue;
            }
            return $headers;
        }
        
        /**
        * Get a single AnswerHeader
        * 
        * @param string $headerName
        * @param boolean $caseSensitive
        */
        public function getAnswerHeader($headerName, $caseSensitive = true) {
            if($caseSensitive)
                return $this->answerHeaders[$headerName];
            else {
                $headerName = strtolower($headerName);
                foreach($this->answerHeaders as $name => $value) {
                    if(strtolower($name) == $headerName) 
                        return $this->answerHeaders[$name];
                }
            }
        }
        
        /**
        * Set AnswerCode
        * 
        * @param int $value A valid HTTP-Answer-Code, for example 200 or 404
        */
        public function setAnswerCode($value) {
            return $this->answerCode = $value;
        }
        
        /**
        * Get AnswerCode
        * 
        */
        public function getAnswerCode() {
            return $this->answerCode;
        }
        
        /**
        * Get AnswerBody
        * 
        */
        public function getAnswerBody() {
            return $this->answerBody;
        }
        
        /**
        * Set AnswerBody
        * 
        * @param string $value
        */
        public function setAnswerBody($value) {
            return $this->answerBody = $value;
        }
        
        /**
        * Get the RequestWorker-instance handling this request
        * 
        */
        public function getRequestWorker() {
            return $this->requestWorker;
        }
        
        /**
        * Get the vHost for this request
        * 
        * @return Pancake_vHost
        */
        public function getvHost() {
            return $this->vHost;
        }
        
        /**
        * Returns all GET-parameters of this request
        * 
        */
        public function getGETParams() {
            return $this->GETParameters;
        }
        
        /**
        * Returns all POST-parameters of this request
        * 
        */
        public function getPOSTParams() {
            return $this->POSTParameters;
        }
        
        /**
        * Returns all Cookies the client sent in this request
        * 
        */
        public function getCookies() {
            return $this->cookies;
        }
        
        /**
        * Returns the first line of the request
        * 
        */
        public function getRequestLine() {
            return $this->requestLine;
        }
        
        /**
        * Returns the start of the requested range
        * 
        */
        public function getRangeFrom() {
            return $this->rangeFrom;
        }
        
        /**
        * Returns the end of the requested range
        * 
        */
        public function getRangeTo() {
            return $this->rangeTo;
        }
        
        /**
        * Returns the IP of the client
        * 
        */
        public function getRemoteIP() {
            return $this->remoteIP;
        }
        
        /**
        * Returns the port the client listens on
        * 
        */
        public function getRemotePort() {
            return $this->remotePort;
        }
        
        /**
        * Returns the MIME-type of the requested file
        * 
        */
        public function getMIMEType() {
            return $this->mimeType;
        }
        
        /**
        * Returns an array with the uploaded files (similar to $_FILES)
        * 
        */
        public function getUploadedFiles() {
            return $this->uploadedFiles;
        } 
        
        /**
        * Returns an array with the temporary names of all uploaded files
        * 
        */
        public function getUploadedFileNames() {
            return $this->uploadedFileTempNames;
        }
        
        /**
        * Check if client accepts a specific compression format
        * 
        * @param string $compression Name of the compression, e. g. gzip, deflate, etc.
        */
        public function acceptsCompression($compression) {
            return $this->acceptedCompressions[strtolower($compression)] === true;
        }
        
        /**
        * Get Message corresponding to an AnswerCode
        * 
        * @param int $code Valid AnswerCode, for example 200 or 404
        * @return string The corresponding string, for example "OK" or "Not found"
        */
        public static function getCodeString($code) {
            return self::$answerCodes[$code];
        }
    }                             
?>
