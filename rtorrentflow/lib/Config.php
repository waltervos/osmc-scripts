<?php
    class Config {
        private static $log_level = 'quiet'; //

        private static $erase_completed = false; //

        private static $sonarr_api_key = '771f8491c4474cd4b7bf1a5b0861963e';

        private static $sonarr_port = '8989';

        private static $sonarr_hostname = 'localhost';

        private static $sonarr_scheme = 'http';

        private static $sonarr_category = 'tv-sonarr';
        
        // How many torrents to download simultaneously? false = unlimited (not recommended)
        private static $max_leeching = 6; //
    
        // How many torrents may be active (including leeching)? false = unlimited
        private static $max_active = 12; //

        // What is the root directory for torrent files?
        private static $torrent_root = '/home/osmc/Torrents'; //

        // What is the root directory to move completed downloads to?
        private static $completed_root = '/home/osmc/'; //

        private static $copy_paths = array(
            'downloads/whatcd' => 'muziek/'
        );

        // What is the location of the rtorrent unix socket?
        private static $unix_socket = '/home/osmc/.run/rtorrent.socket'; //

        private static $scgi_timeout = 5;
        
        private static $load_method = 'load_start';

        public function __construct() {}

        public static function initialize() {}

        public static function setValue($key, $value) {
            if (property_exists(__CLASS__, $key)) {
                self::$$key = $value;
            } else {
                throw new Exception();
            }
        }

        public static function getValue($key) {
            if (property_exists(__CLASS__, $key)) {
                return self::$$key;
            }
            else return false;
        }
    }
?>