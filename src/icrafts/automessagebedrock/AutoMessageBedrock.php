<?php

declare(strict_types=1);

namespace icrafts\automessagebedrock;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\utils\TextFormat;

final class AutoMessageBedrock extends PluginBase{
	/** @var array<string, array<string, mixed>> */
	private array $lists = [];

	/** @var array<string, TaskHandler> */
	private array $tasks = [];

	/** @var array<string, int> */
	private array $currentIndexes = [];

	public function onEnable() : void{
		$this->saveDefaultConfig();
		$this->loadListsFromConfig();
		$this->scheduleLists();
	}

	public function onDisable() : void{
		$this->unscheduleLists();
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if(strtolower($command->getName()) !== "automessage"){
			return false;
		}

		$sub = isset($args[0]) ? strtolower((string) $args[0]) : "help";
		switch($sub){
			case "reload":
				if(!$sender->hasPermission("automessage.commands.reload")){
					$sender->sendMessage(TextFormat::RED . "No permission.");
					return true;
				}
				$this->reloadConfig();
				$this->loadListsFromConfig();
				$this->scheduleLists();
				$sender->sendMessage(TextFormat::GREEN . "AutoMessage config reloaded.");
				return true;

			case "list":
				if(!$sender->hasPermission("automessage.commands.list")){
					$sender->sendMessage(TextFormat::RED . "No permission.");
					return true;
				}
				if(count($this->lists) === 0){
					$sender->sendMessage(TextFormat::YELLOW . "No message lists loaded.");
					return true;
				}
				$sender->sendMessage(TextFormat::AQUA . "Message lists:");
				foreach($this->lists as $name => $list){
					$sender->sendMessage(TextFormat::GRAY . "- " . TextFormat::WHITE . $name
						. TextFormat::GRAY . " (enabled=" . (($list["enabled"] ?? false) ? "true" : "false")
						. ", interval=" . (string) ($list["interval"] ?? 45)
						. ", random=" . (($list["random"] ?? false) ? "true" : "false")
						. ", messages=" . count(is_array($list["messages"] ?? null) ? $list["messages"] : []) . ")");
				}
				return true;

			case "send":
				if(!$sender->hasPermission("automessage.commands.send")){
					$sender->sendMessage(TextFormat::RED . "No permission.");
					return true;
				}
				if(!isset($args[1])){
					$sender->sendMessage(TextFormat::YELLOW . "Usage: /" . $label . " send <list> [index]");
					return true;
				}
				$listName = (string) $args[1];
				if(!isset($this->lists[$listName])){
					$sender->sendMessage(TextFormat::RED . "List not found: " . $listName);
					return true;
				}
				$index = isset($args[2]) ? max(0, (int) $args[2]) : null;
				$ok = $this->broadcastList($listName, true, $index);
				$sender->sendMessage($ok ? TextFormat::GREEN . "Message sent from list " . $listName : TextFormat::RED . "Could not send message.");
				return true;

			case "help":
			default:
				$sender->sendMessage(TextFormat::AQUA . "/am reload");
				$sender->sendMessage(TextFormat::AQUA . "/am list");
				$sender->sendMessage(TextFormat::AQUA . "/am send <list> [index]");
				return true;
		}
	}

	private function loadListsFromConfig() : void{
		$this->lists = [];
		$this->currentIndexes = [];

		$rawLists = $this->getConfig()->get("message-lists", []);
		if(!is_array($rawLists)){
			return;
		}

		foreach($rawLists as $name => $entry){
			if(!is_array($entry)){
				continue;
			}
			$messages = $entry["messages"] ?? [];
			if(!is_array($messages)){
				$messages = [];
			}
			$this->lists[(string) $name] = [
				"enabled" => (bool) ($entry["enabled"] ?? true),
				"random" => (bool) ($entry["random"] ?? false),
				"interval" => max(1, (int) ($entry["interval"] ?? 45)),
				"expiry" => (int) ($entry["expiry"] ?? -1),
				"messages" => array_values($messages),
			];
			$this->currentIndexes[(string) $name] = 0;
		}
	}

	private function scheduleLists() : void{
		$this->unscheduleLists();

		foreach($this->lists as $name => $entry){
			$interval = (int) ($entry["interval"] ?? 45);
			$this->tasks[$name] = $this->getScheduler()->scheduleRepeatingTask(
				new ClosureTask(function() use ($name) : void{
					$this->broadcastList($name, false, null);
				}),
				$interval * 20
			);
		}
	}

	private function unscheduleLists() : void{
		foreach($this->tasks as $handler){
			$handler->cancel();
		}
		$this->tasks = [];
	}

