# 应用内网络通话

## 能力边界

易运盈的语音与视频通话是应用内 WebRTC 网络通话，只允许同一应用内、具备合法会话关系的用户呼叫。它不调用运营商电话，不消耗手机话费，不把通话媒体写入聊天附件，也不会自动录音或录像。

Android 客户端已实现：

- 语音呼叫、视频呼叫、来电接听、拒绝、取消和挂断
- 麦克风开关，语音通话默认使用听筒，可手动切换扬声器
- 前后摄像头切换，本地与远端视频画面
- 通话时长、前台服务和高优先级来电通知
- Android 系统原生画中画，返回桌面后保留视频或通话状态与计时
- 画中画由系统负责拖动、边缘停靠和关闭，不申请高风险的全局悬浮窗权限

“拍摄后保存到本地”只影响聊天中的拍照与录像，不影响通话；通话始终不会自动保存。

## API

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| POST | `/api/user/voice-calls` | 发起通话，传 `target_user_id`、`call_type` 和 `offer` |
| GET | `/api/user/voice-calls/incoming` | 查询当前来电 |
| GET | `/api/user/voice-calls/{call_id}` | 查询通话状态、参与方、ICE 与信令游标 |
| POST | `/api/user/voice-calls/{call_id}/answer` | 接听并提交 `answer` |
| POST | `/api/user/voice-calls/{call_id}/decline` | 拒绝来电 |
| POST | `/api/user/voice-calls/{call_id}/hangup` | 取消或挂断 |
| POST | `/api/user/voice-calls/{call_id}/signals` | 发送 offer、answer 或 ICE candidate |
| GET | `/api/user/voice-calls/{call_id}/signals` | 使用 `after_id` 增量拉取信令 |

所有接口均要求用户令牌和匹配的 `X-App-Key`。服务端会再次校验调用者是否为主叫或被叫，其他用户无法读取通话详情或信令。

## 状态机

```text
ringing
|- 被叫接听 -> active -> 任一方挂断 -> ended
|- 被叫拒绝 -> declined
|- 主叫取消 -> cancelled
`- 超时未接 -> missed
```

结束状态不可再次接听或写入信令。服务端记录状态、时间和必要的故障原因，不记录媒体内容。

## ICE 与 TURN

开发环境可仅配置 STUN：

```text
VOICE_CALL_ICE_SERVERS=[{"urls":["stun:stun.l.google.com:19302"]}]
```

生产环境必须使用自己控制的 TURN，尤其是双方处于严格 NAT、企业网络、校园网或部分移动网络时。示例：

```text
VOICE_CALL_ICE_SERVERS=[{"urls":["stun:turn.example.com:3478","turn:turn.example.com:3478?transport=udp","turn:turn.example.com:3478?transport=tcp"],"username":"replace-me","credential":"replace-me"}]
```

宝塔等无法可靠向 PHP-FPM 注入环境变量的环境，也可以把同一段 JSON 写入
`storage/voice-call-ice-servers.json`。该文件位于网站 `public` 根目录之外，必须限制为仅服务器运行用户可读，且不得加入源码包或公开备份。环境变量存在且有效时仍优先使用环境变量。

TURN 凭据应通过服务器环境变量注入，不能写进 Android 安装包、Git 仓库或公开 API 文档。服务器安全组、防火墙和 TURN 服务都必须放行实际配置的 UDP/TCP 端口。

## 数据库升级

已有数据库按顺序执行：

```text
database/upgrade_20260715_voice_calls.sql
database/upgrade_20260715_video_calls.sql
```

升级前必须备份数据库。全新安装直接导入 `database/install.sql`。

## 闭环验证

```powershell
powershell -ExecutionPolicy Bypass -File tools/smoke-voice-calls.ps1 -BaseUrl http://127.0.0.1:8788
```

脚本覆盖语音发起、来电、offer、接听、answer 信令、挂断，以及视频发起、类型校验、拒绝和终态校验。真实音视频质量还需要两台 Android 设备分别在 Wi-Fi、移动网络和跨 NAT 环境测试。
