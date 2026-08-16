-- =====================================================
-- ACGO 广场（帖子）模块数据表
-- 业务场景：类小红书帖子 —— 发帖 / 回帖 / 举报 / 热度推荐
-- =====================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------
-- 1. 帖子表
-- -----------------------------------------------------
DROP TABLE IF EXISTS `post`;
CREATE TABLE `post` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '帖子ID',
  `user_id`       INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '发帖用户ID（头像/昵称改为实时联表 user 查询，不再冗余）',
  `content`       TEXT         NOT NULL COMMENT '帖子文字内容（限2000字）',
  `images`        TEXT         COMMENT '图片列表 JSON 数组（相对路径，最多9张）',
  `heat`          INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '热度值：回帖+1，后台可手动增加，影响随机推荐权重',
  `comment_count` INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '评论总数（冗余，性能）',
  `favorite_count` INT         NOT NULL DEFAULT 0  COMMENT '收藏数',
  `report_count`  INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '被举报次数',
  `status`        TINYINT      NOT NULL DEFAULT 1  COMMENT '状态：0已删除 1正常 2待审核',
  `create_time`   INT          NOT NULL DEFAULT 0  COMMENT '发布时间',
  `update_time`   INT          NOT NULL DEFAULT 0  COMMENT '更新时间',
  `ip`            VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '发布IP',
  `location`      VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '归属地(省/直辖市/未知)',
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status_time` (`status`, `create_time`),
  KEY `idx_heat` (`heat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='广场帖子表';

-- -----------------------------------------------------
-- 2. 帖子评论表
-- -----------------------------------------------------
DROP TABLE IF EXISTS `post_comment`;
CREATE TABLE `post_comment` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '评论ID',
  `post_id`       INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '所属帖子ID',
  `user_id`       INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '评论用户ID',
  `parent_id`     INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '父评论ID，0为顶层评论',
  `reply_to_uid`  INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '回复的目标用户ID（楼中楼）',
  `reply_to_name` VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '回复的目标用户昵称',
  `content`       VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '评论内容（限500字）',
  `images`        TEXT         COMMENT '评论图片URL数组(JSON)',
  `report_count`  INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '被举报次数',
  `like_count`    INT          NOT NULL DEFAULT 0  COMMENT '点赞数',
  `status`        TINYINT      NOT NULL DEFAULT 1  COMMENT '状态：0已删除 1正常',
  `create_time`   INT          NOT NULL DEFAULT 0  COMMENT '评论时间',
  `ip`            VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '评论IP',
  `location`      VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '归属地(省/直辖市/未知)',
  PRIMARY KEY (`id`),
  KEY `idx_post` (`post_id`, `status`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='广场帖子评论表';

-- -----------------------------------------------------
-- 3. 举报表
-- -----------------------------------------------------
DROP TABLE IF EXISTS `post_report`;
CREATE TABLE `post_report` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '举报ID',
  `post_id`         INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '被举报帖子ID',
  `comment_id`      INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '被举报评论ID，0表示举报帖子本身',
  `user_id`         INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '举报人ID',
  `reason`          VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '举报原因类型（spam/色情/辱骂/其他）',
  `content`         VARCHAR(500) NOT NULL DEFAULT '' COMMENT '举报补充说明',
  `handle_admin_id` INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '处理人管理员ID',
  `handle_time`     INT          NOT NULL DEFAULT 0  COMMENT '处理时间',
  `handle_remark`   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '处理备注',
  `result`          TINYINT      NOT NULL DEFAULT 0  COMMENT '处理结果 0待处理 1已处理 2已删除',
  `status`          TINYINT      NOT NULL DEFAULT 0  COMMENT '处理状态：0待处理 1已处理',
  `create_time`     INT          NOT NULL DEFAULT 0  COMMENT '举报时间',
  PRIMARY KEY (`id`),
  KEY `idx_post` (`post_id`),
  KEY `idx_status` (`status`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='广场举报表';

-- -----------------------------------------------------
-- 4. 点赞表（唯一索引防重复点赞）
-- -----------------------------------------------------
DROP TABLE IF EXISTS `post_like`;
CREATE TABLE `post_like` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `post_id`     INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '帖子ID',
  `user_id`     INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '点赞用户ID',
  `create_time` INT          NOT NULL DEFAULT 0  COMMENT '点赞时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_post_user` (`post_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='广场帖子点赞表';

-- -----------------------------------------------------
-- 5. 帖子收藏表（唯一索引防重复收藏）
-- -----------------------------------------------------
DROP TABLE IF EXISTS `post_favorite`;
CREATE TABLE `post_favorite` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '收藏ID',
  `post_id`     INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '帖子ID',
  `user_id`     INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '用户ID',
  `create_time` INT          NOT NULL DEFAULT 0  COMMENT '收藏时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_post_user` (`post_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='帖子收藏表';

-- -----------------------------------------------------
-- 6. 评论点赞表（唯一索引防重复点赞）
-- -----------------------------------------------------
DROP TABLE IF EXISTS `comment_like`;
CREATE TABLE `comment_like` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '点赞ID',
  `comment_id`  INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '评论ID',
  `user_id`     INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '用户ID',
  `create_time` INT          NOT NULL DEFAULT 0  COMMENT '点赞时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_comment_user` (`comment_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论点赞表';

-- -----------------------------------------------------
-- 7. 敏感词表（文字审核）
-- -----------------------------------------------------
DROP TABLE IF EXISTS `sensitive_word`;
CREATE TABLE `sensitive_word` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `word`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '敏感词',
  `create_time` INT          NOT NULL DEFAULT 0  COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_word` (`word`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='敏感词表（文字审核用）';

-- -----------------------------------------------------
-- 后台菜单：在「运营」顶级菜单（parent_id=0 且 url 为空）下新增「广场帖子」子菜单
-- 注意：父菜单 id 不硬编码，用子查询动态定位，避免用户手动重建菜单后 id 漂移导致孤儿菜单
-- -----------------------------------------------------
INSERT INTO `menu` (`id`, `parent_id`, `title`, `icon`, `url`, `level`, `sort`, `status`, `create_time`, `update_time`)
SELECT 12,
       (SELECT id FROM (SELECT id FROM `menu` WHERE parent_id=0 AND url='' AND title LIKE '运营%' ORDER BY id LIMIT 1) AS t),
       '广场帖子', 'fas fa-comments', '/post/index', 1, 1, 1, UNIX_TIMESTAMP(), 0
ON DUPLICATE KEY UPDATE `parent_id`=VALUES(parent_id), `title`='广场帖子', `url`='/post/index', `status`=1, `update_time`=UNIX_TIMESTAMP();

-- -----------------------------------------------------
-- 已有环境迁移：帖子表头像/昵称改为实时联表 user 查询，删除冗余列
-- -----------------------------------------------------
-- ALTER TABLE `post` DROP COLUMN `nickname`, DROP COLUMN `avatar`;
