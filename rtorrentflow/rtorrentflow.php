#!/usr/bin/php
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

require 'rtorrentflow/ScgiXmlRpcClient.php';
require 'rtorrentflow/RtorrentClient.php';
require 'rtorrentflow/RtorrentManager.php';
require 'rtorrentflow/Log.php';

require 'rtorrentflow/bittorrent/DecoderInterface.php';
require 'rtorrentflow/bittorrent/Decoder.php';
require 'rtorrentflow/bittorrent/EncoderInterface.php';
require 'rtorrentflow/bittorrent/Encoder.php';
require 'rtorrentflow/bittorrent/Torrent.php';

$args = array();

if ($argc >= 2) {
    array_shift($argv);
    foreach ($argv as $key => $arg) {
        parse_str($arg, $output);
        if (empty($output[key($output)])) {
            $args[$key] = key($output);
        } else {
            $args[$key] = $output;
        }
    }
}

$erase = false;
$loglevel = 'quiet';

$rtorrent_manager = new RtorrentManager();

foreach ($args as $arg) {
    if (is_array($arg)) {
        $option = key($arg);
        $value = $arg[$option];
    } else {
        $option = $arg;
        $value = '';
    }
    switch($option) {
        case 'max_leeching' :
            $rtorrent_manager->setMaxLeeching($value);
            break;
        case 'max_active' :
            $rtorrent_manager->setMaxActive($value);
            break;
        case 'torrent_root' :
            $rtorrent_manager->setTorrentRoot($value);
            break;
        case 'completed_root' :
            $rtorrent_manager->setCompletedRoot($value);
            break;
        case 'unix_socket' :
            $rtorrent_manager->setUnixSocket($value);
            break;
        case 'erase_completed' :
            $erase = true;
            break;
        case 'load_only' :
            $rtorrent_manager->setLoadMethod('load');
            break;
        case 'verbose' :
            $loglevel = 'debug';
            break;
        case 'info' :
            $loglevel = 'info';
            break;
        default :
            break;
    }
}
$rtorrent_manager->closeCompletedTorrents($erase);
$rtorrent_manager->runQueueManager();

if ($loglevel != 'quiet') {
    Log::printMessages($loglevel);
}
?>
