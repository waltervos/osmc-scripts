#!/usr/bin/php
<?php
require 'rtorrentflow/ScgiXmlRpcClient.php';
require 'rtorrentflow/RtorrentClient.php';
require 'rtorrentflow/SonarrClient.php';
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
$rtorrent_manager->throttleActiveTorrents();
$rtorrent_manager->setDestinationOnSonarrTorrents();

if ($loglevel != 'quiet') {
    Log::printMessages($loglevel);
}
?>
