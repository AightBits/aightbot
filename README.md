# AightBot Discord Moderator Note-Taking Bot

A secure, DM-only Discord bot designed for server moderators to privately create, view, and manage notes about server members. It operates entirely through direct messages to keep moderator actions discreet and uses role-based permissions for access control.

## Features

- **DM-Only Interface**: All commands are executed in private Direct Messages, keeping moderator notes and actions off public server channels.
- **Role-Based Security**: Only users with specific moderator roles can access the bot's functionality.
- **Full Note Management**: Create, list, delete, and clear notes for any server member.
- **Audit Logging**: All bot actions are logged with timestamps, user details, and outcomes for accountability.
- **Secure Configuration**: Sensitive tokens are managed via environment variables, not hardcoded.

## Project Structure

```
your-bot-project/
├── bot.py                    # Main bot application code
├── config.json               # Server and role configuration (CREATE THIS)
├── notes.json                # Data file for stored notes (AUTO-GENERATED)
├── bot_actions.log           # Audit log file (AUTO-GENERATED)
├── requirements.txt          # Python dependencies
├── ecosystem.config.js       # PM2 process manager config (for production)
└── logs/                     # PM2 application logs directory
    ├── err.log
    └── out.log
```

## Setup & Installation

### Local Development Setup

#### Prerequisites

- Python 3.8 or higher
- A Discord account and server where you have administrative permissions

#### Clone and Prepare

```bash
git clone <your-repository-url>
cd your-bot-project
```

#### Create a Virtual Environment (Recommended)

```bash
python -m venv venv
# On Windows: venv\Scripts\activate
# On macOS/Linux: source venv/bin/activate
```

#### Install Dependencies

```bash
pip install -r requirements.txt
```

#### Create Configuration File

Create a `config.json` file in the project root:

```json
{
  "guild_id": "YOUR_DISCORD_SERVER_ID_HERE",
  "mod_role_names": ["Moderator", "Admin", "Staff"]
}
```

- **guild_id**: Your Discord server's ID (Enable Developer Mode in Discord settings, then right-click your server and copy ID)
- **mod_role_names**: An array of role names that should have access to the bot

## Discord Developer Portal Setup

### Create a New Application

- Go to the Discord Developer Portal
- Click "New Application" and give it a name (for example, Moderator Bot)

### Create the Bot User

- Navigate to the Bot section in the left sidebar
- Click "Add Bot" and confirm
- Copy the bot token and store it securely
- Disable the Public Bot option if you want to restrict it to your server

### Configure Bot Permissions

- Navigate to OAuth2 > URL Generator
- Select `bot` and `applications.commands` scopes
- Select the following permissions:
  - View Channels
  - Read Messages
  - Send Messages
  - Read Message History
- Use the generated URL to invite the bot to your server

### Set Environment Variable

```bash
# Linux/macOS
export DISCORD_BOT_TOKEN='your_bot_token_here'

# Windows Command Prompt
set DISCORD_BOT_TOKEN=your_bot_token_here

# Windows PowerShell
$env:DISCORD_BOT_TOKEN='your_bot_token_here'
```

## Running the Bot

### Running Standalone (Development)

```bash
python bot.py
```

### Running with PM2 (Production)

#### Install PM2

```bash
npm install -g pm2
```

#### Configure ecosystem.config.js

```javascript
module.exports = {
  apps: [{
    name: 'discord-moderator-bot',
    script: 'bot.py',
    interpreter: 'python3',
    cwd: '/absolute/path/to/your/bot-project',
    env: {
      NODE_ENV: 'production'
    },
    log_date_format: 'YYYY-MM-DD HH:mm:ss',
    error_file: 'logs/err.log',
    out_file: 'logs/out.log',
    time: true
  }]
}
```

#### Set Token in PM2

```bash
pm2 set discord-moderator-bot:env.DISCORD_BOT_TOKEN "your_actual_token_here"
```

#### Start and Manage

```bash
pm2 start ecosystem.config.js
pm2 status
pm2 logs discord-moderator-bot
pm2 restart discord-moderator-bot
pm2 stop discord-moderator-bot
```

#### Start on Boot

```bash
pm2 startup
pm2 save
```

## Operation Guide

### Server Administrator Responsibilities

- Ensure `config.json` contains the correct guild ID and role names
- Verify moderators have the required roles
- Monitor `bot_actions.log` for audit trails
- Back up `notes.json` regularly
- Restart the bot after updates

### Moderator Usage

Moderators interact with the bot exclusively through Direct Messages.

#### Available Commands

| Command | Format | Description |
|------|------|------|
| !addnote | !addnote "Username" note text | Add a note |
| !listnotes | !listnotes "Username" | View notes |
| !deletenote | !deletenote "Username" 2 | Delete a note |
| !clearnotes | !clearnotes "Username" | Clear all notes |
| !help | !help | Display help |

#### Examples

```
!addnote "John Doe" Was helpful in the support channel today.
!listnotes "John Doe"
!deletenote "John Doe" 1
!clearnotes "John Doe"
```

## Security Notes

- Never commit sensitive files to version control
- Exclude config.json, notes.json, logs, and venv in .gitignore
- Review audit logs regularly
- Rotate bot tokens if compromised

## Troubleshooting

| Issue | Solution |
|------|--------|
| Bot does not respond | Verify moderator role |
| User not found | Use exact display name |
| Startup crash | Check environment variable |
| Permission errors | Verify bot permissions |
| Notes missing | Check file permissions |

## To-Do

- SQLite option
- Log rotation
- Mutli-server capability

## License

MIT License. See the LICENSE file for details.
