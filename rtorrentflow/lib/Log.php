<?php
class Log {
    private static $messages = array();

    private static $log_levels = array (
        100 => 'trace',
        200 => 'info',
        300 => 'warn',
        400 => 'error'
    );

    public static function init() {
    }

    public static function addMessage($content, $level) {
        self::$messages[$level][] = array('timestamp' => new DateTime('now'), 'content' => $content);
    }

    private static function prepareMessage($format_and_parameters) {
        $format = $format_and_parameters[0];
        $parameters = array_slice($format_and_parameters, 1);
        return vsprintf($format, $parameters);
    }

    public static function __callStatic($name, $arguments) {
        if (in_array($name, self::$log_levels)) {
            $log_level = $name;
            $message = self::prepareMessage($arguments);
            self::addMessage($message, $log_level);
        } else {
            if (method_exists(static::class, $name)) {
                throw new Exception("Calling private static method 'Log::$name' " . implode(', ', $arguments). " in Log.php.");
            } else {
                throw new Exception("Calling unknown static method 'Log::$name' " . implode(', ', $arguments). " in Log.php.");
            }
        }
    }

    public static function printMessages($log_level = 'warn') {
        if ($numeric_log_level = array_search($log_level, self::$log_levels)) {
            $applicable_log_levels = array_filter(self::$log_levels, function($key) use ($numeric_log_level) {
                return $key >= $numeric_log_level;
            }, ARRAY_FILTER_USE_KEY);
        }
        $format = '%1s: [%2s] %3s' . "\n";
        foreach (self::$messages as $level => $messages) {
            if (in_array($level, $applicable_log_levels)) {
                foreach ($messages as $message) {
                    printf($format, $message['timestamp']->format('d-m-Y H:i:s') , $level, $message['content']);
                }   
            }
        }
    }
}
?>
