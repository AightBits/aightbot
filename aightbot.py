# External library imports for Discord functionality and utilities
import discord
from discord.ext import commands
import json
import os
import sys
import asyncio
import re
from datetime import datetime
from typing import Dict, List, Any, Optional

# Retrieve server and role settings from external configuration file
with open('config.json', 'r') as f:
    config = json.load(f)

# Target Discord server identifier
GUILD_ID = config["guild_id"]
# Collection of role titles authorized to use bot commands
MOD_ROLE_NAMES = set(config["mod_role_names"])
# Storage location for user annotation records
NOTES_FILE = 'notes.json'

# Coordination mechanism preventing simultaneous file access conflicts
notes_lock = asyncio.Lock()

# Representation for automated system-originated activities
class SystemUser:
    name: str = "SYSTEM"
    id: str = "SYSTEM"

# Singleton instance for system-initiated logging entries
SYSTEM_USER = SystemUser()

# Define which Discord events and data the application can access
intents = discord.Intents.default()
intents.message_content = True
intents.members = True

# Primary bot instance configured with exclamation command trigger
bot = commands.Bot(command_prefix="!", intents=intents, help_command=None)

# Security measure stripping potentially harmful characters from text input
def sanitize_input(text: str, max_length: int = 1000) -> str:
    """Filters control characters and enforces maximum text boundaries"""
    if not text:
        return text
    # Eliminate non-printable characters while preserving formatting whitespace
    text = re.sub(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]', '', text)
    # Apply character ceiling to prevent resource exhaustion
    return text.strip()[:max_length]

# Activity recording system for operational transparency and debugging
def log_action(user: discord.User, action: str, data: str, result: str):
    """Documents all bot interactions with contextual metadata"""
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    entry = f"[{timestamp}] {user.name} ({user.id}) | Action: {action} | Data: {data} | Result: {result}\n"

    try:
        # Persistent storage of activity records
        with open('bot_actions.log', 'a', encoding='utf-8') as f:
            f.write(entry)
    except Exception as e:
        # Fallback notification when file operations fail
        print(f"Failed to write log: {e}")

    # Immediate console output for real-time monitoring
    print(entry.strip())

# Verification that user possesses required authorization credentials
def has_permission(member: Optional[discord.Member]) -> bool:
    """Assesses role-based access privileges"""
    if not member:
        return False
    # Compare user's assigned roles against approved moderator list
    return any(role.name in MOD_ROLE_NAMES for role in member.roles)

# Locates server participant using provided identifier string
async def find_member_by_name(user_name: str) -> Optional[discord.Member]:
    """Searches server membership using flexible name matching"""
    guild = bot.get_guild(GUILD_ID)
    if not guild:
        return None

    original_name = user_name.strip()
    if not original_name:
        return None

    # Normalize for case-insensitive comparison
    search_name = original_name.lower()

    # Priority search for exact username or nickname matches
    for member in guild.members:
        if (member.name.lower() == search_name or
            member.display_name.lower() == search_name):
            return member

    # Secondary search for partial name containment
    for member in guild.members:
        if (search_name in member.name.lower() or
            search_name in member.display_name.lower()):
            return member

    return None

# Thread-safe data retrieval from persistent storage
async def load_notes() -> Dict[int, List[Dict[str, Any]]]:
    """Safely reads annotation data from disk with concurrency protection"""
    async with notes_lock:
        if not os.path.exists(NOTES_FILE):
            return {}

        try:
            with open(NOTES_FILE, 'r', encoding='utf-8') as f:
                data = json.load(f)
                # Convert JSON string keys back to integer identifiers
                return {int(k): v for k, v in data.items()}
        except:
            return {}

# Thread-safe data persistence to disk storage
async def save_notes(notes: Dict[int, List[Dict[str, Any]]]):
    """Securely writes annotation collections to file"""
    async with notes_lock:
        with open(NOTES_FILE, 'w', encoding='utf-8') as f:
            json.dump(notes, f, indent=2, default=str)

# Core functionality: Creates new annotation for specified user
async def add_note(ctx, user_name: str, *, note: str):
    """Appends observational record to user profile"""
    target_member = await find_member_by_name(user_name)

    if not target_member:
        await ctx.send(f"User '{user_name}' not found")
        log_action(ctx.author, "add_note", f"user_not_found: {user_name}", "Failed")
        return

    # Apply security cleansing to submitted content
    note = sanitize_input(note.strip())
    
    if not note:
        await ctx.send("Note cannot be empty")
        log_action(ctx.author, "add_note", "empty note", "Failed")
        return

    if len(note) > 1000:
        await ctx.send("Note too long (max 1000 characters)")
        log_action(ctx.author, "add_note", f"note_length: {len(note)}", "Failed")
        return

    notes = await load_notes()

    if target_member.id not in notes:
        notes[target_member.id] = []

    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    notes[target_member.id].append({
        'timestamp': timestamp,
        'note': note,
        'added_by': ctx.author.id
    })

    await save_notes(notes)

    await ctx.send(f"Note added for {target_member.display_name}")
    log_action(ctx.author, "add_note", f"target: {target_member.display_name} | note: {note}", "Success")

