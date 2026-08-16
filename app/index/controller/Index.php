<?php

namespace app\index\controller;

use app\BaseController;

class Index extends BaseController
{
    public function index()
    {
        return '这是 Index 应用（绑定 127.0.1.1）';
    }
}
