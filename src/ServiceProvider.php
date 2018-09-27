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
    protected $queries = [];

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app["queries"] = [];
    }

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
      public function boot()
    {

        if(config('query.enabled')){
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
                $time_count = $query->time;
                if ($query instanceof \Illuminate\Database\Events\QueryExecuted) {
                    $formattedQuery = $this->formatQuery($query->sql, $query->bindings, $query->connection);
                } else {
                    $formattedQuery = $this->formatQuery($query, $bindings, $this->app['db']->connection($name));
                }
                $duration = doubleval(number_format($time_count / 1000,10,".",""));
                $query_log = [
                    'time' => $duration,
                    'date' => $date_time,
                    'query' => $formattedQuery,
                    'file_line' => $arrTemp
                ];
                if($duration > config('query.time_slow')){
                    @file_put_contents(config('query.slow_path'),json_encode($query_log) . "\n",FILE_APPEND);
                }
                if(config('query.log_query')){
                    @file_put_contents(config('query.query_path'),json_encode($query_log) . "\n",FILE_APPEND);
                }
                $queries = $this->app["queries"];
                $queries[] = $query_log;
                $this->app["queries"] = $queries;
                unset($queries,$query_log);
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
