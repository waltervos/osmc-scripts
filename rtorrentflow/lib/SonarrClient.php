<?php
    class SonarrClient {
        private $port = '8989';
        private $hostname = 'localhost';
        private $protocol = 'http';
        private $api_key;

        public function __construct() {

        }

        public function getDestinationForTorrent($hash) {
            // TODO: Use X-Api-Key header
            $curl = curl_init();
            $url = $this->getFullUrl('queue');

            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'Accept' => 'application/json'
            ));
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            
            $result = curl_exec($curl);
            curl_close($curl);
            
            if ($result) {
                $json = json_decode($result);
                foreach ($json as $queue_item) {
                    if ($queue_item->downloadId == $hash) {
                        return $queue_item->series->path;
                    }
                }
                return false;
            }
        }

        private function getFullUrl($path) {
            return $this->getProtocol() . '://' . $this->getHostname() . ':' . $this->getPort() . '/api/' . $path . '?apikey=' . $this->getApiKey();
        }

        public function setPort($port) {
            $this->port = $port;
        }

        public function setHostname($hostname) {
            $this->hostname = $hostname;
        }

        public function setProtocol($protocol) {
            $this->protocol = $protocol;
        }

        public function setApiKey($api_key) {
            $this->api_key = $api_key;
        }

        private function getPort() {
            return $this->port;
        }

        private function getHostname() {
            return $this->hostname;
        }

        private function getProtocol() {
            return $this->protocol;
        }

        private function getApiKey() {
            return $this->api_key;
        }
    }
?>