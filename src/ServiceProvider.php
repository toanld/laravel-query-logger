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
            $timestemp = Carbon::now()->toDateTimeString();
            $time_count = 0;
            $this->app['db']->listen(function($query, $bindings = null, $time = null, $name = null) use(&$time_count,&$timestemp) {
                $time_execute = number_format($query->time / 1000,6);
                if ($query instanceof \Illuminate\Database\Events\QueryExecuted) {
                    $formattedQuery = $this->formatQuery($query->sql, $query->bindings, $query->connection);
                } else {
                    $formattedQuery = $this->formatQuery($query, $bindings, $this->app['db']->connection($name));
                }
                if($time_execute > config('query_logger.time_slow')){
                    $arrDebug = debug_backtrace();
                    $arrTemp = [];
                    foreach($arrDebug as $key => $val){
                        if(!isset($val["file"]) && !isset($val["class"]) && !isset($val["function"])) continue;
                        $file = (isset($val["file"])) ? $val["file"] : (isset($val["class"]) ? $val["class"] : (isset($val["function"]) ? $val["function"] : ''));
                        if(isset($val['object']))unset($val['object']);
                        if(isset($val['args']))unset($val['args']);
                        if(isset($val['type']))unset($val['type']);
                        if((strpos($file,"vendor") !== false) || (strpos($file,"Laravel") !== false)){

                        }else{
                            $arrTemp[] =$val;
                        }
                        //$arrTemp[] =$val;
                    }
                    unset($arrDebug);
                    $query_log = [
                        'timestemp'=>$timestemp,
                        'time_execute'=>$time_execute,
                        'query'=>$formattedQuery,
                        'src'=>$arrTemp
                    ];
                    $query_log = json_encode($query_log);
                    $this->logger->info($query_log);
                }else{
                    $query_log = [
                        'timestemp'=>$timestemp,
                        'time_execute'=>$time_execute,
                        'query'=>$formattedQuery
                    ];
                    $query_log = json_encode($query_log);
                    $this->logger->info($query_log);
                }

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