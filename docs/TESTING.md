# 测试说明

## 一键校验

```powershell
.\scripts\verify.ps1
```

该脚本执行：

- Android 四角色单元测试与 Debug APK 构建
- 全量 PHP 语法检查
- 下载中心依赖锁定安装、Lint 与渲染测试
- 版本链一致性、疑似密钥和超大文件检查

## 隔离 MariaDB 最小功能闭环

Windows 本机已安装 MariaDB 11.4.5 与 PHP `pdo_mysql/curl/mbstring` 扩展时，可执行：

```powershell
.\backend\tools\run-minimum-closure-local.ps1
```

脚本每次创建独立数据库目录和随机端口，使用随机数据库密码、随机应用密钥及仅通过进程环境传递的随机测试口令，严格导入当前 `install.sql`，再通过真实 HTTP 依次执行 `core`、`forum`、`notifications`、`chat-commerce` 四条闭环。SQL `SOURCE` 任一错误、服务启动失败或任何 smoke 失败都会非零退出；服务进程在 `finally` 中按 PID 停止。证据默认保存在仓库外的 `D:\易运盈\.test-evidence\minimum-closure\<run-id>`，不能提交，也不能把隔离测试身份用于生产。

定向复测可使用：

```powershell
.\backend\tools\run-minimum-closure-local.ps1 -Suites core,forum
```

## 必须真机验证的场景

- Android 14/15/16，至少覆盖小米、vivo、OPPO/一加、华为/荣耀和原生 Android
- 刘海/挖孔屏、手势导航、三键导航、字体放大、深浅主题和语言切换
- 后台消息、锁屏来电、全屏通知、震动/铃声、悬浮窗、免打扰和省电限制
- 语音/视频双向媒体、前置镜像、大小窗交换、弱网、超时、占线和任意一方挂断
- 多图选择、50MB 图片、大视频、HTTP 413、断点上传、GIF/动态照片与文件预览
- 聊天滚动锚点、新消息提示、离线只读、撤回/编辑、嵌套转发和匿名边界
- 红包随机/按份、转账退回、24 小时过期、订单、余额与审计一致性
- 更新下载、进度条、APK 有效性、签名连续性、强制更新和维护模式

## 回归原则

每个崩溃修复必须保留异常堆栈、触发步骤和对应测试。Activity/Fragment 回调在使用 Context、Glide 或提交 FragmentTransaction 前必须检查生命周期状态。
