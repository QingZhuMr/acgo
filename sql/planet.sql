-- =====================================================
-- ACGO 星球表（后台创建，客户端星球页展示/切换）
-- 每个星球存种子（决定程序化生成的星球外观）、名字、介绍。
-- 同一种子 → 同一个星球外观。
-- =====================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `planet`;
CREATE TABLE `planet` (
  `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `seed`        INT UNSIGNED     NOT NULL DEFAULT 0     COMMENT '星球种子（创建时自动生成，同种子同星球）',
  `type`        VARCHAR(20)      NOT NULL DEFAULT 'earth' COMMENT '星球类型：earth类地/jupiter气态/lava岩浆/rock岩石/gradient_green绿渐变/gradient_blue蓝渐变/gradient_pink粉渐变/gradient_red红渐变/blackhole黑洞',
  `name`        VARCHAR(50)      NOT NULL DEFAULT ''    COMMENT '星球名称',
  `intro`       VARCHAR(500)     NOT NULL DEFAULT ''    COMMENT '星球介绍',
  `sort`        INT              NOT NULL DEFAULT 0     COMMENT '排序权重，越小越靠前',
  `status`      TINYINT          NOT NULL DEFAULT 1     COMMENT '状态：0禁用 1启用',
  `create_time` INT              NOT NULL DEFAULT 0     COMMENT '创建时间',
  `update_time` INT              NOT NULL DEFAULT 0     COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`, `sort`),
  KEY `idx_seed` (`seed`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='星球表';

-- -----------------------------------------------------
-- 后台菜单：星球管理（挂到顶级「运营」/「运营管理」菜单下）
-- 注：不硬编码父级 id（不同环境菜单 id 可能不同），按标题动态查找。
-- -----------------------------------------------------
INSERT INTO `menu` (`parent_id`, `title`, `icon`, `url`, `level`, `sort`, `status`, `create_time`)
SELECT `id`, '星球管理', 'fas fa-globe', '/planet/index', 1, 2, 1, UNIX_TIMESTAMP()
FROM `menu`
WHERE `parent_id` = 0 AND `title` IN ('运营', '运营管理')
ORDER BY `id` ASC
LIMIT 1;
