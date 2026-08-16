-- =====================================================
-- ACGO 充值记录表
-- =====================================================
SET NAMES utf8mb4;

DROP TABLE IF EXISTS `recharge`;
CREATE TABLE `recharge` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`       INT UNSIGNED    NOT NULL DEFAULT 0    COMMENT '用户ID',
  `phone`         VARCHAR(20)     NOT NULL DEFAULT ''   COMMENT '用户手机号',
  `username`      VARCHAR(50)     NOT NULL DEFAULT ''   COMMENT '用户账号(冗余快照，已弃用)',
  `nickname`      VARCHAR(50)     NOT NULL DEFAULT ''   COMMENT '用户昵称',
  `currency`      VARCHAR(10)     NOT NULL DEFAULT 'cny' COMMENT '币种：cny人民币 paw熊爪',
  `amount`        DECIMAL(18,2)   NOT NULL DEFAULT 0    COMMENT '充值数量',
  `source`        VARCHAR(20)     NOT NULL DEFAULT 'manual' COMMENT '来源：manual人工 api接口',
  `operator_type` VARCHAR(10)     NOT NULL DEFAULT 'admin' COMMENT '操作人类型：admin管理员 system系统',
  `operator_id`   INT UNSIGNED    NOT NULL DEFAULT 0    COMMENT '操作人ID（管理员ID）',
  `operator_name` VARCHAR(50)     NOT NULL DEFAULT ''   COMMENT '操作人名称',
  `remark`        VARCHAR(255)    NOT NULL DEFAULT ''   COMMENT '备注（人工充值/支付宝充值/微信充值等）',
  `status`        TINYINT         NOT NULL DEFAULT 1    COMMENT '状态：1发起中 2核对中 3已完成 4已取消',
  `create_time`   INT             NOT NULL DEFAULT 0    COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_currency` (`currency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充值记录表';
