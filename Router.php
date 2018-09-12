<?php
/**
 * Router.php
 *
 * 開発中(PHPのビルトインCLIサーバーから読込が発生した場合)は常時DEBUG=trueになる。
 */
if (php_sapi_name() === 'cli-server') {
    if (!defined('DEBUG')) {
        define('DEBUG', true);
    }
    function dump($var)
    {
        echo '<pre>';
        var_dump($var);
        echo '</pre>';
    }
    function logging($str)
    {
        error_log($str, 0);
    }
    function dump_logging($var)
    {
        ob_start();
        var_dump($var);
        $str = ob_get_contents();
        logging($str);
        ob_end_clean();
    }

    ini_set('display_errors', 1);
    set_time_limit(0);
    error_reporting(E_ALL);
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Expires: Sat, 01 Apr 2017 09:00:00 GMT");
    require_once __DIR__.'/LaunchTest.php';

    return false;
}
