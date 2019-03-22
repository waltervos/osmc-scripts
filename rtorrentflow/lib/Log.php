<?php
class Log {
    public static $messages = array();

    public static function init() {
    }

    public static function addMessage($content, $level) {
        self::$messages[$level][] = array('timestamp' => new DateTime('now'), 'content' => $content);
    }

    private static function prepareMessage($format_amd_parameters) {
        $format = $format_amd_parameters[0];
        $parameters = array_slice($format_amd_parameters, 1);
        return vsprintf($format, $parameters);
    }

    public static function __callStatic($name, $arguments) {
        if (substr($name, 0, 3) == 'log') {
            $log_level = strtolower(substr($name, 3));
            $message = self::prepareMessage($arguments);
            self::addMessage($message, $log_level);
        } else {
            throw new Exception("Calling unknown static method '$name' " . implode(', ', $arguments). " in Log.php.");
        }
    }

    public static function printMessages() {
        $format = '%1s: %2s %3s' . "\n";
        foreach (self::$messages as $level => $messages) {
            //if ($loglevel !== 'debug' && $level !== $loglevel) continue;
            foreach ($messages as $message) {
                printf($format, $message['timestamp']->format('d-m-Y H:i:s') , $level, $message['content']);
            }
        }
    }
}
?>
