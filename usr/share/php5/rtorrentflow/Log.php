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
class Log {
    public static $messages = array();

    public static function init() {
    }

    public static function addMessage($content, $level) {
        self::$messages[$level][] = array('timestamp' => new DateTime('now'), 'content' => $content);
    }

    public static function printMessages($loglevel) {
        $format = '%1s: %2s %3s' . "\n";
        foreach (self::$messages as $level => $messages) {
            if ($level !== $loglevel) continue;
            foreach ($messages as $message) {
                printf($format, $message['timestamp']->format('d-m-Y H:i:s') , $level, $message['content']);
            }
        }
    }
}
?>
