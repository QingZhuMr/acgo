-- =====================================================
-- ACGO 聊天系统（第二期）
-- 设计见 docs/聊天系统设计.md
-- =====================================================

SET NAMES utf8mb4;

-- 用户 E2EE 公钥（私钥仅存客户端本地，永不上传）
CREATE TABLE IF NOT EXISTS `user_e2ee_key` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `user_id`     INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '用户ID',
  `public_key`  TEXT         NULL COMMENT 'X25519 公钥（base64）',
  `create_time` INT          NOT NULL DEFAULT 0  COMMENT '上传时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户聊天公钥表';

-- 聊天消息（密文，服务器零知识；30 天缓存自动清理）
CREATE TABLE IF NOT EXISTS `chat_message` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '消息ID',
  `chat_id`        VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '会话ID：min(sender,receiver)_max(sender,receiver)',
  `sender_id`      INT UNSIGNED    NOT NULL DEFAULT 0  COMMENT '发送者ID',
  `receiver_id`    INT UNSIGNED    NOT NULL DEFAULT 0  COMMENT '接收者ID',
  `msg_type`       TINYINT         NOT NULL DEFAULT 1  COMMENT '消息类型：1文本 2图片 3文件 4系统',
  `body`           TEXT            NULL COMMENT '消息密文（AES-GCM，服务器不可解密）',
  `nonce`          VARCHAR(64)     NOT NULL DEFAULT '' COMMENT 'AES-GCM nonce（base64）',
  `ephemeral_pub`  TEXT            NULL COMMENT '临时公钥（base64，用于 ECDH 密钥协商）',
  `client_msg_id`  VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '客户端消息ID（去重）',
  `status`         TINYINT         NOT NULL DEFAULT 1  COMMENT '消息状态：1正常 2撤回 3删除',
  `create_time`    INT             NOT NULL DEFAULT 0  COMMENT '发送时间',
  `expire_time`    INT             NOT NULL DEFAULT 0  COMMENT '过期时间（30天后），清理任务删除',
  PRIMARY KEY (`id`),
  KEY `idx_chat_time` (`chat_id`, `id`),
  KEY `idx_receiver_time` (`receiver_id`, `id`),
  KEY `idx_expire` (`expire_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='聊天消息表（密文，30天缓存）';
