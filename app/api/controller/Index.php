<?php

namespace app\api\controller;

use app\BaseController;

class Index extends BaseController
{
    public function index()
    {
        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'app' => 'api',
                'domain' => '127.0.1.3',
            ],
        ]);
    }
}
