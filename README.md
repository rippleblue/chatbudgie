# ChatBudgie WordPress 插件

在 WordPress 页面上显示聊天对话框，用户可以通过对话框与基于 RAG 的 Agent 对话，获得与网站相关的回答。

## 功能特性

- 📱 响应式聊天气泡，适配移动端和桌面端
- 🤖 支持多种聊天气泡图标（默认、机器人、客服、消息、自定义）
- 🔌 可配置的自定义 API 接口
- 🔒 支持 API 密钥认证
- 🎨 现代化的设计风格
- 💬 支持上下文连续对话

## 安装方法

1. 下载插件：`git clone https://github.com/rippleblue/chatbudgie.git`
2. 将 `chatbudgie` 文件夹复制到 WordPress 的 `wp-content/plugins/` 目录
3. 在 WordPress 后台 **插件** 页面启用 ChatBudgie
4. 进入 **设置 → ChatBudgie** 配置 API 地址和其他选项

## 配置说明

### API 设置
- **API 地址**：输入自定义 API 的完整地址（如 `https://your-api.com/chat`）
- **API 密钥**：可选，如果 API 需要认证，请输入 API 密钥

### 图标设置
- **默认图标**：聊天气泡 SVG 图标
- **机器人**：机器人头像 SVG 图标
- **客服**：耳机客服 SVG 图标
- **消息**：消息气泡 SVG 图标
- **自定义图标 URL**：输入自定义图片 URL（支持 SVG、PNG、JPG）

## API 接口规范

### 请求格式
```json
{
  "message": "用户消息内容",
  "conversation_history": [
    {"role": "user", "content": "历史消息1"},
    {"role": "assistant", "content": "历史回复1"}
  ]
}
```

### 响应格式
```json
{
  "success": true,
  "data": {
    "reply": "AI 回复内容"
  }
}
```

## 技术栈

- PHP 8.0+
- WordPress 6.0+
- jQuery
- 原生 JavaScript
- CSS3

## 开发说明

### 项目结构
```
chatbudgie/
├── chatbudgie.php          # 主插件文件
└── assets/
    ├── css/
    │   └── chatbudgie.css  # 样式文件
    └── js/
        └── chatbudgie.js   # 前端脚本
```

### 本地开发
1. 克隆仓库：`git clone https://github.com/yourusername/chatbudgie.git`
2. 进入项目目录：`cd chatbudgie`
3. 编辑代码
4. 上传到 WordPress 插件目录测试

## 贡献

欢迎提交 Issue 和 Pull Request！

## 许可证

GPL v2 或更高版本

## 联系方式

- 作者：Budgie Team
- 项目地址：https://github.com/yourusername/chatbudgie
