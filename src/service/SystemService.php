<?php

declare (strict_types=1);

namespace think\admin\service;

use think\admin\Service;
use think\db\Query;

/**
 * 系统参数管理服务
 * Class SystemService
 * @package think\admin\service
 */
class SystemService extends Service
{

    /**
     * 配置数据缓存
     * @var array
     */
    protected $data = [];

    /**
     * 绑定配置数据表
     * @var string
     */
    protected $table = 'SystemConfig';

    /**
     * 设置配置数据
     * @param string $name 配置名称
     * @param string $value 配置内容
     * @return integer
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function set(string $name, $value = '')
    {
        $this->data = [];
        [$type, $field] = $this->_parse($name, 'base');
        if (is_array($value)) {
            $count = 0;
            foreach ($value as $kk => $vv) {
                $count += $this->set("{$field}.{$kk}", $vv);
            }
            return $count;
        } else {
            $this->app->cache->delete($this->table);
            $map = ['type' => $type, 'name' => $field];
            $data = array_merge($map, ['value' => $value]);
            $query = $this->app->db->name($this->table)->master(true)->where($map);
            return (clone $query)->count() > 0 ? $query->update($data) : $query->insert($data);
        }
    }

    /**
     * 读取配置数据
     * @param string $name
     * @param string $default
     * @return array|mixed|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function get(string $name = '', string $default = '')
    {
        if (empty($this->data)) {
            $this->app->db->name($this->table)->cache($this->table)->select()->map(function ($item) {
                $this->data[$item['type']][$item['name']] = $item['value'];
            });
        }
        [$type, $field, $outer] = $this->_parse($name, 'base');
        if (empty($name)) {
            return $this->data;
        } elseif (isset($this->data[$type])) {
            $group = $this->data[$type];
            if ($outer !== 'raw') foreach ($group as $kk => $vo) {
                $group[$kk] = htmlspecialchars($vo);
            }
            return $field ? ($group[$field] ?? $default) : $group;
        } else {
            return $default;
        }
    }

    /**
     * 数据增量保存
     * @param Query|string $dbQuery 数据查询对象
     * @param array $data 需要保存的数据
     * @param string $key 更新条件查询主键
     * @param array $where 额外更新查询条件
     * @return boolean|integer 失败返回 false, 成功返回主键值或 true
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function save($dbQuery, array $data, string $key = 'id', array $where = [])
    {
        $val = $data[$key] ?? null;
        $query = (is_string($dbQuery) ? $this->app->db->name($dbQuery) : $dbQuery)->master()->strict(false)->where($where);
        if (empty($where[$key])) is_string($val) && strpos($val, ',') !== false ? $query->whereIn($key, explode(',', $val)) : $query->where([$key => $val]);
        return is_array($info = (clone $query)->find()) && !empty($info) ? ($query->update($data) !== false ? ($info[$key] ?? true) : false) : $query->insertGetId($data);
    }

    /**
     * 解析缓存名称
     * @param string $rule 配置名称
     * @param string $type 配置类型
     * @return array
     */
    private function _parse(string $rule, string $type = 'base'): array
    {
        if (stripos($rule, '.') !== false) {
            [$type, $rule] = explode('.', $rule, 2);
        }
        [$field, $outer] = explode('|', "{$rule}|");
        return [$type, $field, strtolower($outer)];
    }

    /**
     * 生成最短URL地址
     * @param string $url 路由地址
     * @param array $vars PATH 变量
     * @param boolean|string $suffix 后缀
     * @param boolean|string $domain 域名
     * @return string
     */
    public function sysuri(string $url = '', array $vars = [], $suffix = true, $domain = false): string
    {
        $location = $this->app->route->buildUrl($url, $vars)->suffix($suffix)->domain($domain)->build();
        [$d1, $d2, $d3] = [$this->app->config->get('app.default_app'), $this->app->config->get('route.default_controller'), $this->app->config->get('route.default_action')];
        return preg_replace('|/\.html$|', '', preg_replace(["|^/{$d1}/{$d2}/{$d3}(\.html)?$|i", "|/{$d2}/{$d3}(\.html)?$|i", "|/{$d3}(\.html)?$|i"], ['$1', '$1', '$1'], $location));
    }

    /**
     * 保存数据内容
     * @param string $name
     * @param mixed $value
     * @return boolean
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setData(string $name, $value)
    {
        return $this->save('SystemData', ['name' => $name, 'value' => serialize($value)], 'name');
    }

    /**
     * 读取数据内容
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getData(string $name, $default = [])
    {
        try {
            $value = $this->app->db->name('SystemData')->where(['name' => $name])->value('value', null);
            return is_null($value) ? $default : unserialize($value);
        } catch (\Exception $exception) {
            return $default;
        }
    }

    /**
     * 写入系统日志内容
     * @param string $action
     * @param string $content
     * @return boolean
     */
    public function setOplog(string $action, string $content): bool
    {
        $oplog = $this->getOplog($action, $content);
        return $this->app->db->name('SystemOplog')->insert($oplog) !== false;
    }

