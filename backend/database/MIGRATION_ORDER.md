# 易运盈后台数据库升级顺序

全新安装只导入 `install.sql`，不要再执行升级脚本。已有数据库先完整备份，再按尚未执行的版本依次运行：

1. `upgrade_20260712_groups_admin_documents.sql`
2. `upgrade_20260712_fixed_document_shares.sql`
3. `upgrade_20260712_complete_balance_hierarchy.sql`
4. `upgrade_20260713_message_center.sql`
5. `upgrade_20260713_message_recall_policy.sql`
6. `upgrade_20260713_multimedia_social.sql`
7. `upgrade_20260713_interaction_navigation.sql`
8. `upgrade_20260713_profile_interactions.sql`
9. `upgrade_20260713_contact_groups.sql`
10. `upgrade_20260713_conversation_media_experience.sql`
11. `upgrade_20260713_chat_media_forward_search.sql`
12. `upgrade_20260713_media_cache_cloud_sync.sql`
13. `upgrade_20260713_identity_uid_registration.sql`
14. `upgrade_20260713_identity_review_scope.sql`
15. `upgrade_20260713_jianyun_capabilities.sql`
16. `upgrade_20260714_forum_experience.sql`
17. `upgrade_20260714_communication_takeover.sql`
18. `upgrade_20260714_forward_snapshot_privacy.sql`
19. `upgrade_20260714_chat_identity_settings.sql`
20. `upgrade_20260714_relationship_notifications.sql`
21. `upgrade_20260714_chat_commerce.sql`
22. `upgrade_20260714_message_edits.sql`
23. `upgrade_20260715_speech_transcription.sql`
24. `upgrade_20260715_group_album_media.sql`
25. `upgrade_20260715_message_replies.sql`
26. `upgrade_20260715_upload_limits.sql`
27. `upgrade_20260715_voice_calls.sql`
28. `upgrade_20260715_video_calls.sql`
29. `upgrade_20260715_voice_calls_context.sql`
30. `upgrade_20260717_group_file_folders.sql`
31. `upgrade_20260717_local_ai_festival_update.sql`
32. `upgrade_20260718_forum_taxonomy_privacy.sql`
33. `migrations/upgrade_20260718_management_review_notes.sql`
34. `upgrade_20260719_group_invite_history.sql`
35. `upgrade_20260719_privacy_notification_settings.sql`
36. `migrations/upgrade_20260720_moments.sql`
37. `migrations/upgrade_20260720_moment_privacy_interactions.sql`
38. `migrations/upgrade_20260720_moment_like_visibility.sql`
39. `migrations/upgrade_20260720_targeted_red_packets.sql`
40. `migrations/upgrade_20260720_red_packet_recipient_returns.sql`
41. `migrations/upgrade_20260721_red_packet_delivery_scope.sql`
42. `migrations/upgrade_20260721_group_vote_option_images.sql`
43. `migrations/upgrade_20260721_moment_pins.sql`
44. `migrations/upgrade_20260721_shop_commerce_closure.sql`
45. `migrations/upgrade_20260721_business_catalog_rewards.sql`
46. `migrations/upgrade_20260721_role_permission_center.sql`
47. `migrations/upgrade_20260722_random_red_packet_money.sql`
48. `migrations/upgrade_20260722_red_packet_dispatch_modes.sql`
49. `migrations/upgrade_20260722_remote_login_protection.sql`
50. `migrations/upgrade_20260722_bounty_moderation.sql`
51. `migrations/upgrade_20260725_submission_risk_metadata.sql`
52. `migrations/upgrade_20260731_chat_room_kind.sql`

The order of items 44 and 45 is mandatory: the commerce migration creates
`shop_categories`, and the catalog/reward migration extends that table.

第 13、14 项顺序不能颠倒：先创建 UID、身份绑定和解绑申请主体，再补充独立审核范围字段。通信接管必须在聊天、多媒体、身份和论坛迁移完成后执行；先建立匿名快照，再升级为用户匿名与 1/2/3 级实名审计双轨。第 28 项用于已经执行过第 27 项的数据库，为通话表补充音频/视频类型；第 29 项再加入群聊、聊天室等通话上下文。第 30 项补齐群文件夹与下载统计，第 31 项加入本地 AI 知识库/会话、节日界面策略和安装包校验字段，第 33 项补齐悬赏审核字段、管理视角和笔记日期检索，第 34 项加入群邀请历史消息可见边界，第 35 项补齐添加方式、通知渠道和动态可见对象，第 36、37 项依次建立生活动态主体与隐私互动能力，第 41 项补齐红包投放范围，第 42 项为群投票补充图片选项，第 43 项加入个人资料动态置顶顺序。执行后运行 `php tools/generate-reference.php` 可重新生成表结构参考；部署验收至少检查 `/api/health`、四级登录、`deploy/local-ai/verify-environment.sh`、`tools/smoke-identity-qr.ps1`、`tools/smoke-communication-takeover.ps1`、`tools/smoke-forward-privacy.ps1`、`tools/smoke-chat-commerce.ps1` 和 `tools/smoke-voice-calls.ps1`。
