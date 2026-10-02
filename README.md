# 员工卫生考核系统 (Hygiene Audit System)

## 技术栈

- **Frontend**: Vue 3 + Vite + Tailwind CSS + Element Plus
- **Backend**: PHP 8 + ThinkPHP 8
- **Database**: MySQL 8.0（utf8mb4）

## 启动指南 (How to Run)

1. 确保 Docker Desktop 已启动。
2. 在项目根目录执行：`docker compose up --build`
3. 等待容器启动完成（数据库健康检查通过、后端与前端构建完成）。
4. 浏览器访问前端地址即可使用。

## 服务地址 (Services)

- **Frontend（Docker，推荐）**: **http://localhost:3000** — 执行 `docker compose up --build` 后访问此地址即可，无需再开 5173。
- **Frontend（本地 Vite 开发）**: http://localhost:5173 — 仅当需要热更新时使用，需**单独**在终端执行 `cd frontend && npm run dev`（Docker 不会启动 5173）。
- **Backend API**: http://localhost:8080
- **Database**: localhost:3306（user: root / pass: root）

### 访问不了 5173 时

- 若你只运行了 `docker compose up`：请改用 **http://localhost:3000** 访问前端，Docker 前端在 3000 端口。
- 若确实要用 5173（热更新开发）：在项目根目录新开一个终端，执行：
  ```bash
  cd frontend && npm run dev
  ```
  等终端出现 “Local: http://localhost:5173/” 后再用浏览器打开。此时需保证后端已启动（如 `docker compose up -d db backend`）。

## 测试账号与数据

- 系统通过 Seed 预置演示数据。
- **登录**：管理员端需先登录。默认账号：`admin` / `admin123`（首次登录会自动初始化密码）。
- **管理员-检查上传**：登录后打开 `/admin`，选择员工、上传问题图片（每张显示 key #1、#2… 与检查项、扣分值）、可删除单张（删除后序号自动连续）、保存后获得整改链接与二维码。
- **员工管理**：登录后打开 `/employees`，查看每名员工的 ID、token、整改链接与二维码（可点击「生成/刷新二维码」）。
- **员工端**：通过链接 `http://localhost:3000/fix?token=emp-token-001` 进入（或扫码），无需登录，查看待整改项（图片对按 #key 从小到大排序）并上传整改图。
- **汇总看板**：登录后打开 `/summary`，查看各员工整改进度与对比图；问题图与整改图成对展示，同一徽章（检查项+分值）共用。

### 已有数据库升级

若数据库已存在且缺少登录相关字段，可执行迁移脚本：

```bash
docker exec -i <mysql_container_name> mysql -uroot -proot hygiene_audit < backend/database/migrate_add_auth.sql
```

若需要为历史记录补充「检查日期」字段（用于按天筛选），可执行：

```bash
docker exec -i <mysql_container_name> mysql -uroot -proot hygiene_audit < backend/database/migrate_add_check_date.sql
```

脚本会为 `records.check_date` 赋值：优先取 `created_at` 的日期部分，缺失时使用当前日期。

若数据库是早期版本（序号曾按「员工 + 检查日期」分别编号，跨天后员工端/汇总页会出现重复的 #1、#2），请执行序号统一迁移：

```bash
docker exec -i <mysql_container_name> mysql -uroot -proot hygiene_audit < backend/database/migrate_sequence_per_user.sql
```

脚本会先把每名员工的历史记录按（检查日期、旧序号、id）压实为从 1 开始的**全局连续序号**，再把 `(user_id, sequence_key)` 升级为唯一索引（防止并发重号）。可重复执行。

## 序号（key）规则

- 编号在**同一员工下全局连续**（跨检查日期），从已有最大序号继续生成 #1、#2…，单张上传与分批上传规则一致。
- 删除某张问题图后，后续序号自动整体前移一位，展示始终连续、不跳号。
- 并发保存/删除由员工级咨询锁串行化，并由数据库唯一索引兜底。

## Docker 说明

- 数据库使用 `utf8mb4` 字符集，连接时指定 charset。
- 前端构建时通过 `VITE_API_BASE=http://localhost:8080` 指定后端地址，浏览器直接请求后端 API。
- 后端通过服务名 `db` 连接 MySQL，不依赖本地环境。
