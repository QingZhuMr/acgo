-- =====================================================
-- ACGO 实时推送队列表
-- admin/api 后台操作时写入待推送消息，Workerman WS 服务轮询该表并推送。
-- =====================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `push_queue`;
CREATE TABLE `push_queue` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '目标用户ID(0=广播)',
  `platform`    VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '目标平台：win/mac/ios/android/h5，空=不限制',
  `device_id`   VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '目标设备ID，空=不限制',
  `payload`     TEXT         NOT NULL COMMENT '推送消息体(JSON，含type字段)',
  `pushed`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否已推送：0待推 1已推',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_pushed_id` (`pushed`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='实时推送队列';