	private function broadcastList(string $name, bool $ignoreGlobalSettings, ?int $forcedIndex) : bool{
		if(!isset($this->lists[$name])){
			return false;
		}

		if(!$ignoreGlobalSettings && !$this->getConfig()->getNested("settings.enabled", true)){
			return false;
		}

		$list = $this->lists[$name];
		if(!(bool) ($list["enabled"] ?? false)){
			return false;
		}
		$expiry = (int) ($list["expiry"] ?? -1);
		if($expiry !== -1 && time() >= $expiry){
			return false;
		}

		$messages = $list["messages"] ?? [];
		if(!is_array($messages) || count($messages) === 0){
			return false;
		}

		$onlinePlayers = $this->getServer()->getOnlinePlayers();
		$minPlayers = (int) $this->getConfig()->getNested("settings.min-players", 0);
		if(!$ignoreGlobalSettings && count($onlinePlayers) < $minPlayers){
			return false;
		}

		if($forcedIndex !== null){
			$index = min(max(0, $forcedIndex), count($messages) - 1);
		}else{
			$random = (bool) ($list["random"] ?? false);
			$index = $random ? mt_rand(0, count($messages) - 1) : ($this->currentIndexes[$name] ?? 0);
			if($index >= count($messages) || $index < 0){
				$index = 0;
			}
		}

		$raw = (string) $messages[$index];
		$this->broadcastRawMessageToTargets($name, $raw, $onlinePlayers);

		if((bool) $this->getConfig()->getNested("settings.log-to-console", false)){
			$this->broadcastRawMessageToSender($raw, $this->getServer()->getConsoleSender(), null);
		}

		$this->currentIndexes[$name] = $index + 1;
		if($this->currentIndexes[$name] >= count($messages)){
			$this->currentIndexes[$name] = 0;
		}

		return true;
	}

	/**
	 * @param Player[] $players
	 */
	private function broadcastRawMessageToTargets(string $listName, string $raw, array $players) : void{
		foreach($players as $player){
			if(!$player->hasPermission("automessage.receive." . $listName)){
				continue;
			}
			$this->broadcastRawMessageToSender($raw, $player, $player);
		}
	}

	private function broadcastRawMessageToSender(string $raw, CommandSender $to, ?Player $playerContext) : void{
		$lines = preg_split('/(?<!\\\\)\\\\n/', $raw) ?: [];
		foreach($lines as $line){
			$line = str_replace("\\\\n", "\\n", (string) $line);
			$line = str_replace("%n", " ", $line);
			if($line === ""){
				continue;
			}
			if(str_starts_with($line, "/")){
				$this->getServer()->dispatchCommand($this->getServer()->getConsoleSender(), ltrim(substr($line, 1)));
				continue;
			}
			$to->sendMessage(TextFormat::colorize($this->replacePlaceholders($line, $to, $playerContext)));
		}
	}

	private function replacePlaceholders(string $line, CommandSender $to, ?Player $playerContext) : string{
		if($playerContext instanceof Player){
			$line = str_replace("{NAME}", $playerContext->getName(), $line);
			$line = str_replace("{DISPLAY_NAME}", $playerContext->getDisplayName(), $line);
			$line = str_replace("{WORLD}", $playerContext->getWorld()->getFolderName(), $line);
			$line = str_replace("{BIOME}", "UNKNOWN", $line);
		}else{
			$line = str_replace("{NAME}", $to->getName(), $line);
			$line = str_replace("{DISPLAY_NAME}", $to->getName(), $line);
			$line = str_replace("{WORLD}", "UNKNOWN", $line);
			$line = str_replace("{BIOME}", "UNKNOWN", $line);
		}

		$line = str_replace("{ONLINE}", (string) count($this->getServer()->getOnlinePlayers()), $line);
		$line = str_replace("{MAX_ONLINE}", (string) $this->getServer()->getMaxPlayers(), $line);
		$line = str_replace("{UNIQUE_PLAYERS}", "0", $line);

		$line = str_replace("{YEAR}", date("Y"), $line);
		$line = str_replace("{MONTH}", date("n"), $line);
		$line = str_replace("{WEEK_OF_MONTH}", (string) (int) ceil((int) date("j") / 7), $line);
		$line = str_replace("{WEEK_OF_YEAR}", date("W"), $line);
		$line = str_replace("{DAY_OF_WEEK}", date("N"), $line);
		$line = str_replace("{DAY_OF_MONTH}", date("j"), $line);
		$line = str_replace("{DAY_OF_YEAR}", date("z"), $line);
		$line = str_replace("{HOUR}", date("g"), $line);
		$line = str_replace("{HOUR_OF_DAY}", date("G"), $line);
		$line = str_replace("{MINUTE}", date("i"), $line);
		$line = str_replace("{SECOND}", date("s"), $line);

		return $line;
	}
}
