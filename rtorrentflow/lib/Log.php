<?php
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
            if ($loglevel !== 'debug' && $level !== $loglevel) continue;
            foreach ($messages as $message) {
                printf($format, $message['timestamp']->format('d-m-Y H:i:s') , $level, $message['content']);
            }
        }
    }
}
?>
