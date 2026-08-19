<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 星球列表接口（api 应用）
 *
 * 客户端星球页进入时拉取后台创建的星球列表，
 * 展示星球名称/介绍，并按 seed 程序化生成对应星球外观。
 * 该接口为公开只读数据，游客与登录用户均可访问。
 */
class Planet extends BaseController
{
    /**
     * 星球列表
     * GET /planet/list
     *
     * 返回启用的星球，按 sort 升序、id 升序。
     * data 每项：{id, seed, name, intro}
     */
    public function list()
    {
        $list = Db::name('planet')
            ->where('status', 1)
            ->order('sort asc, id asc')
            ->field('id, seed, name, intro')
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $item['seed']  = (int) $item['seed'];
            $item['intro'] = (string) $item['intro'];
        }
        unset($item);

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => $list,
        ]);
    }
}
