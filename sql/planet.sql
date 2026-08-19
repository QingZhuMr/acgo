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
  `name`        VARCHAR(50)      NOT NULL DEFAULT ''    COMMENT '星球名称',
  `intro`       VARCHAR(500)     NOT NULL DEFAULT ''    COMMENT '星球介绍',
  `sort`        INT              NOT NULL DEFAULT 0     COMMENT '排序权重，越小越靠前',
  `status`      TINYINT          NOT NULL DEFAULT 1     COMMENT '状态：0禁用 1启用',
  `create_time` INT              NOT NULL DEFAULT 0     COMMENT '创建时间',
  `update_time` INT              NOT NULL DEFAULT 0     COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`, `sort`),
  KEY `idx_seed` (`seed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='星球表';

-- -----------------------------------------------------
-- 后台菜单：星球管理（挂到「运营管理」parent_id=10 下）
-- -----------------------------------------------------
INSERT INTO `menu` (`parent_id`, `title`, `icon`, `url`, `level`, `sort`, `status`, `create_time`) VALUES
(10, '星球管理', 'fas fa-globe', '/planet/index', 1, 1, 1, UNIX_TIMESTAMP());
