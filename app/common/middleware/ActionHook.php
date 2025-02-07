<?php

namespace app\common\middleware;

use support\Container;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use Webman\Route;

class ActionHook implements MiddlewareInterface
{
    /**
     * @var string
     */
    public $sqlLogs = '';

    public function process(Request $request, callable $next): Response
    {
        $request->request_time = microtime(true);

        if ($request->controller) {
            // 禁止直接访问beforeAction afterAction
            if ($request->action === 'beforeAction' || $request->action === 'afterAction') {
                $callback = Route::getFallback() ?? function () use ($request) {
                    $response = new Response(404, [], \file_get_contents(public_path() . '/404.html'));
                    $response->response_time = microtime(true);
                    $this->setResponse($response);
                    $this->recordLog($request, $response);
                    return $response;
                };

                $reponse = $callback($request);
                $response = $reponse instanceof Response ? $reponse : \response($reponse);
                $response->response_time = microtime(true);
                $this->setResponse($response);
                $this->recordLog($request, $response);
                return $response;
            }

            $controller = Container::get($request->controller);
            if (method_exists($controller, 'beforeAction')) {
                $before_response = call_user_func([$controller, 'beforeAction'], $request);
                if ($before_response instanceof Response) {
                    $before_response->response_time = microtime(true);
                    $this->setResponse($before_response);
                    $this->recordLog($request, $before_response);
                    return $before_response;
                }
            }

            $response = $next($request);
            if (method_exists($controller, 'afterAction')) {
                $after_response = call_user_func([$controller, 'afterAction'], $request, $response);
                if ($after_response instanceof Response) {
                    $after_response->response_time = microtime(true);
                    $this->setResponse($after_response);
                    $this->recordLog($request, $after_response);
                    return $after_response;
                }
            }

            $response->response_time = microtime(true);
            $this->setResponse($response);
            $this->recordLog($request, $response);
            return $response;
        }

        $response = $next($request);
        $response->response_time = microtime(true);
        $this->setResponse($response);
        $this->recordLog($request, $response);
        return $response;
    }

    /**
     * 记录日志
     *
     * @author HSK
     * @date 2022-04-14 17:29:29
     *
     * @param Request $request
     *
     * @return void
     */
    protected function recordLog(Request $request, Response $response)
    {
        $runTime = ($response->response_time - $request->request_time) ?? 0;

        if (strpos($response->rawBody(), '<!DOCTYPE html>') !== false || strpos($response->rawBody(), '<h1>') !== false) {
            $body = 'html view';
        } else if (strpos($response->rawBody(), 'PNG') !== false) {
            $body = 'captcha';
        } else {
            $body = $response->rawBody();
        }

        if (!empty($request->header('content-length'))) {
            $requestLen = $request->header('content-length');
        } else {
            $requestLen = strlen($request->rawBuffer());
        }

        if (null !== $response->file) {
            $fileLen = (0 === $response->file['length']) ? filesize($response->file['file']) : $response->file['length'];
        } else {
            $fileLen = 0;
        }

        $data = [
            'time'                => date('Y-m-d H:i:s.', $request->request_time) . substr($request->request_time, 11),   // 请求时间（包含毫秒时间）
            'message'             => 'http request',                                                                      // 描述
            'transceived_traffic' => $requestLen + strlen($response) + $fileLen,                                          // 收发流量
            'run_time'            => $runTime,                                                                            // 运行时长
            'ip'                  => $request->getRealIp($safe_mode = true) ?? '',                                        // 请求客户端IP
            'url'                 => $request->fullUrl() ?? '',                                                           // 请求URL
            'method'              => $request->method() ?? '',                                                            // 请求方法
            'request_param'       => $request->all() ?? [],                                                               // 请求参数
            'request_header'      => $request->header() ?? [],                                                            // 请求头
            'cookie'              => $request->cookie() ?? [],                                                            // 请求cookie
            'session'             => $request->session()->all() ?? [],                                                    // 请求session
            'response_code'       => $response->getStatusCode() ?? '',                                                    // 响应码
            'response_header'     => $response->getHeaders() ?? [],                                                       // 响应头
            'response_body'       => $body ?? [],                                                                         // 响应数据
        ];

        // 记录详细请求日志
        \Webman\RedisQueue\Client::send('webman_log_request', $data);

        // 记录请求日志
        $logs = $data['message'] . ' ' . $data['ip'] . ' ' . $data['method'] . ' ' . trim($data['url'], '/') . ' [' . $data['run_time'] . "s]\n";
        if ('POST' === $data['method']) {
            $logs .= "[POST] " . var_export($request->post(), true) . "\n";
        }
        static $initialized;
        if (!$initialized) {
            if (class_exists(\think\facade\Db::class)) {
                \think\facade\Db::listen(function ($sql, $runtime, $master) {
                    if ($sql === 'select 1') {
                        return;
                    }

                    $this->sqlLogs .= "[SQL] " . trim($sql) . " [ RunTime:{$runtime}s ]\n";
                });
            }
            $initialized = true;
        }
        $logs .= $this->sqlLogs;
        $this->sqlLogs = '';
        if (method_exists($response, 'exception') && $exception = $response->exception()) {
            $logs .= "[EXCEPTION] {$exception}\n";
        }
        \support\Log::info($logs, ['time' => $data['time']]);

        // 应用监控
        if ("app\\" . $request->app . "\controller\TransferStatistics" !== $request->controller) {
            $transfer = $request->controller . '::' . $request->action;
            if ('::' === $transfer) {
                $transfer = $request->path();
            }
            // 响应数据（发生异常）
            if (method_exists($response, 'exception') && $exception = $response->exception()) {
                $data['response_body'] = (string)$exception;
            } else {
                $data['response_body'] = true;
            }
            \Webman\RedisQueue\Client::send('webman_TransferStatistics', ['transfer' => $transfer] + $data);
        }
    }

    /**
     * 设置响应数据
     *
     * @author HSK
     * @date 2022-04-14 17:29:26
     *
     * @param Response $response
     *
     * @return void
     */
    protected function setResponse(Response &$response)
    {
        $response->withHeaders([
            'Server' => 'hsk99'
        ]);
    }
}
