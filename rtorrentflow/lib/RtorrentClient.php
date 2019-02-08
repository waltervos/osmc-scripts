<?php
/*
 * rtorrentflow controls the queue in rtorrent
 * Copyright (C) 2012  Walter Vos
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */
 
class RtorrentClient {
    // Will hold an ScgiXmlRpcClient object
    private $scgi_client = false;
    private $load_method;

    public function __construct($scgi_socket, $load_method = 'load_start') {
        $this->scgi_client = new ScgiXmlRpcClient($scgi_socket);
        $this->load_method = $load_method;
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
        $response = $this->rTorrentRequest('d.get_name', array($hash));
        if (isset($response['faultCode'])) {
            if ($response['faultCode'] == "-501") {
                return false;
            }
            exit('XML-RPC error: "' . $response['faultString'] . '" (' . $response['faultCode'] . ')' . "\n");
        } else {
            return $response;
        }
    }

    public function getTorrents($view) {
        $calls = array('d.get_hash=', 'd.get_tied_to_file=', 'd.get_custom1=', 'd.get_custom2=', 'd.get_throttle_name=');
        $args = array_merge((array) $view, $calls);
        $response = $this->rTorrentRequest('d.multicall', $args);
        if (isset($response['faultCode'])) {
            exit('XML-RPC error: "' . $response['faultString'] . '" (' . $response['faultCode'] . ')' . "\n");
        } else {
            return $this->parseGetResponse($response, $calls);
        }
    }

    public function closeTorrent($hash) {
        $response = $this->rTorrentRequest('d.stop', array($hash));
        if (isset($response['faultCode'])) {
            if ($response['faultCode'] == "-501") {
                return false;
            }
            exit('XML-RPC error: "' . $response['faultString'] . '" (' . $response['faultCode'] . ')' . "\n");
        } else {
            return $response;
        }
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

    public function loadTorrent($file, $calls) {
        foreach($calls as $key => $call) {
            $calls[$key] = str_replace(' ', '\ ', $call);
        }
        $response = $this->rTorrentRequest($this->load_method, array_merge(array($file), $calls), 'bool');
        return $response;
    }
}
?>
