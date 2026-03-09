# AutoMessage

**AutoMessage** is a lightweight and highly customizable plugin for **PocketMine-MP** that allows server administrators to broadcast automated messages to players at specific intervals.

## Features

*   **Custom Intervals:** Set the delay between messages in seconds.
*   **Message Formatting:** Full support for Minecraft color codes (§).
*   **Prefix Support:** Add a custom prefix to all automated broadcasts.
*   **Randomized Mode:** Toggle between sequential or random message delivery.
*   **Multi-line Messages:** Send complex announcements using `\n` for new lines.
*   **Zero Lag:** Optimized to run in the background without affecting server performance.

## Commands


| Command | Description | Permission |
|---------|-------------|------------|
| `/am reload` | Reloads the plugin configuration | `automessage.admin` |
| `/am list` | Shows all configured messages | `automessage.admin` |
| `/am toggle` | Enables or disables the broadcaster | `automessage.admin` |

## Configuration

You can easily edit the messages and settings in the `config.yml` file:

```yaml
# AutoMessage Configuration
settings:
  interval: 60 # In seconds
  random: false
  prefix: "§l§8[§bINFO§8]§r "

messages:
  - "Welcome to our server! Type §e/help§f for commands."
  - "Don't forget to join our Discord: §adiscord.gg/yourlink"
  - "Support us by visiting our webstore!"
