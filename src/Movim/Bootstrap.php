<?php

namespace Movim;

define('DOCUMENT_ROOT', dirname(__FILE__, 3));

use App\Configuration;
use Monolog\Logger;
use Monolog\Handler\SyslogHandler;
use Illuminate\Database\Capsule\Manager as Capsule;

use App\Session as DBSession;
use App\User as DBUser;

class Bootstrap
{
    function boot($dbOnly = false)
    {
        //define all needed constants
        $this->setConstants();

        mb_internal_encoding("UTF-8");

        $loadmodlsuccess = $this->loadModl();
        $this->loadCapsule();

        if ($dbOnly) return;

        //First thing to do, define error management (in case of error forward)
        $this->setLogs();

        //Check if vital system need is OK
        $this->checkSystem();

        $this->loadCommonLibraries();
        $this->loadDispatcher();
        $this->loadHelpers();

        $this->setTimezone();
        $this->setLogLevel();

        if ($loadmodlsuccess) {
            $this->startingSession();
            $this->loadLanguage();
        } else {
            throw new \Exception('Error loading Modl');
        }
    }

    private function checkSystem()
    {
        $listWritableFile = [
            LOG_PATH.'/logger.log',
            LOG_PATH.'/php.log',
            CACHE_PATH.'/test.tmp',
        ];
        $errors = [];

        if (!file_exists(CACHE_PATH) && !@mkdir(CACHE_PATH)) {
            $errors[] = 'Couldn\'t create directory cache';
        }
        if (!file_exists(LOG_PATH) && !@mkdir(LOG_PATH)) {
            $errors[] = 'Couldn\'t create directory log';
        }
        if (!file_exists(CONFIG_PATH) && !@mkdir(CONFIG_PATH)) {
            $errors[] = 'Couldn\'t create directory config';
        }

        if (!empty($errors) && !is_writable(DOCUMENT_ROOT)) {
            $errors[] = 'We\'re unable to write to folder ' .
                DOCUMENT_ROOT . ': check rights';
        }

        foreach($listWritableFile as $fileName) {
            if (!file_exists($fileName)) {
                if (touch($fileName) !== true) {
                    $errors[] = 'We\'re unable to write to ' .
                        $fileName . ': check rights';
                }
            } elseif (is_writable($fileName) !== true) {
                $errors[] = 'We\'re unable to write to file ' .
                    $fileName . ': check rights';
            }
        }
        if (!function_exists('json_decode')) {
             $errors[] = 'You need to install php5-json that\'s not seems to be installed';
        }
        if (count($errors)) {
            throw new \Exception(implode("\n<br />",$errors));
        }
    }

    private function setConstants()
    {
        define('APP_TITLE',     'Movim');
        define('APP_NAME',      'movim');
        define('APP_VERSION',   $this->getVersion());
        define('APP_SECURED',   $this->isServerSecured());
        define('SMALL_PICTURE_LIMIT', 320000);

        if (file_exists(DOCUMENT_ROOT.'/config/db.inc.php')) {
            require DOCUMENT_ROOT.'/config/db.inc.php';
        } else {
            throw new \Exception('Cannot find config/db.inc.php file');
        }

        if (isset($_SERVER['HTTP_HOST'])) {
            define('BASE_HOST',     $_SERVER['HTTP_HOST']);
        }

        if (isset($_SERVER['SERVER_NAME'])) {
            define('BASE_DOMAIN',   $_SERVER["SERVER_NAME"]);
        }

        define('BASE_URI',      $this->getBaseUri());
        define('CACHE_URI',     $this->getBaseUri() . 'cache/');

        if (isset($_COOKIE['MOVIM_SESSION_ID'])) {
            define('SESSION_ID',    $_COOKIE['MOVIM_SESSION_ID']);
        } else {
            define('SESSION_ID',    getenv('sid'));
        }

        define('DB_TYPE',       $conf['type']);
        define('DB_HOST',       $conf['host']);
        define('DB_USERNAME',   $conf['username']);
        define('DB_PASSWORD',   $conf['password']);
        define('DB_PORT',       $conf['port']);
        define('DB_DATABASE',   $conf['database']);

        define('THEMES_PATH',   DOCUMENT_ROOT . '/themes/');
        define('APP_PATH',      DOCUMENT_ROOT . '/app/');
        define('SYSTEM_PATH',   DOCUMENT_ROOT . '/system/');
        define('LIB_PATH',      DOCUMENT_ROOT . '/lib/');
        define('LOCALES_PATH',  DOCUMENT_ROOT . '/locales/');
        define('CACHE_PATH',    DOCUMENT_ROOT . '/cache/');
        define('LOG_PATH',      DOCUMENT_ROOT . '/log/');
        define('CONFIG_PATH',   DOCUMENT_ROOT . '/config/');

        define('VIEWS_PATH',    DOCUMENT_ROOT . '/app/views/');
        define('HELPERS_PATH',  DOCUMENT_ROOT . '/app/helpers/');
        define('WIDGETS_PATH',  DOCUMENT_ROOT . '/app/widgets/');
        define('SQL_DATE',      'Y-m-d H:i:s');

        define('MOVIM_API',     'https://api.movim.eu/');

        if (!defined('DOCTYPE')) {
            define('DOCTYPE','text/html');
        }
    }

