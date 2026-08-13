# PC 管理端部署到阿里云（大陆）

适用：域名 `fdsdxc.xyz`（或你的域名）、Ubuntu + **宝塔面板**（最省事）。  
目标：浏览器能打开登录页并用 `admin` 进入后台。

> 备案未通过前，阿里云常会封 **80/443**。可先用「IP + 自定义端口」自测 PC 端；备案通过后再绑域名和 HTTPS。

---

## 零、你需要准备好

| 项 | 说明 |
|----|------|
| 大陆服务器 | 已开机，有公网 IP |
| SSH / 宝塔 | 能登录服务器 |
| 本机项目 | `d:\phpstudy_pro\WWW\team-maturity` |
| 上传方式 | 宝塔文件上传，或本机打包后 scp |

安全组放行：**8888**（宝塔）、**22**（SSH）、测试用端口如 **8080**；备案后放行 **80、443**。

---

## 一、服务器装宝塔（若还没有）

SSH 登录后（Ubuntu）按宝塔官网当前一键脚本安装，例如：

```bash
wget -O install.sh https://download.bt.cn/install/install-ubuntu_6.0.sh && sudo bash install.sh ed8484bec
```

装完记下面板地址、账号密码。浏览器打开面板（安全组放行 8888）。

推荐一键安装套件：

- **Nginx**
- **MySQL 5.7 或 8.0**
- **PHP 7.4**（7.3～8.0 均可；本项目按 7.3+ 开发）

PHP 扩展在宝塔 → **软件商店 → PHP 设置 → 安装扩展**，勾选：

`pdo_mysql`、`mbstring`、`openssl`、`curl`、`gd`、`fileinfo`、`opcache`

---

## 二、上传代码

建议目录：

```text
/www/wwwroot/team-maturity/
  backend/
  sql/
```

本机打包（在项目根目录 PowerShell）：

```powershell
cd d:\phpstudy_pro\WWW\team-maturity
# 打 zip 时带上 backend（含 vendor、thinkphp）和 sql
Compress-Archive -Path backend,sql -DestinationPath team-maturity-pc.zip -Force
```

用宝塔「文件」上传到 `/www/wwwroot/`，解压到 `team-maturity`。

也可只上传 `backend` + `sql`；**务必带上** `backend/vendor` 和 `backend/thinkphp`（若没带，见下文 composer）。

目录权限（SSH 或宝塔终端）：

```bash
cd /www/wwwroot/team-maturity
mkdir -p backend/runtime
chown -R www:www backend/runtime
chmod -R 775 backend/runtime
```

（宝塔用户一般是 `www`。）

---

## 三、建数据库并导入

宝塔 → **数据库** → 添加：

| 项 | 值 |
|----|-----|
| 库名 | `team_maturity` |
| 用户 | `tm_user`（自定） |
| 密码 | 强密码（记下） |
| 字符集 | `utf8mb4` |

导入顺序（宝塔「导入」或 phpMyAdmin），按文件名：

1. `sql/01-schema.sql`
2. `sql/02-fix-seed-utf8.sql`
3. `sql/03-pc-admin.sql`
4. `sql/04-question-pick-reserve.sql`
5. `sql/05-org-flexible-depth.sql`
6. `sql/06-member-self-register.sql`

若要从本机同步已有组织/题库/批次：本机导出整个 `team_maturity` 再导入服务器（可替代上面种子脚本）。

---

## 四、配置数据库连接与生产开关

在服务器创建（宝塔文件编辑）：

`/www/wwwroot/team-maturity/backend/application/config.local.php`

```php
<?php
return [
    'wx_appid'    => 'wxc23577d86aaf957c',
    'wx_secret'   => '',           // PC 先上线可先空；小程序要用时再填
    'dev_login'   => false,
    'wxacode_env' => 'trial',
];
```

库账号用环境变量（推荐）。宝塔 → 网站 → 你的站 → **配置文件**，在 `server { ... }` 里加（PHP-FPM 有时要用面板「环境变量」；更简单做法如下）。

**更简单：直接改** `backend/application/database.php` **仅在服务器上**（勿提交 Git），或用环境变量。

