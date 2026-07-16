# 收钱吧支付插件 (SqbPay)

收钱吧码牌支付网关插件，支持轮询查询匹配支付。

---

## 代码结构

```
public/plugins/gateways/sqb_pay/
│
├── SqbPayPlugin.php              # 插件入口
│   ├── install()                 #   安装：创建 shd_sqb_pay_pending 表
│   ├── uninstall()               #   卸载：删除 shd_sqb_pay_pending 表
│   ├── SqbPayHandle()            #   支付处理：额度校验 → 生成唯一金额 → 写入待支付表 → 返回支付页面
│   ├── checkQuota()              #   额度校验：查询收钱吧可用额度，超限返回错误信息
│   ├── buildErrorHtml()          #   构建错误提示 HTML 页面
│   ├── buildPayHtml()            #   构建支付 HTML 页面
│   ├── getUniqueAmount()         #   生成唯一金额（整数分比较，避免浮点问题）
│   └── config()                  #   读取插件配置
│
├── model/
│   └── SqbPayPendingModel.php    # 待支付记录 Model（ThinkPHP 5.1 ORM）
│       ├── addPending()          #   添加待支付记录（金额转分存储）
│       ├── removePending()       #   移除单条记录
│       ├── removeBatchPending()  #   批量移除记录
│       ├── findInvoiceByAmount() #   按金额（分）查找发票
│       ├── clearExpired()        #   清理过期记录
│       ├── clearOlderThan()      #   清理指定时间之前的记录
│       ├── getExistingAmounts()  #   获取所有有效金额（排除指定发票）
│       ├── getEarliestCreateTime() # 获取最早记录的创建时间
│       └── clearAll()            #   清空全部记录
│
├── lib/
│   └── SqbPayCore.php            # 核心业务逻辑
│       ├── login()               #   登录收钱吧 API，获取 Token
│       ├── queryQuota()          #   查询收钱吧账户额度
│       ├── queryTransactions()   #   查询交易列表
│       ├── matchPayments()       #   匹配支付：遍历交易 → 查待支付表 → check_pay → 清理记录
│       └── 静态代理方法           #   addPending / removePending / findInvoiceByAmount 等
│
├── command/
│   └── SqbPolling.php            # 轮询命令（php think sqb_polling）
│   ├── execute()                 #   主流程：清理过期 → 查询待支付 → 匹配交易
│   └── cancelExpiredInvoices()   #   取消超时订单（已禁用，按需启用）
│
├── controller/
│   └── IndexController.php       # 前端控制器
│   ├── pay()                     #   支付页面
│   └── pollingQuery()            #   手动查询支付状态（AJAX 接口）
│
├── config.php                    # 插件配置项定义
├── SqbPay.png                    # 收钱吧 Logo 图片
└── README.md                     # 本文档
```

---

## 数据库设计

### shd_sqb_pay_pending（待支付记录表）

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT UNSIGNED AUTO_INCREMENT | 主键 |
| `invoice_id` | VARCHAR(64) UNIQUE | 发票ID |
| `amount` | INT UNSIGNED | 金额（**分**，整数存储） |
| `create_time` | INT UNSIGNED | 创建时间戳 |
| `expire_time` | INT UNSIGNED | 过期时间戳 |

**索引：**
- `uk_invoice_id` — invoice_id 唯一索引
- `uk_amount_expire` — 金额+过期时间联合唯一索引（防并发重复金额）
- `idx_expire_time` — 过期时间索引（清理查询用）

---

## 设计方案

### 核心问题与解决方案

#### 1. 金额匹配的浮点/字符串类型问题

**问题**：PHP 中浮点数 `10.0` 和字符串 `"10.00"` 作为数组 key 时，字符串表示不同（`"10"` vs `"10.00"`），导致匹配失败。

**方案**：所有金额统一用 **整数分**（元 × 100 取整）存储和比较：
- 数据库 `amount` 字段存分（INT）
- API 返回的 `paid_amount` 直接作为分使用
- 比较时 `(int) round($yuan * 100) === $amountCents`

#### 2. 缓存不可靠问题

**问题**：原方案使用多个 `cache()` 调用存储待支付记录，key 类型不一致导致匹配失败。

**方案**：用数据库表 `shd_sqb_pay_pending` 替代缓存，配合 ThinkPHP 5.1 Model 操作：
- 持久化存储，重启不丢失
- 事务支持，数据一致
- 通过索引高效查询

#### 3. 交易查询时间范围

**问题**：固定查最近 5 分钟，可能遗漏更早的支付。

**方案**：从待支付表取最早一条记录的 `create_time` 作为查询起点：
```php
$earliestTime = SqbPayPendingModel::getEarliestCreateTime();
$startTime = $earliestTime ?: (time() - 300);
```

#### 4. 额度校验

**问题**：生成二维码前未校验账户额度，可能导致用户支付金额超过收钱吧最大可用额度，支付失败。

**方案**：在生成二维码之前调用收钱吧 `queryQuota` 接口查询可用额度，支付金额超过 `totalQuota` 时返回错误提示页面，不生成二维码。

**流程**：
```
SqbPayHandle()
  ├── checkQuota()           ← 调用 queryQuota 接口
  │   ├── totalQuota（分）与 amountCents（分）比较
  │   ├── 超限 → 返回错误信息字符串
  │   ├── 接口异常 → 返回错误信息字符串
  │   └── 通过 → 返回 null
  ├── 错误时 → buildErrorHtml() 返回错误页面（不生成二维码）
  └── 通过 → 继续生成唯一金额和二维码
```

