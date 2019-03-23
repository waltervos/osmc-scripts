<?php
    class SonarrClient {
        private $port = '8989';
        private $hostname = 'localhost';
        private $scheme = 'http';
        private $api_key;
        private $queue_result = array();

        public function __construct($api_key) {
            $this->setApiKey($api_key);
        }

        public function getDestinationForTorrent($hash) {
            if (empty($this->getQueueResult())) {
                // TODO: Use X-Api-Key header
                $curl = curl_init();

                curl_setopt($curl, CURLOPT_URL, $this->getUrlForCommand('queue'));
                curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                    'Accept' => 'application/json'
                ));
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                
                $result = curl_exec($curl);
                curl_close($curl);
                
                if ($result) {
                    $this->setQueueResult($result);
                } else return false;
            }
            foreach ($this->getQueueResult() as $queue_item) {
                if ($queue_item->downloadId == $hash) {
                    return $queue_item->series->path;
                }
            }
            return false;
        }

        private function getUrlForCommand($path) {
            return sprintf(
                '%1$s://%2$s:%3$s/api/%4$s?%5$s', // Format
                $this->getScheme(), 
                $this->getHostname(), 
                $this->getPort(), 
                $path, 
                http_build_query(array('apikey' => $this->getApiKey()))
            );
        }

        private function getQueueResult() {
            return $this->queue_result;
        }

        private function setQueueResult($queue_result) {
            $this->queue_result = json_decode($queue_result);
        }

        public function setPort($port) {
            $this->port = $port;
        }

        public function setHostname($hostname) {
            $this->hostname = $hostname;
        }

        public function setScheme($scheme) {
            $this->scheme = $scheme;
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

        private function getScheme() {
            return $this->scheme;
        }

        private function getApiKey() {
            return $this->api_key;
        }
    }
?>