宝塔网站 → **设置 → 网站目录 / 伪静态** 之外，可用「配置文件」无法注入 env 时，在 SSH：

```bash
# 写入 PHP-FPM 环境（示例：宝塔 PHP 7.4）
# 更稳妥：在 /www/wwwroot/team-maturity/backend/application/database.php
# 把默认密码改成你的 tm_user 密码（仅服务器改，不提交仓库）
```

**推荐临时做法（只改服务器文件）：**  
编辑 `backend/application/database.php`，把默认改成：

```php
$dbPass = getenv('TM_DB_PASSWORD');
if ($dbPass === false || $dbPass === '') {
    $dbPass = '你的数据库密码';
}
$dbUser = getenv('TM_DB_USERNAME');
if ($dbUser === false || $dbUser === '') {
    $dbUser = 'tm_user';
}
```

并设环境变量（可选，宝塔「PHP 设置 → 配置文件」或 systemd）：

```bash
TM_APP_DEBUG=false
TM_JWT_SECRET=请换成随机长字符串
TM_DB_HOST=127.0.0.1
TM_DB_DATABASE=team_maturity
TM_DB_USERNAME=tm_user
TM_DB_PASSWORD=你的数据库密码
```

没有 env 时，至少保证 `database.php` 里默认用户密码能连上库，且 `TM_APP_DEBUG=false`（可在 `config.php` 依赖的环境变量里设，或暂时在面板 PHP 环境加）。

---

## 五、创建网站（关键）

宝塔 → **网站** → 添加站点：

| 项 | 值 |
|----|-----|
| 域名 | 备案前可先填 `服务器公网IP`；备案后改 `fdsdxc.xyz` |
| 根目录 | **`/www/wwwroot/team-maturity/backend/public`** |
| PHP 版本 | 7.4 |

**伪静态**：选 `thinkphp`，或自定义：

```nginx
location / {
    if (!-e $request_filename) {
        rewrite ^(.*)$ /index.php?s=$1 last;
        break;
    }
}
```

ThinkPHP 5 也常用：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

若打不开路由，两种都可试，以能打开登录页为准。

### 备案前用端口访问（可选）

网站设置 → **配置修改**，`listen 80` 改成例如 `listen 8080;`，安全组放行 8080。  
浏览器访问：`http://公网IP:8080/`

---

## 六、Composer（若未上传 vendor）

```bash
cd /www/wwwroot/team-maturity/backend
composer install --no-dev
```

本机已带 `vendor` 且已上传，可跳过。

---

## 七、验收 PC 端

浏览器打开：

- `http://公网IP:8080/`（端口自测），或  
- `http://fdsdxc.xyz/`（备案+解析+80 放行后）

| 检查 | 期望 |
|------|------|
| 打开首页 | 出现登录页 |
| 账号 | `admin` / `123456`（登录后立刻改密码） |
| 侧栏 | 测评批次、组织、指标版本等能打开 |
| runtime | 无权限报错 |

登录后：**系统管理 → 改管理员密码**。

---

## 八、备案通过后（域名 + HTTPS）

1. 云解析：`fdsdxc.xyz`（或 `www`）A 记录 → 服务器公网 IP  
2. 宝塔网站域名改为 `fdsdxc.xyz`  
3. 网站 → SSL → Let’s Encrypt 申请 → 强制 HTTPS  
4. 再测：`https://fdsdxc.xyz/`

---

## 常见问题

| 现象 | 处理 |
|------|------|
| 502 / 打不开 | PHP 没开；根目录是否指到 `backend/public` |
| 页面空白 / 500 | 看 `backend/runtime/log`；扩展是否装全；`runtime` 权限 |
| 数据库连接失败 | 库名/用户/密码；MySQL 是否启动 |
| 除首页外全 404 | 伪静态未配 |
| 80/443 不通 | 未备案被封；先用 8080 或等备案 |
| 验证码出不来 | 装 `gd` 扩展 |

---

## 部署完成后（PC 已好）

小程序员工扫码另做：配 `wx_secret`、合法域名、改 `miniprogram/config.js`、发体验版。见 `docs/上线检查表.md`。
