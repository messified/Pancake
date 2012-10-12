<?php
  
    /****************************************************************/
    /* Pancake                                                      */
    /* workerFunctions.php                                          */
    /* 2012 Yussuf Khalil                                           */
    /* License: http://pancakehttp.net/license/                     */
    /****************************************************************/
    
	#.if 0
    namespace Pancake;
    
    if(PANCAKE !== true)
        exit;
	#.endif
    
    /**
    * Formats a filesize
    * 
    * @param int $size Size in Byte
    * @return string Formatted size
    */
    function formatFilesize($size) {
    	#.if /* .eval 'return Pancake\Config::get("main.sizeprefix");' */ == 'si'
            if($size >= 1000000000) // 1 Gigabyte
                return round($size / 1000000000, 2) . ' GB';
            else if($size >= 1000000) // 1 Megabyte
                return round($size / 1000000, 2) . ' MB';
            else if($size >= 1000) // 1 Kilobyte
                return round($size / 1000, 2) . ' kB';
            else 
                return $size . ' Byte';
        #.else
            if($size >= 1073741824) // 1 Gibibyte
                return round($size / 1073741824, 2) . ' GiB';
            else if($size >= 1048576) // 1 Mebibyte 
                return round($size / 1048576, 2) . ' MiB';
            else if($size >= 1024) // 1 Kibibyte
                return round($size / 1024, 2) . ' KiB';
            else
                return $size . ' Byte';   
        #.endif
    }
    
    /**
    * Sets user and group for current thread
    * 
    */
    function setUser() {
        $user = posix_getpwnam(/* .eval "return Pancake\Config::get('main.user');" */);
        $group = posix_getgrnam(/* .eval "return Pancake\Config::get('main.group');" */);
        if(!posix_setgid($group['gid'])) {
            trigger_error('Failed to change group', /* .constant 'E_USER_ERROR' */);
            abort();
        }
        if(!posix_setuid($user['uid'])) {
            trigger_error('Failed to change user', /* .constant 'E_USER_ERROR' */);
            abort();
        }
        return true;
    }
    
    function dummy() {return true;}
?>