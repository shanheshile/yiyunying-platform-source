# Android 客户端

原生 Android 应用，使用 Java 17 和 Android SDK 36。四个 product flavor 分别对应平台总控、授权平台、管理员和用户端，共享聊天、媒体、缓存、权限与设计系统。

版本由 `version.properties` 管理。API 与平台密钥不得写死，可通过 Gradle 属性或环境变量注入：

```powershell
.\gradlew.bat assembleUserDebug -PapiBaseUrl=https://api.example.com/
```

常用校验：

```powershell
.\gradlew.bat testPlatformOwnerDebugUnitTest testAuthorizedPlatformDebugUnitTest testAdminDebugUnitTest testUserDebugUnitTest
.\gradlew.bat assemblePlatformOwnerDebug assembleAuthorizedPlatformDebug assembleAdminDebug assembleUserDebug
```

锁屏来电、推送、悬浮窗、后台保活和文件/媒体权限必须在真实目标设备上验证。
