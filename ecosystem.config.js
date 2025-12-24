module.exports = {
  apps: [{
    name: 'your-bot-name-here',       // Generic placeholder for the PM2 app name
    script: 'bot.py',                 // Generic filename; change to your main script
    interpreter: 'python3',           // Standard interpreter command
    cwd: '/absolute/path/to/your/bot', // PLACEHOLDER: Must be replaced with the actual absolute path
    env: {
      NODE_ENV: 'production',
      // SECURITY: The DISCORD_BOT_TOKEN must be set externally, e.g., via 'pm2 env' or server env vars.
      // Example setup: pm2 set app-name:env.DISCORD_BOT_TOKEN "your_token_here"
      PYTHONPATH: '' // Optional: Set only if you have custom library paths. Can often be left empty.
    },
    log_date_format: 'YYYY-MM-DD HH:mm:ss',
    error_file: 'logs/err.log',      // Relative to 'cwd'; ensures logs are inside project
    out_file: 'logs/out.log',        // Relative to 'cwd'
    time: true                       // Prefixes logs with timestamps
  }]
}
