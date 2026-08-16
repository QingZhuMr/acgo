-- =====================================================
-- ACGO 表情包表（客户端聊天/评论表情包上传）
-- =====================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `sticker`;
CREATE TABLE `sticker` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '表情包ID',
  `user_id`     INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '上传用户ID',
  `url`         VARCHAR(255) NOT NULL DEFAULT '' COMMENT '访问相对路径',
  `width`       INT          NOT NULL DEFAULT 0  COMMENT '宽度',
  `height`      INT          NOT NULL DEFAULT 0  COMMENT '高度',
  `size`        INT          NOT NULL DEFAULT 0  COMMENT '文件字节数',
  `mime`        VARCHAR(20)  NOT NULL DEFAULT 'png' COMMENT '格式：png/gif',
  `status`      TINYINT      NOT NULL DEFAULT 1  COMMENT '状态：0已删除 1正常',
  `create_time` INT          NOT NULL DEFAULT 0  COMMENT '上传时间',
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表情包表';
