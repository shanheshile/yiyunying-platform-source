# 数据库

开发初始化脚本位于 `../backend/database/install.sql`。

本目录特意不包含生产数据库导出。部署时应使用独立数据库账号，并通过 `backend/.env` 或环境变量配置连接信息。任何真实用户数据、聊天记录、资金流水和 Token 都不得提交到 Git。
