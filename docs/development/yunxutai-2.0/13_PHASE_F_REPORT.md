# 阶段 F 交付报告：家族时间轴、数字展馆与统一通知中心

## 结论
已完成。新增 3 张业务表（6 条建表语句）与 3 个服务，Web 端可创建/查看家族事件、创建/发布展馆、查看与管理通知；发布展馆时强制以访客视角复核每个内容的可见性，私密档案与活人资料不可被带出。

## 新增文件
- `database/migrations/20261127_022_create_family_events.php` ~ `024_create_notifications.php`
- `app/Services/FamilyTimelineService.php`、`ExhibitionService.php`、`NotificationService.php`
- `app/Controllers/TimelineController.php`、`ExhibitionController.php`、`NotificationController.php`
- `app/Views/timeline/index.php`、`app/Views/exhibition/{index,show}.php`、`app/Views/notification/index.php`
- 本报告

## 修改文件
- `database/schema.sql`：追加阶段 F 表结构（与迁移同源）
- `app/Core/Policy.php`：新增 `timeline.edit` 能力（owner/admin/genealogy_editor）
- `app/routes.php`：新增 13 条路由（时间轴 3、展馆 6、通知 4，均带 auth）
- `tests/run.php`：新增 4 项阶段 F 测试

## 数据库变化
- 新表：`family_events`、`family_event_persons`、`exhibitions`、`exhibition_items`、`notifications`、`notification_preferences`；无旧表删除/改名。
- 测试库两次迁移均通过；生产库未动。

## 安全设计
- 事件按家族 privacy + 事件 privacy 双层过滤；成员按角色可见，访客仅见公开家族中的公开事件。
- 展馆草稿/下线内容访客不可见；发布前逐个校验内容：私密封档不可公开、活人人物资料不可公开、跨家族内容不可加入。
- 通知属于用户私有资源，markRead 强制 user_id 匹配；偏好按用户+类型唯一；邮件失败只落 failed 状态，不影响主流程。
- 纪念日提醒从事件创建者出发，不向其他成员自动发送。

## 测试结果
- `bash bin/test.sh`：101 通过 / 0 失败 / 0 跳过（阶段 E 的 97 项全部保留）。
- `php bin/doctor.php`：21 通过 / 4 环境警告 / 0 阻断。
- 全量 PHP lint：0 错误。

## 未决事项
- 展馆前端目前为列表/卡片式内容展示，未实现拖动排序与多媒体画廊。
- 小程序 API 与小程序页面在阶段 G 接入。
- 生产库迁移仍需用户授权与备份演练；机构租户仅预留 `owner_type/institution_key` 字段，未实现机构后台。