# Core functionality: Displays all annotations for specified user
async def list_notes(ctx, user_name: str):
    """Retrieves and formats chronological annotation history"""
    target_member = await find_member_by_name(user_name)

    if not target_member:
        await ctx.send(f"User '{user_name}' not found")
        log_action(ctx.author, "list_notes", f"user_not_found: {user_name}", "Failed")
        return

    notes = await load_notes()
    user_notes = notes.get(target_member.id, [])

    if not user_notes:
        await ctx.send(f"No notes for {target_member.display_name}")
        log_action(ctx.author, "list_notes", f"no_notes: {target_member.display_name}", "Info")
        return

    guild = bot.get_guild(GUILD_ID)
    response = f"Notes for {target_member.display_name}:\n\n"

    for i, note in enumerate(user_notes, 1):
        mod_name = "Unknown"
        if guild:
            mod_member = guild.get_member(note['added_by'])
            if mod_member:
                mod_name = mod_member.display_name

        response += f"{i}. [{note['timestamp']}] by {mod_name}\n"
        response += f"   {note['note']}\n\n"

    response += f"Total: {len(user_notes)}"

    # Handle Discord's message length constraints with segmentation
    if len(response) > 2000:
        for chunk in [response[i:i+1990] for i in range(0, len(response), 1990)]:
            await ctx.send(chunk)
    else:
        await ctx.send(response)

    log_action(ctx.author, "list_notes", f"listed: {target_member.display_name}, count: {len(user_notes)}", "Success")

# Core functionality: Removes specific annotation by positional index
async def delete_note(ctx, user_name: str, note_index: int):
    """Deletes single annotation entry using one-based numbering"""
    target_member = await find_member_by_name(user_name)

    if not target_member:
        await ctx.send(f"User '{user_name}' not found")
        log_action(ctx.author, "delete_note", f"user_not_found: {user_name}", "Failed")
        return

    notes = await load_notes()
    user_notes = notes.get(target_member.id, [])

    if not user_notes:
        await ctx.send(f"No notes for {target_member.display_name}")
        log_action(ctx.author, "delete_note", f"no_notes: {target_member.display_name}", "Info")
        return

    if note_index < 1 or note_index > len(user_notes):
        await ctx.send(f"Invalid note index (1-{len(user_notes)})")
        log_action(ctx.author, "delete_note", f"invalid_index: {note_index}", "Failed")
        return

    user_notes.pop(note_index - 1)

    if not user_notes:
        del notes[target_member.id]

    await save_notes(notes)

    await ctx.send(f"Note deleted for {target_member.display_name}")
    log_action(ctx.author, "delete_note", f"deleted: {target_member.display_name}", "Success")

# Core functionality: Erases all annotations for specified user
async def clear_notes(ctx, user_name: str):
    """Removes entire annotation history for targeted individual"""
    target_member = await find_member_by_name(user_name)

    if not target_member:
        await ctx.send(f"User '{user_name}' not found")
        log_action(ctx.author, "clear_notes", f"user_not_found: {user_name}", "Failed")
        return

    notes = await load_notes()

    if target_member.id in notes:
        note_count = len(notes[target_member.id])
        del notes[target_member.id]
        await save_notes(notes)
        await ctx.send(f"Cleared {note_count} notes for {target_member.display_name}")
        log_action(ctx.author, "clear_notes", f"cleared: {target_member.display_name}, count: {note_count}", "Success")
    else:
        await ctx.send(f"No notes for {target_member.display_name}")
        log_action(ctx.author, "clear_notes", f"no_notes: {target_member.display_name}", "Info")

# Core functionality: Provides command syntax guidance
async def show_help(ctx):
    """Delivers instructional information about available commands"""
    help_text = (
        "Commands (DM Only):\n"
        "!addnote \"user name\" note\n"
        "!listnotes \"user name\"\n"
        "!deletenote \"user name\" 1\n"
        "!clearnotes \"user name\"\n"
        "!help\n"
        "Use quotes for names with spaces."
    )
    await ctx.send(help_text)
    log_action(ctx.author, "help", "help requested", "Success")

# Event triggered upon successful bot initialization
@bot.event
async def on_ready():
    """Post-connection setup and status announcement"""
    print(f"Bot started as {bot.user}")
    log_action(SYSTEM_USER, "bot_startup", f"Logged in as {bot.user}", "Success")

    # Ensure log file existence before first write operation
    if not os.path.exists('bot_actions.log'):
        with open('bot_actions.log', 'w', encoding='utf-8') as f:
            f.write("=== Bot Log ===\n")

