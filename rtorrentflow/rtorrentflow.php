#!/usr/bin/php
<?php
require 'rtorrentflow/ScgiXmlRpcClient.php';
require 'rtorrentflow/RtorrentClient.php';
require 'rtorrentflow/SonarrClient.php';
require 'rtorrentflow/RtorrentManager.php';
require 'rtorrentflow/Config.php';
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

Config::initialize();

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
            Config::setValue('max_leeching', $value);
            break;
        case 'max_active' :
            Config::setValue('max_active', $value);
            break;
        case 'torrent_root' :
            Config::setValue('torrent_root', $value);
            break;
        case 'completed_root' :
            Config::setValue('completed_root', $value);
            break;
        case 'unix_socket' :
            Config::setValue('unix_socket', $value);
            break;
        case 'erase_completed' :
            Config::setValue('erase_completed', true);
            break;
        case 'load_only' :
            Config::setValue('load_method', $value);
            break;
        case 'log_level' :
            Config::setValue('log_level', $value);
            break;
        case 'verbose' :
        case 'info' :
            Config::setValue('log_level', $option);
            break;
        default :
            break;
    }
}

$rtorrent_manager = new RtorrentManager();

if (Config::getValue('log_level') != 'quiet') {
    Log::printMessages(Config::getValue('log_level'));
}
?>
