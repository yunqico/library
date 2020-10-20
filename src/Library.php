<?php
namespace think\admin;

use think\admin\command\Database;
use think\admin\command\Install;
use think\admin\command\Queue;
use think\admin\command\Version;
use think\admin\multiple\App;
use think\admin\multiple\command\Build;
use think\admin\multiple\command\Clear;
use think\admin\multiple\Url;
use think\admin\service\AdminService;
use think\admin\service\SystemService;
use think\middleware\LoadLangPack;
use think\middleware\SessionInit;
use think\Request;
use think\Service;
use function Composer\Autoload\includeFile;

/**
 * 模块注册服务
 * Class Library
 * @package think\admin
 */
class Library extends Service
{
    /**
     * 扩展库版本号
     */
    const VERSION = '6.0.17';

    /**
     * 启动服务
     */
    public function boot()
    {
        // 多应用中间键处理
        $this->app->event->listen('HttpRun', function (Request $request) {
            $this->app->middleware->add(App::class);
            // 解决 HTTP 调用 Console 之后 URL 问题
            if (!$this->app->request->isCli()) {
                $request->setHost($request->host());
            }
        });
        // 替换 ThinkPHP 地址
        $this->app->bind('think\route\Url', Url::class);
        // 替换 ThinkPHP 指令
        $this->commands(['build' => Build::class, 'clear' => Clear::class]);
        // 注册 ThinkAdmin 指令
        $this->commands([Queue::class, Install::class, Version::class, Database::class]);
        // 动态应用运行参数
        SystemService::instance()->bindRuntime();
    }

    /**
     * 初始化服务
     */
    public function register()
    {
        // 输入默认过滤
        $this->app->request->filter(['trim']);
        // 加载中文语言
        $this->app->lang->load(__DIR__ . '/lang/zh-cn.php', 'zh-cn');
        $this->app->lang->load(__DIR__ . '/lang/en-us.php', 'en-us');
        // 判断访问模式，兼容 CLI 访问控制器
        if ($this->app->request->isCli()) {
            if (empty($_SERVER['REQUEST_URI']) && isset($_SERVER['argv'][1])) {
                $this->app->request->setPathinfo($_SERVER['argv'][1]);
            }
        } else {
            $isSess = $this->app->request->request('not_init_session', 0) == 0;
            $notYar = stripos($this->app->request->header('user-agent', ''), 'PHP Yar RPC-') === false;
            if ($notYar && $isSess) {
                // 注册会话初始化中间键
                $this->app->middleware->add(SessionInit::class);
                // 注册语言包处理中间键
                $this->app->middleware->add(LoadLangPack::class);
            }
            // 注册访问处理中间键
            $this->app->middleware->add(function (Request $request, \Closure $next) {
                $header = [];
                if (($origin = $request->header('origin', '*')) !== '*') {
                    $header['Access-Control-Allow-Origin'] = $origin;
                    $header['Access-Control-Allow-Methods'] = 'GET,POST,PATCH,PUT,DELETE';
                    $header['Access-Control-Allow-Headers'] = 'Authorization,Content-Type,If-Match,If-Modified-Since,If-None-Match,If-Unmodified-Since,X-Requested-With';
                    $header['Access-Control-Expose-Headers'] = 'User-Form-Token,User-Token,Token';
                }
                // 访问模式及访问权限检查
                if ($request->isOptions()) {
                    return response()->code(204)->header($header);
                } elseif (AdminService::instance()->check()) {
                    return $next($request)->header($header);
                } elseif (AdminService::instance()->isLogin()) {
                    return json(['code' => 0, 'msg' => lang('think_library_not_auth')])->header($header);
                } else {
                    return json(['code' => 0, 'msg' => lang('think_library_not_login'), 'url' => sysuri('admin/login/index')])->header($header);
                }
            }, 'route');
        }
        // 动态加入应用函数
        $SysRule = "{$this->app->getBasePath()}*/sys.php";
        foreach (glob($SysRule) as $file) includeFile($file);
    }
}