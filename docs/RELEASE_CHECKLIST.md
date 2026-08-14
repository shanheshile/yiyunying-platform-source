# 发布检查表

## 版本

- [ ] `android/version.properties` 的版本号和版本码已递增
- [ ] APK 内 manifest 版本与源码一致
- [ ] 更新 API 和 `download-site/release-metadata.json` 与 APK 一致
- [ ] 下载中心展示的版本、大小、SHA-256 和链接正确
- [ ] 四端包名、versionName、versionCode 和签名证书指纹已写入发布台账
- [ ] versionCode 未被历史版本使用，且四端保持单调递增

## 构建与测试

- [ ] 四角色单元测试通过
- [ ] 四角色 Debug/Release 构建通过
- [ ] PHP 全量语法检查通过
- [ ] 下载中心 build、lint、rendered HTML test 通过
- [ ] Android 16 多品牌真机回归完成
- [ ] Stable Finalize 已二选一通过完整四角色 `device-upgrade-evidence.json`，或仅对 `1.0.0/code66` 使用显式单次 `release-risk-waiver.json`；豁免必须在官网显示“真机验证待用户完成”，不得写成真机通过
- [ ] 至少验证一次从上一正式版覆盖安装到本版
- [ ] 公网 APK 前四字节为 `PK\x03\x04`，不是 HTML 错误页

## 安全

- [ ] 密钥扫描无真实凭据
- [ ] 仓库无生产 SQL、用户数据、签名文件和服务器备份
- [ ] 演示账号已在生产环境删除
- [ ] 上传、支付、权限和审计策略已复核

## 部署

- [ ] 数据库已备份并验证恢复
- [ ] 后端健康检查与关键 API 正常
- [ ] 过期红包/转账、通知、缓存定时任务正常
- [ ] TURN、推送、地图、邮件、AI、天气和支付配置正常
- [ ] APK 返回正确 MIME，不是 HTML 错误页，签名可覆盖安装
- [ ] 数据库四端更新策略与发布清单一致
- [ ] `/api/public/lifecycle` 四种 edition 核验通过 4/4
- [ ] 公网 APK Content-Length、MIME、Range 与发布清单一致
- [ ] 静态站备份、数据库备份和发布台账路径已记录
- [ ] 发布中断遗留的暂存目录已归档或清理

## 回滚

- [ ] 保留上一稳定版本 APK、后端包和数据库迁移回滚方案
- [ ] 强制更新和维护开关可独立关闭
- [ ] 发布后监控崩溃、接口 5xx、消息延迟、通话失败和资金异常
- [ ] 下载、哈希、签名、解析和安装失败均有脱敏遥测或人工核验记录
