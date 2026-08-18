-- =====================================================
-- ACGO 聊天系统（第二期）
-- 设计见 docs/聊天系统设计.md
-- =====================================================

SET NAMES utf8mb4;

-- 用户 E2EE 密钥（公钥 + 托管私钥；私钥 AES 加密托管，首次领取后置空）
-- 设计：服务器在用户无公钥时自动生成密钥对托管，保证"总能发出去、对方一定能收到"
CREATE TABLE IF NOT EXISTS `user_e2ee_key` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `user_id`               INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '用户ID',
  `public_key`            TEXT         NULL COMMENT 'RSA 公钥（PEM，他人加密用）',
  `private_key_encrypted` TEXT         NULL COMMENT 'RSA 私钥（AES-256 加密托管，客户端领取后置空）',
  `create_time`           INT          NOT NULL DEFAULT 0  COMMENT '上传时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户聊天密钥表（含托管私钥）';

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

-- 已读回执（会话内每用户最后已读消息 id）
CREATE TABLE IF NOT EXISTS `chat_read` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `chat_id`          VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '会话ID',
  `user_id`          INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '读者ID',
  `last_read_msg_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已读到的最大消息ID',
  `update_time`      INT          NOT NULL DEFAULT 0  COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_user` (`chat_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='聊天已读回执表';