    private function isServerSecured()
    {
        return ((
            isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != "")
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'
        ));
    }

    private function getVersion()
    {
        $file = 'VERSION';
        if ($f = fopen(DOCUMENT_ROOT.'/'.$file, 'r')) {
            return trim(fgets($f));
        }
    }

    private function getBaseUri()
    {
        $dirname = dirname($_SERVER['PHP_SELF']);

        if (strstr($dirname, 'index.php')) {
            $dirname = substr($dirname, 0, strrpos($dirname, 'index.php'));
        }

        $path = (($dirname == DIRECTORY_SEPARATOR) ? '' : $dirname).'/';

        // Determining the protocol to use.
        $uri = "http://";
        if ($this->isServerSecured()) {
            $uri = 'https://';
        }

        if ($path == "") {
            $uri .= $_SERVER['HTTP_HOST'] ;
        } elseif (isset($_SERVER['HTTP_HOST'])) {
            $uri .= str_replace('//', '/', $_SERVER['HTTP_HOST'] . $path);
        }

        if (getenv('baseuri') != null
        && filter_var(getenv('baseuri'), FILTER_VALIDATE_URL)) {
            return getenv('baseuri');
        }

        return $uri;
    }

    private function loadCapsule()
    {
        if (file_exists(DOCUMENT_ROOT.'/config/db.inc.php')) {
            require DOCUMENT_ROOT.'/config/db.inc.php';
        } else {
            throw new \Exception('Cannot find config/db.inc.php file');
        }

        $capsule = new Capsule;
        $capsule->addConnection([
          'driver' => $conf['type'],
          'host' => $conf['host'],
          'port' => $conf['port'],
          'database' => $conf['database'],
          'username' => $conf['username'],
          'password' => $conf['password'],
          'charset' => 'utf8',
          'collation' => 'utf8_unicode_ci',
        ]);

        $capsule->bootEloquent();
        $capsule->setAsGlobal();
    }

    private function loadCommonLibraries()
    {
        // XMPPtoForm lib
        require_once LIB_PATH . 'XMPPtoForm.php';

        // SDPtoJingle and JingletoSDP lib :)
        require_once LIB_PATH . 'SDPtoJingle.php';
        require_once LIB_PATH . 'JingletoSDP.php';
    }

    private function loadHelpers()
    {
        foreach (glob(HELPERS_PATH . '*Helper.php') as $file) {
            require $file;
        }
    }

    private function loadDispatcher()
    {
        require_once APP_PATH . 'widgets/Notification/Notification.php';
    }

    /**
     * Loads up the language, either from the User or default.
     */
    function loadLanguage()
    {
        $user = new User;

        if (php_sapi_name() != 'cli') {
            $user->reload(true);
        }

        $l = \Movim\i18n\Locale::start();

        if ($user->isLogged()) {
            $lang = DBUser::me()->language;
        }

        if (isset($lang)) {
            $l->load($lang);
        } elseif (getenv('language') != false) {
            $l->detect(getenv('language'));
            $l->loadPo();
        } elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $l->detect();
            $l->loadPo();
        } else {
            $l->load(Configuration::findOrNew(1)->locale);
        }
    }

    private function setLogs()
    {
        ini_set('display_errors', 0);
        ini_set('error_log', DOCUMENT_ROOT.'/log/php.log');

        set_error_handler([$this, 'systemErrorHandler'], E_ALL);
        register_shutdown_function([$this, 'fatalErrorShutdownHandler']);
    }

    private function setTimezone()
    {
        define('TIMEZONE_OFFSET', (getenv('offset') != 0)
            ? getenv('offset')
            : 0);
        /*else {
            // We set the default timezone to the server timezone
            $cd = new \Modl\ConfigDAO;
            $config = $cd->get();

            // And we set a global offset
            define('TIMEZONE_OFFSET', getTimezoneOffset($config->timezone));
        }*/

        date_default_timezone_set("UTC");
    }

    private function setLogLevel()
    {
        define('LOG_LEVEL', (int)Configuration::findOrNew(1)->loglevel);
    }

    private function loadModl()
    {
        // We load Movim Data Layer
        $db = \Modl\Modl::getInstance();
        $db->setModelsPath(APP_PATH.'models');

        \Modl\Utils::loadModel('Presence');
        \Modl\Utils::loadModel('Contact');
        \Modl\Utils::loadModel('Privacy');
        \Modl\Utils::loadModel('RosterLink');
        \Modl\Utils::loadModel('Postn');
        \Modl\Utils::loadModel('Info');
        \Modl\Utils::loadModel('EncryptedPass');
        \Modl\Utils::loadModel('Subscription');
        \Modl\Utils::loadModel('SharedSubscription');
        \Modl\Utils::loadModel('Caps');
        \Modl\Utils::loadModel('Invite');
        \Modl\Utils::loadModel('Message');
        \Modl\Utils::loadModel('Conference');
        \Modl\Utils::loadModel('Tag');
        \Modl\Utils::loadModel('Url');

        if (file_exists(DOCUMENT_ROOT.'/config/db.inc.php')) {
            require DOCUMENT_ROOT.'/config/db.inc.php';
        } else {
            throw new \Exception('Cannot find config/db.inc.php file');
        }

        $db->setConnectionArray($conf);
        $db->connect();

        return true;
    }

    private function startingSession()
    {
        if (SESSION_ID !== null) {
            $process = (bool)requestURL('http://localhost:1560/exists/', 2, ['sid' => SESSION_ID]);
            $session = DBSession::find(SESSION_ID);

            if ($session) {
                // There a session in the DB but no process
                if (!$process) {
                    $session->delete();
                    return;
                }

                $db = \Modl\Modl::getInstance();
                $db->setUser($session->user_id);

                $session->loadMemory();
            } elseif ($process) {
                // A process but no session in the db
                requestURL('http://localhost:1560/disconnect/', 2, ['sid' => SESSION_ID]);
            }
        }

        Cookie::set();
    }

    public function getWidgets()
    {
        // Return a list of interesting widgets to load (to save memory)
        return ['Account','AccountNext','Ack','AdHoc','Avatar','Bookmark',
        'Communities','CommunityAffiliations','CommunityConfig','CommunityData',
        'CommunityHeader','CommunityPosts','CommunitiesServer','CommunitiesServers',
        'Confirm','Chat','Chats','Config','ContactData','ContactHeader','Dialog',
        'Drawer','Header','Init','Login','LoginAnonymous','Menu','Notifs',
        'Invitations','Post','PostActions','Presence','PublishBrief','Rooms',
        'Roster','Stickers','Upload','Vcard4','Visio','VisioLink'];
    }

    /**
     * Error Handler...
     */
    function systemErrorHandler($errno, $errstr, $errfile, $errline, $errcontext = null)
    {
        $log = new Logger('movim');
        $log->pushHandler(new SyslogHandler('movim'));
        $log->addError($errstr);
        return false;
    }

    function fatalErrorShutdownHandler()
    {
        $last_error = error_get_last();
        if ($last_error['type'] === E_ERROR) {
            $this->systemErrorHandler(
                E_ERROR,
                $last_error['message'],
                $last_error['file'],
                $last_error['line']);

            if (ob_get_contents()) ob_clean();

            echo "Oops... something went wrong.\n";
            echo colorize($last_error['message'], 'red') . "\n";
            echo colorize('in ' . $last_error['file'] . ' (line ' . $last_error['line'] . ")\n", 'yellow');
            if (ob_get_contents()) ob_end_clean();
        }
    }
}
