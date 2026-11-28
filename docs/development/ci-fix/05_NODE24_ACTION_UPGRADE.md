# 后续补齐：GitHub Actions 升级至 Node.js 24 运行时

## 1. 背景

Phase 4 最终报告 `04_CI_RELEASE_READINESS.md` 第 7 节留下唯一可在本地闭环的剩余项：CI 每个 job 都产生一条 `Node.js 20 is deprecated` 警告注解，涉及 `actions/checkout@v4`、`actions/setup-node@v4`、`gitleaks/gitleaks-action@v2`。GitHub 将 Node 20 action 强制在 Node 24 上运行；警告属非阻断项，但后续平台会移除兼容路径。

## 2. 修改内容

仅改 `.github/workflows/ci.yml`，共 4 行：

| 原值 | 新值 | 依据 |
|---|---|---|
| `actions/checkout@v4` | `actions/checkout@v6` | 官方 v6 的 `action.yml` 使用 `node24` |
| `actions/setup-node@v4` | `actions/setup-node@v6` | 官方 v6 的 `action.yml` 使用 `node24` |
| `node-version: '20'` | `node-version: '24'` | Node 20 已 EOL；JS 语法检查在 Node 24 实跑通过 |
| `gitleaks/gitleaks-action@v2` | `gitleaks/gitleaks-action@v3` | 官方 v3 迁移说明：仅运行时从 Node 20 升到 Node 24，输入/输出/行为不变 |

未改 matrix、未改任何质量门禁、未改测试或业务代码；未放宽 gitleaks allowlist。仓库属于个人账号，gitleaks v3 不需要 `GITLEAKS_LICENSE`（与 v2 现状一致）。

## 3. 验证证据

**本地**（修改提交前）：

- `python3` 解析 workflow YAML：PASS，4 个 matrix cell 保持不变；
- `find miniprogram -name '*.js' ... node --check`（本地 Node v24.15.0）：PASS；
- `bash bin/test.sh`：128 通过 / 0 失败；
- `git diff --check`：无空白错误。

**远程 CI**（提交 `dbefa1a0470dbfa08b99249021d18dfc2f91e5f4`）：

- Run：https://github.com/yaoyongli1974-dotcom/yunxutai/actions/runs/46108114407
- `status=completed`、`conclusion=success`；4/4 job 成功（PHP 8.4/8.5 × mysql:8.4/mariadb:11），每个 job 的全部 step 均 `success`，无跳过/失败/取消；
- `gh run watch --exit-status` 返回码 0；
- 通过 GitHub API 检查该 SHA 的 4 个 check run 注解数：全部为 `0`，Node.js 20 deprecated 警告已消失；
- Secret scan（gitleaks v3）、PHPStan、集成测试、migration 与 doctor 步骤在全部四组合继续通过。

## 4. 风险与回滚

- gitleaks v3 官方文档明确为纯运行时升级，行为不变；若未来迁移到组织账号，需按官方说明配置 `GITLEAKS_LICENSE`（与本升级无关）。
- checkout/setup-node 的主版本升级已由四矩阵实跑覆盖，未发现兼容问题。
- 回滚方式：将 workflow 中四处版本恢复为原值并推送；不需要回滚 test/基线或其他代码。

## 5. 结论

CI 注解警告已消除，四矩阵保持全绿。CI 红灯专项及其后续补齐项均已闭环；生产迁移、部署与完整恢复演练仍需用户单独授权，本轮未触碰生产。