    /**
     * 获取系统日志内容
     * @param string $action
     * @param string $content
     * @return array
     */
    public function getOplog(string $action, string $content): array
    {
        return [
            'node'     => NodeService::instance()->getCurrent(),
            'action'   => $action, 'content' => $content,
            'geoip'    => $this->app->request->ip() ?: '127.0.0.1',
            'username' => AdminService::instance()->getUserName() ?: '-',
        ];
    }

    /**
     * 打印输出数据到文件
     * @param mixed $data 输出的数据
     * @param boolean $new 强制替换文件
     * @param string|null $file 文件名称
     * @return false|int
     */
    public function putDebug($data, $new = false, $file = null)
    {
        if (is_null($file)) $file = $this->app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR . date('Ymd') . '.log';
        $str = (is_string($data) ? $data : ((is_array($data) || is_object($data)) ? print_r($data, true) : var_export($data, true))) . PHP_EOL;
        return $new ? file_put_contents($file, $str) : file_put_contents($file, $str, FILE_APPEND);
    }

    /**
     * 判断运行环境
     * @param string $type 运行模式（dev|demo|local）
     * @return boolean
     */
    public function checkRunMode(string $type = 'dev'): bool
    {
        $domain = $this->app->request->host(true);
        $isDemo = is_numeric(stripos($domain, 'thinkadmin.top'));
        $isLocal = in_array($domain, ['127.0.0.1', 'localhost']);
        if ($type === 'dev') return $isLocal || $isDemo;
        if ($type === 'demo') return $isDemo;
        if ($type === 'local') return $isLocal;
        return true;
    }

    /**
     * 判断实时运行模式
     * @return boolean
     */
    public function isDebug(): bool
    {
        return $this->getRuntime('run') !== 'product';
    }

    /**
     * 设置运行环境模式
     * @param null|boolean $state
     * @return boolean
     */
    public function productMode($state = null): bool
    {
        if (is_null($state)) {
            return $this->bindRuntime();
        } else {
            return $this->setRuntime([], $state ? 'product' : 'debug');
        }
    }

    /**
     * 获取实时运行配置
     * @param null|string $name 配置名称
     * @param array $default 配置内容
     * @return array|string
     */
    public function getRuntime($name = null, array $default = [])
    {
        $filename = "{$this->app->getRootPath()}runtime/config.json";
        if (file_exists($filename) && is_file($filename)) {
            $data = json_decode(file_get_contents($filename), true);
        }
        if (empty($data) || !is_array($data)) $data = [];
        if (empty($data['map']) || !is_array($data['map'])) $data['map'] = [];
        if (empty($data['uri']) || !is_array($data['uri'])) $data['uri'] = [];
        if (empty($data['run']) || !is_string($data['run'])) $data['run'] = 'debug';
        return is_null($name) ? $data : ($data[$name] ?? $default);
    }

    /**
     * 设置实时运行配置
     * @param null|array $map 应用映射
     * @param null|string $run 支持模式
     * @param null|array $uri 域名映射
     * @return boolean 是否调试模式
     */
    public function setRuntime(array $map = [], $run = null, array $uri = []): bool
    {
        $data = $this->getRuntime();
        $data['run'] = is_string($run) ? $run : $data['run'];
        $data['map'] = $this->uniqueArray($data['map'], $map);
        $data['uri'] = $this->uniqueArray($data['uri'], $uri);
        $filename = "{$this->app->getRootPath()}runtime/config.json";
        file_put_contents($filename, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this->bindRuntime($data);
    }

    /**
     * 绑定应用实时配置
     * @param array $data 配置数据
     * @return boolean 是否调试模式
     */
    public function bindRuntime(array $data = []): bool
    {
        if (empty($data)) $data = $this->getRuntime();
        $bind['app_map'] = $this->app->config->get('app.app_map', []);
        $bind['domain_bind'] = $this->app->config->get('app.domain_bind', []);
        if (count($data['map']) > 0) $bind['app_map'] = $this->uniqueArray($bind['app_map'], $data['map']);
        if (count($data['uri']) > 0) $bind['domain_bind'] = $this->uniqueArray($bind['domain_bind'], $data['uri']);
        $this->app->config->set($bind, 'app');
        return $this->app->debug($data['run'] !== 'product')->isDebug();
    }

    /**
     * 获取唯一数组参数
     * @param array ...$args
     * @return array
     */
    private function uniqueArray(...$args): array
    {
        return array_unique(array_reverse(array_merge(...$args)));
    }

    /**
     * 压缩发布项目
     */
    public function pushRuntime(): void
    {
        $type = $this->app->db->getConfig('default');
        $this->app->console->call("optimize:schema", ["--connection={$type}"]);
        foreach (NodeService::instance()->getModules() as $module) {
            $path = $this->app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR . $module;
            file_exists($path) && is_dir($path) or mkdir($path, 0755, true);
            $this->app->console->call("optimize:route", [$module]);
        }
    }

    /**
     * 清理运行缓存
     */
    public function clearRuntime(): void
    {
        $data = $this->getRuntime();
        $this->app->console->call('clear');
        $this->setRuntime($data['map'], $data['run'], $data['uri']);
    }

    /**
     * 初始化并运行应用
     * @param \think\App $app
     */
    public function doInit(\think\App $app): void
    {
        $app->debug($this->isDebug());
        ($response = $app->http->run())->send();
        $app->http->end($response);
    }
}