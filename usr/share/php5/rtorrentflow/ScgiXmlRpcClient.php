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
    class ScgiXmlRpcClient {
        private $scgi_socket;
        private $scgi_timeout;

        public function __construct($scgi_socket, $scgi_timeout = 5) {
            $this->scgi_socket = "unix://$scgi_socket";
            $this->scgi_timeout = $scgi_timeout;
        }

        public function doXmlRpc($request) {
            if ($response = $this->scgiSend($request)) {
                $response = $this->parseHttpResponse($response);
                $success = ($response[0]['status'] == '200 OK') ? true : false;
                $content = str_replace('i8', 'double', $response[1]);
                return array(
                    'success' => $success,
                    'content' => xmlrpc_decode(utf8_encode($content))
                );
            } else {
                Log::addMessage('Cannot connect to rtorrent', 'info');
                exit(0);
            }
        }

        // from http://snipplr.com/view/17242/parse-http-response/
        private function parseHttpResponse($string) {
            $headers = array();
            $content = '';
            $str = strtok($string, "\n");
            $h = null;
            while ($str !== false) {
                if ($h and trim($str) === '') {
                    $h = false;
                    continue;
                }
                if ($h !== false and false !== strpos($str, ':')) {
                    $h = true;
                    list($headername, $headervalue) = explode(':', trim($str), 2);
                    $headername = strtolower($headername);
                    $headervalue = ltrim($headervalue);
                    if (isset($headers[$headername])) {
                        $headers[$headername] .= ',' . $headervalue;
                    } else {
                        $headers[$headername] = $headervalue;
                    }
                }
                if ($h === false) {
                    $content .= $str . "\n";
                }
                $str = strtok("\n");
            }
            return array($headers, trim($content));
        }

        // adapted from source code of rutorrent
        private function scgiSend($request) {
            $result = '';
            $contentlength = strlen($request);
            if ($contentlength > 0) {
                $socket = @fsockopen($this->scgi_socket, -1, $errno, $errstr, $this->scgi_timeout);
                if ($socket) {
                    $reqheader = "CONTENT_LENGTH\x00$contentlength\x00SCGI\x001\x00";
                    $tosend = strlen($reqheader) . ":$reqheader,$request";
                    @fputs($socket, $tosend);
                    while (!feof($socket)) {
                        $result .= @fread($socket, 4096);
                    }
                    fclose($socket);
                }
            }
            return $result;
        }
    }
?>