# Primary message processing gateway
@bot.event
async def on_message(message):
    """Filters and routes incoming messages to appropriate handlers"""
    if message.author == bot.user:
        return

    # Exclusive processing of direct message channels
    if isinstance(message.channel, discord.DMChannel):
        guild = bot.get_guild(GUILD_ID)
        if not guild:
            # Configuration failure - server reference unavailable
            log_action(SYSTEM_USER, "error", f"Guild {GUILD_ID} not found", "Error")
            return

        # Identity verification through server membership check
        try:
            member = await guild.fetch_member(message.author.id)
        except discord.NotFound:
            # Sender not present in configured server
            log_action(message.author, "auth_check", "User not in guild", "Rejected")
            return
        except discord.HTTPException as e:
            # Network or API failure during verification
            log_action(message.author, "auth_check", f"API Error: {e.status}", "Rejected")
            return

        # Authorization assessment against role requirements
        if not has_permission(member):
            # Detailed logging of permission denial for security review
            log_action(message.author, "auth_check",
                      f"Missing required role. User roles: {[r.name for r in member.roles]}",
                      "Rejected")
            return

        # Successful authentication record
        log_action(message.author, "auth_check", "Has required role", "Granted")

        # Command processing for messages beginning with trigger symbol
        if message.content.startswith('!'):
            await bot.process_commands(message)

    # Messages from server channels receive no processing or logging

# Command interface: Handles note creation with flexible argument parsing
@bot.command(name='addnote')
async def add_note_cmd(ctx, *, args: str):
    """Parses combined arguments into username and content components"""
    args = args.strip()
    if args.startswith('"'):
        end_quote = args.find('"', 1)
        if end_quote == -1:
            await ctx.send('Missing closing quote')
            return
        user_name = args[1:end_quote]
        note = args[end_quote + 1:].strip()
    else:
        parts = args.split(' ', 1)
        if len(parts) < 2:
            await ctx.send('Usage: !addnote "user name" note')
            return
        user_name, note = parts[0], parts[1]

    if not note:
        await ctx.send('Note cannot be empty')
        return

    await add_note(ctx, user_name, note=note)

# Command interface: Retrieves annotation listings
@bot.command(name='listnotes')
async def list_notes_cmd(ctx, *, user_name: str):
    """Processes potentially quoted username for lookup"""
    if user_name.startswith('"') and user_name.endswith('"'):
        user_name = user_name[1:-1]
    await list_notes(ctx, user_name)

# Command interface: Handles single annotation removal
@bot.command(name='deletenote')
async def delete_note_cmd(ctx, *, args: str):
    """Extracts username and numerical index from command arguments"""
    args = args.strip()
    if args.startswith('"'):
        end_quote = args.find('"', 1)
        if end_quote == -1:
            await ctx.send('Missing closing quote')
            return
        user_name = args[1:end_quote]
        rest = args[end_quote + 1:].strip()
    else:
        parts = args.split(' ', 1)
        if len(parts) < 2:
            await ctx.send('Usage: !deletenote "user name" 1')
            return
        user_name, rest = parts[0], parts[1]

    try:
        note_index = int(rest.split()[0])
    except ValueError:
        await ctx.send('Note index must be a number')
        return

    await delete_note(ctx, user_name, note_index)

# Command interface: Handles complete annotation clearance
@bot.command(name='clearnotes')
async def clear_notes_cmd(ctx, *, user_name: str):
    """Processes username input for bulk removal operation"""
    if user_name.startswith('"') and user_name.endswith('"'):
        user_name = user_name[1:-1]
    await clear_notes(ctx, user_name)

# Command interface: Displays usage instructions
@bot.command(name='help')
async def help_cmd(ctx):
    """Provides user guidance for available bot functionality"""
    await show_help(ctx)

# Global error interception for command execution failures
@bot.event
async def on_command_error(ctx, error):
    """Centralized error handling for command processing issues"""
    log_action(ctx.author, "command_error", str(error), "Failed")

    # User-friendly responses for common error conditions
    if isinstance(error, commands.MissingRequiredArgument):
        await ctx.send('Missing argument. Use !help.')
    elif isinstance(error, commands.CommandNotFound):
        await ctx.send('Command not found. Use !help.')

# Application entry point and startup sequence
if __name__ == "__main__":
    # Retrieve authentication token from environment variables
    token = os.getenv('DISCORD_BOT_TOKEN')
    
    # Validate token presence before initialization attempts
    if not token:
        print("Error: DISCORD_BOT_TOKEN not set")
        sys.exit(1)
    
    if not token.strip():
        print("Error: DISCORD_BOT_TOKEN is empty")
        sys.exit(1)
    
    try:
        # Launch bot with provided authentication credentials
        bot.run(token)
    except discord.LoginFailure:
        # Handle authentication rejection by Discord API
        print("Error: Invalid DISCORD_BOT_TOKEN")
        sys.exit(1)
