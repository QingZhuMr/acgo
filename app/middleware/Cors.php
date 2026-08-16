<?php
declare (strict_types = 1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 跨域中间件
 * 白名单来源：.env 中的 CORS_ALLOW_ORIGIN（逗号分隔）
 */
class Cors
{
    /**
     * 允许跨域的来源列表
     * @var array
     */
    protected array $allowOrigins = [];

    public function __construct()
    {
        $origin = env('CORS_ALLOW_ORIGIN', '');
        if ($origin) {
            $this->allowOrigins = array_filter(array_map('trim', explode(',', $origin)));
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        // 请求来源
        $origin = $request->header('origin', '');

        // 预检请求（OPTIONS）直接返回，带上 CORS 头
        if ($request->method(true) === 'OPTIONS') {
            $response = Response::create('', 'html', 204);
            $this->setCorsHeaders($response, $origin);
            return $response;
        }

        // 正常请求，先执行后续逻辑，再设置响应头
        $response = $next($request);
        $this->setCorsHeaders($response, $origin);

        return $response;
    }

    /**
     * 设置跨域响应头
     */
    protected function setCorsHeaders(Response $response, string $origin): void
    {
        // 来源在白名单内，才允许跨域
        if ($this->isAllowed($origin)) {
            $response->header([
                'Access-Control-Allow-Origin'      => $origin,
                'Access-Control-Allow-Credentials' => 'true',
                'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
                'Access-Control-Allow-Headers'     => 'Content-Type, Authorization, X-Requested-With, X-Token',
                'Access-Control-Max-Age'           => '86400',
            ]);
        }
    }

    /**
     * 判断来源是否在白名单内
     */
    protected function isAllowed(string $origin): bool
    {
        if ('' === $origin) {
            return false;
        }

        foreach ($this->allowOrigins as $allow) {
            // 支持精确匹配，也支持带协议/端口的匹配
            if ($origin === $allow) {
                return true;
            }
            // 去掉协议前缀后匹配（如 http://127.0.1.1 -> 127.0.1.1）
            $host = preg_replace('#^https?://#', '', $allow);
            $originHost = preg_replace('#^https?://#', '', $origin);
            if ($originHost === $host) {
                return true;
            }
        }

        return false;
    }
}
