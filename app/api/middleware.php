<?php
// api 应用中间件定义
return [
    // 客户端密钥校验（所有接口要求 X-Api-Key）
    \app\api\middleware\ApiAuth::class,
];
