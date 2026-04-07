# ChatBudgie WordPress Plugin

Display a chat dialog on WordPress pages, allowing users to converse with a RAG-based Agent to get website-related answers.

## Features

- 📱 Responsive chat bubble, optimized for mobile and desktop
- 🤖 Multiple chat bubble icons (Default, Robot, Customer Service, Message, Custom)
- 🔌 Configurable custom API endpoints
- 🔒 API key authentication support
- 🎨 Modern design style
- 💬 Continuous contextual conversations

## Installation

1. Download the plugin: `git clone https://github.com/rippleblue/chatbudgie.git`
2. Copy the `chatbudgie` folder to WordPress `wp-content/plugins/` directory
3. Activate ChatBudgie from the **Plugins** page in WordPress admin
4. Navigate to **Settings → ChatBudgie** to configure API URL and other options

## Configuration

### API Settings
- **API URL**: Enter the full API URL (e.g., `https://your-api.com/chat`)
- **API Key**: Optional, enter your API key if authentication is required

### Icon Settings
- **Default Icon**: Chat bubble SVG icon
- **Robot**: Robot avatar SVG icon
- **Customer Service**: Headset customer service SVG icon
- **Message**: Message bubble SVG icon
- **Custom Icon URL**: Enter custom image URL (supports SVG, PNG, JPG)

## API Specifications

### Request Format
```json
{
  "message": "User message content",
  "conversation_history": [
    {"role": "user", "content": "Historical message 1"},
    {"role": "assistant", "content": "Historical reply 1"}
  ]
}
```

### Response Format
```json
{
  "success": true,
  "data": {
    "reply": "AI reply content"
  }
}
```

## Tech Stack

- PHP 8.0+
- WordPress 6.0+
- jQuery
- Vanilla JavaScript
- CSS3

## Development

### Project Structure
```
chatbudgie/
├── chatbudgie.php          # Main plugin file
└── assets/
    ├── css/
    │   └── chatbudgie.css  # Stylesheet
    └── js/
        └── chatbudgie.js   # Frontend script
```

### Local Development
1. Clone the repository: `git clone https://github.com/yourusername/chatbudgie.git`
2. Navigate to the project directory: `cd chatbudgie`
3. Edit the code
4. Upload to WordPress plugin directory for testing

## Contributing

Issues and Pull Requests are welcome!

## License

GPL v2 or later

## Contact

- Author: Budgie Team
- Project URL: https://github.com/yourusername/chatbudgie
