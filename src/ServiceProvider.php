<?php

namespace NgocTP\QueryLogger;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use DB;
use Illuminate\Database\Events\QueryExecuted;
use Monolog\Formatter\LineFormatter;
use Carbon\Carbon;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * 
     * @var \Monolog\Logger $logger
     */
    protected $logger = null;

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom($this->getConfigPath(), 'query_logger');
        if ($this->isLoggerEnabled()) {
            $filePath = config('query_logger.query_path');
            if ($filePath) {
                $streamHandler = new StreamHandler($filePath, Logger::INFO);
                $streamHandler->setFormatter(new LineFormatter("%message%;\n"));
                $this->logger = new Logger('query_logger');
                $this->logger->pushHandler($streamHandler);
            }
        }
    }

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
      public function boot()
    {

        $this->publishes([
            $this->getConfigPath() => config_path('query_logger.php'),
        ]);

        if ($this->logger) {
            $date_time = Carbon::now()->toDateTimeString();
            $time_count = 0;
            $this->app['db']->listen(function ($query, $bindings = null, $time = null, $name = null) use (&$time_count, &$date_time) {
                $arrDebug = debug_backtrace();
                $arrTemp = [];
                foreach ($arrDebug as $key => $val) {
                    if (!isset($val["file"]) && !isset($val["class"]) && !isset($val["function"])) continue;
                    $file = (isset($val["file"])) ? $val["file"] : (isset($val["class"]) ? $val["class"] : (isset($val["function"]) ? $val["function"] : ''));
                    if (isset($val['object'])) unset($val['object']);
                    if (isset($val['args'])) unset($val['args']);
                    if (isset($val['type'])) unset($val['type']);
                    if (isset($val['class'])) {
                        if (strpos($val['class'], 'Routing\Pipeline') !== false || strpos($val['class'], 'Lumen\Application')) {
                            continue;
                        } else if (strpos($val['class'], 'Eloquent\Builder') !== false) {
                            unset($val['class']);
                        }
                    }
                    if (isset($val['function'])) {
                        if (strpos($val['function'], 'first') !== false) {
                            unset($val['function']);

                        }
                    }
                    if ((strpos($file, "vendor") !== false) || (strpos($file, "Laravel") !== false) || (strpos($file, "Illuminate") !== false) || (strpos($file, "Routing") !== false)) {
                    } else {
                        $arrTemp[] = $val;
                    }
                }
                unset($arrDebug);
                if ($query instanceof \Illuminate\Database\Events\QueryExecuted) {
                    $time_count = $query->time;
                    $formattedQuery = $this->formatQuery($query->sql, $query->bindings, $query->connection);
                } else {
                    $time_count = $query->time;
                    $formattedQuery = $this->formatQuery($query, $bindings, $this->app['db']->connection($name));
                }
                $query_log = [
                    'time' => $date_time,
                    'time_count' => $time_count,
                    'query' => $formattedQuery,
                    'src' => $arrTemp
                ];
                $query_log = json_encode($query_log);
                $this->logger->info($query_log);
            });
        }
    }

    private function formatQuery($query, $bindings, $connection)
    {
        $bindings = $connection->prepareBindings($bindings);
        $bindings = $this->checkBindings($bindings);
        $pdo = $connection->getPdo();

        /**
         * Replace placeholders
         *
         * @copyright https://github.com/barryvdh/laravel-debugbar
         */
        foreach ($bindings as $key => $binding) {
            // This regex matches placeholders only, not the question marks,
            // nested in quotes, while we iterate through the bindings
            // and substitute placeholders by suitable values.
            $regex = is_numeric($key)
                ? "/\?(?=(?:[^'\\\']*'[^'\\\']*')*[^'\\\']*$)/"
                : "/:{$key}(?=(?:[^'\\\']*'[^'\\\']*')*[^'\\\']*$)/";
            $query = preg_replace($regex, $pdo->quote($binding), $query, 1);
        }

        return $query;
    }

    private function isLoggerEnabled()
    {
        $enabled = config('query_logger.enabled');
        if (is_null($enabled)) {
            $enabled = config('app.debug');
            if (is_null($enabled)) {
                $enabled = env('APP_DEBUG', false);
            }
        }

        return $enabled;
    }

    private function getConfigPath()
    {
        return __DIR__ . '/../config/query_logger.php';
    }

    /**
     * Check bindings for illegal (non UTF-8) strings, like Binary data.
     *
     * @param $bindings
     * @return mixed
     * @copyright https://github.com/barryvdh/laravel-debugbar
     */
    private function checkBindings($bindings)
    {
        foreach ($bindings as &$binding) {
            if (is_string($binding) && !mb_check_encoding($binding, 'UTF-8')) {
                $binding = '[BINARY DATA]';
            }
        }

        return $bindings;
    }
}
