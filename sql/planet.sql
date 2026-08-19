-- =====================================================
-- ACGO 星球系统 - 建表脚本
-- 数据库：acgo
-- 说明：玩家星球养成，一人一颗星球（uid 唯一）
-- =====================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `planet`;
CREATE TABLE `planet` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `uid`         INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '玩家UID，对应user.id，唯一',
  `name`        VARCHAR(50)   NOT NULL DEFAULT ''   COMMENT '星球名字',
  `seed`        INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '星球种子（决定表面形态与初始资源）',
  `water`       INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '水资源（与水材质绑定，越多海洋占比越大）',
  `metal`       INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '金属资源',
  `oxygen`      INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '氧气资源（初始星球为0）',
  `rock`        INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '岩石资源',
  `stage`       VARCHAR(20)   NOT NULL DEFAULT 'magma' COMMENT '星球阶段：magma=岩浆活动期',
  `status`      TINYINT(1)    NOT NULL DEFAULT 1    COMMENT '状态：0删除 1正常',
  `create_time` INT           NOT NULL DEFAULT 0    COMMENT '创建时间',
  `update_time` INT           NOT NULL DEFAULT 0    COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='玩家星球表';
