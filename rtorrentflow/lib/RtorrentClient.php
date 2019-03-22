<?php
/**
 * Description of RtorrentClient
 *
 * @author waltervos
 */
class RtorrentClient {
    // Will hold an ScgiXmlRpcClient object
    private $scgi_client = false;

    public function __construct($scgi_socket) {
        $this->scgi_client = new ScgiXmlRpcClient($scgi_socket);
    }

    private function rTorrentRequest($method, $params, $return = 'content') {
        $request = xmlrpc_encode_request($method, $params);
        $response = $this->scgi_client->doXmlRpc($request);
        switch ($return) {
            case 'array' :
                return $response;
                break;
            case 'bool' :
                return $response['success'];
                break;
            case 'content' :
            default :
            return $response['content'];
                break;
        }
    }

    public function getTorrent($hash) {
        return $this->rTorrentRequest('d.get_name', array($hash));
    }

    public function setTorrentAttribute($hash, $attribute, $value) {
        return $this->rTorrentRequest("d.$attribute.set", array($hash, $value), 'bool');
    }

    public function getTorrents($view) {
        $calls = array('d.get_hash=', 'd.get_tied_to_file=', 'd.get_custom1=', 'd.get_custom2=', 'd.get_throttle_name=', 'd.is_private=', 'd.get_base_path=', 'd.custom1=', 'd.custom2=', 'd.custom3=');
        $args = array_merge((array) $view, $calls);
        $response = $this->rTorrentRequest('d.multicall', $args);
        if (isset($response['faultCode'])) {
            exit('XML-RPC error: "' . $response['faultString'] . '" (' . $response['faultCode'] . ')' . "\n");
        } else {
            return $this->parseGetResponse($response, $calls);
        }
    }

    public function closeTorrent($hash) {
        return $this->rTorrentRequest('d.stop', array($hash), 'bool');
    }

    public function pauseTorrent($hash) {
        return $this->rTorrentRequest('d.pause', array($hash), 'bool');
    }

    public function resumeTorrent($hash) {
        return $this->rTorrentRequest('d.resume', array($hash), 'bool');
    }


    public function deleteTorrent($hash) {
        $response = $this->rTorrentRequest('d.erase', array($hash));
        if (isset($response['faultCode'])) {
            if ($response['faultCode'] == "-501") {
                return false;
            }
            exit('XML-RPC error: "' . $response['faultString'] . '" (' . $response['faultCode'] . ')' . "\n");
        } else {
            return $response;
        }
    }

    private function parseGetResponse($response, $calls) {
        $return = array();
        foreach ($response as $item_key => $item_value) {
            foreach ($item_value as $key => $value) {
                $call_key = str_replace(array('d.get_', '='), '', $calls[$key]);
                if ($call_key == 'tied_to_file') {
                    $value = str_replace('//', '/', $value);
                }
                $return[$item_key][$call_key] = $value;
            }
        }
        return $return;
    }

    public function arrayToString(array $array) {
        foreach($array as $key => $value) {
            $array[$key] = str_replace(' ', '\ ', $value);
        }
        return $array;
    }

    public function loadTorrent($file, $calls, $load_method) {
        foreach($calls as $key => $call) {
            $calls[$key] = str_replace(' ', '\ ', $call);
        }
        $response = $this->rTorrentRequest($load_method, array_merge(array($file), $calls), 'bool');
        return $response;
    }
}
?>
