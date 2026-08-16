<?php
// admin 应用中间件定义
return [
    // Session 初始化（登录功能依赖）
    \think\middleware\SessionInit::class,
    // 登录鉴权
    \app\admin\middleware\CheckLogin::class,
];