**API 调用**：
- 接口：`POST /api/queryQuota`
- 鉴权：复用 `login()` 获取的 token，通过 query 参数传递
- 响应：`code === 50000` 为成功，`data.totalQuota` 为最大可用额度（单位：分）

#### 5. 最大上浮金额配置

**问题**：生成唯一金额时的上浮上限硬编码为 1000 分（10 元），无法灵活调整。

**方案**：将上浮上限改为后台可配置项 `max_floating_amount`，单位分，默认 1000。

---

### 数据流

```
┌─────────────────────────────────────────────────────────────┐
│                        下单流程                              │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  用户下单 → SqbPayHandle()                                  │
│    │                                                        │
│    ├── checkQuota()           ← 调用收钱吧额度接口          │
│    │   ├── 失败 → buildErrorHtml() → 返回错误页面          │
│    │   └── 通过 → 继续                                      │
│    │                                                        │
│    ├── getUniqueAmount()     ← 从 shd_sqb_pay_pending 查已有金额│
│    │   └── 整数分比较，冲突时 +1 分                           │
│    ├── addPending()          → 写入 shd_sqb_pay_pending      │
│    │   └── amount = round(元 × 100)                         │
│    └── buildPayHtml()        ← 从 shd_sqb_pay_pending 读过期时间│
│        └── 展示支付金额和二维码                               │
│                                                             │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                      轮询匹配流程                            │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  php think sqb_polling → execute()                          │
│    │                                                        │
│    ├── 1. clearExpiredPending()    清理过期记录              │
│    ├── 2. clearOlderThanPending()  清理一周前的记录          │
│    │                                                        │
│    ├── 3. 查询 invoices 表获取待支付订单                      │
│    │                                                        │
│    └── 4. matchPayments()                                  │
│        │                                                    │
│        ├── getEarliestCreateTime()  取最早记录时间           │
│        ├── queryTransactions()      调用收钱吧 API 查询交易  │
│        ├── 过滤 status == 2000（已完成）                    │
│        ├── 检查 accounts 表防重复匹配                       │
│        │                                                    │
│        └── 遍历交易：                                       │
│            ├── findInvoiceByAmount()  ← 从待支付表按金额查找 │
│            ├── fallback: 遍历 $pendingInvoices 按金额查找   │
│            ├── check_pay()           确认支付               │
│            └── removePending()       删除已匹配记录         │
│                                                             │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                       卸载流程                               │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  uninstall() → DROP TABLE shd_sqb_pay_pending               │
│                                                             │
│  ✅ 只删除自己的表，不触碰任何系统表                          │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 配置说明

| 配置项 | 说明 | 默认值 |
|--------|------|--------|
| `username` | 收钱吧商户平台登录账号（手机号） | - |
| `password` | 收钱吧商户平台登录密码 | - |
| `terminal_sn` | 终端号 | - |
| `qr_code_image` | 码牌静态二维码图片 URL | - |
| `polling_timeout` | 支付超时时间（秒），超过自动清理 | 300 |
| `max_floating_amount` | 生成唯一金额时允许的最大上浮金额（分） | 1000 |

---

## 轮询命令

```bash
php think sqb_polling
```

建议通过 cron 每 1-2 分钟执行一次：

```crontab
* * * * * cd /path/to/project && php think sqb_polling >> /dev/null 2>&1
```

---

## 关键类与方法

### SqbPayPlugin（插件入口）

| 方法 | 说明 |
|------|------|
| `SqbPayHandle($param)` | 支付处理主流程：额度校验 → 生成唯一金额 → 写入待支付 → 返回页面 |
| `checkQuota($config, $totalFee)` | 额度校验：调用 queryQuota 接口，返回错误信息或 null |
| `buildErrorHtml($config, $totalFee, $errorMsg)` | 构建错误提示页面 |
| `buildPayHtml($config, $amount, $outTradeNo, $pollingTimeout)` | 构建支付页面（含二维码） |
| `getUniqueAmount($baseAmount, $outTradeNo, $maxFloating)` | 生成唯一金额（分），冲突时递增 |
| `config()` | 读取插件配置 |

### SqbPayCore（核心逻辑）

| 方法 | 类型 | 说明 |
|------|------|------|
| `login()` | instance | 登录收钱吧 API，获取 Token（带缓存） |
| `queryQuota()` | instance | 查询收钱吧账户额度（totalQuota 单位：分） |
| `queryTransactions($params)` | instance | 查询交易列表 |
| `matchPayments($pendingInvoices)` | instance | 匹配支付并处理 |
| `addPending($invoiceId, $amountYuan, $ttl)` | static | 添加待支付记录 |
| `removePending($invoiceId)` | static | 移除单条记录 |
| `removeBatchPending($invoiceIds)` | static | 批量移除记录 |
| `findInvoiceByAmount($amountCents)` | static | 按金额（分）查找发票 |
| `clearExpiredPending()` | static | 清理过期记录 |
| `clearOlderThanPending($beforeTime)` | static | 清理指定时间之前的记录 |

### SqbPayPendingModel（数据模型）

| 方法 | 说明 |
|------|------|
| `addPending($invoiceId, $amountYuan, $ttl)` | 添加（金额转分，设置过期时间） |
| `findOrCreateByInvoiceId($invoiceId)` | 按 invoice_id 查找或新建 |
| `getExistingAmounts($excludeInvoiceId)` | 获取所有有效金额（排除指定发票） |
| `getEarliestCreateTime()` | 获取最早记录时间 |
| `clearExpired()` | 清理过期记录 |
| `clearOlderThan($beforeTime)` | 清理指定时间之前的记录 |