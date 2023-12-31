<?php

namespace aParticles;

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\PluginTask;
use pocketmine\utils\Config;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;

use pocketmine\math\Vector3;
use pocketmine\level\particle\FlameParticle;
use pocketmine\level\particle\LavaParticle;
use pocketmine\level\particle\HeartParticle;
use pocketmine\level\particle\WaterParticle;
use pocketmine\level\particle\HappyVillagerParticle;
use pocketmine\level\particle\AngryVillagerParticle;
use pocketmine\level\particle\BubbleParticle;
use pocketmine\level\particle\PortalParticle;
use pocketmine\level\particle\EnchantParticle;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;

use pocketmine\Player;

class aParticles extends PluginBase implements Listener {
	public $config, $users;

	public function onEnable() {
		$folder = $this->getDataFolder();
		if (!is_dir($folder))
			@mkdir($folder);
		$this->saveResource('config.yml');
		$this->config = (new Config($folder . 'config.yml', Config::YAML))->getAll();
		$this->users = (new Config($folder . 'users.yml', Config::YAML, []))->getAll();
		unset($folder);
		$this->eco = new aParticlesEconomyManager($this);
		$pManager = $this->getServer()->getPluginManager();
		$pManager->registerEvents($this, $this);
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new aParticlesGeneration($this), $this->config['period'] * 20);
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new aParticlesAutosaver($this), 6000);
		$this->getLogger()->info('§aHiệu Ứng Đã Được Kích Hoạt!');
		$this->getLogger()->info('§aTuwj Động Lưu Lại Sau 5 Phút...');
	}

	public function onPlayerJoin(PlayerJoinEvent $event) {
		$player = $event->getPlayer();
		$name = strtolower($player->getName());
		if (!isset($this->users[$name])) {
			$particles = [];
			foreach ($this->config['particles'] as $particle => $cost)
				if ($cost == 'free' || $cost == 0)
					$particles[] = $particle;
			$this->users[$name] = [
				'particles' => $particles,
				'enabled' => false
			];
		}
	}

	public function onCommand(CommandSender $sender, Command $command, $label, array $args) {
		if ($sender instanceof Player) {
			if (strtolower($command->getName()) == 'hieuung') {
				$name = strtolower($sender->getName());
				$config = $this->config;

				switch (count($args)) {

					case 0:
						$sender->sendMessage($this->help($sender));
						break;

					case 1:
						$particle = strtolower($args[0]);
						if ($particle == 'save') {
							if ($sender->isOp() || $sender->hasPermission('aprt.save')) {
								$this->save();
								$sender->sendMessage($config['save']);
								return true;
							}
						}
						if ($particle == 'help') {
							$sender->sendMessage($this->help($sender));
							return true;
						}
						if ($particle == 'off') {
							$this->users[$name]['enabled'] = false;
							$sender->sendMessage($config['particleOff']);
							return true;
						}
						if (!isset($config['particles'][$particle])) {
							$sender->sendMessage($config['particleNotExist']);
							return true;
						}
						if (!$sender->hasPermission("aprt.$particle")) {
							$sender->sendMessage(str_replace('{particle}', $particle, $config['permNotExist']));
							return true;
						}
						if (array_search($particle, $this->users[$name]['particles']) === false) {
							$sender->sendMessage(str_replace('{particle}', $particle, $config['necesita comprar']));
							return true;
						}
						$this->users[$name]['enabled'] = $particle;
						$sender->sendMessage(str_replace('{particle}', $particle, $config['enabled']));
						break;

					case 2:
						$buy = strtolower($args[0]);
						$particle = strtolower($args[1]);
						if ($buy == 'buy' || $buy == 'add') {
							if (!isset($config['particles'][$particle])) {
								$sender->sendMessage($config['particleNotExist']);
								return true;
							}
							$money = $this->eco->getMoney($name);
							if ($this->eco !== null) {
								if ($money < $config['particles'][$particle]) {
									$sender->sendMessage($config['notEnoughMoney']);
									return true;
								}
							} else $this->getLogger()->warning('Instala economy');
							$this->eco->buyParticle($sender->getName(), $config['particles'][$particle]);
							$this->users[$name]['particles'][] = $particle;
							$sender->sendMessage(str_replace('{particle}', $particle, $config['buy']));
							return true;
						}
						$sender->sendMessage($this->help($sender));
						break;

					default:
						$sender->sendMessage($this->help($sender));
						break;
				}
			}
		} else $sender->sendMessage('§cChỉ dành cho người chơi!');
	}

	public function save() {
		$cfg = new Config($this->getDataFolder() . 'users.yml');
		$cfg->setAll($this->users);
		$cfg->save();
		unset($cfg);
	}

	/**
	 * @param Player $player
	 * @return string
	 */
	private function help($player) {
		$list = "";
		foreach ($this->config['particles'] as $particle => $price) {
			$price = $price == 'free' || $price == 0 ? $this->config['free'] : str_replace('{price}', $price, $this->config['price']);
			if (!$player->hasPermission("aprt.$particle"))
				$price .= $this->config['dontHavePermissions'];
			if (array_search($particle, $this->users[strtolower($player->getName())]['particles']) !== false)
				$price .= str_replace('{particle}', $particle, $this->config['bought']);
			else
				$price .= str_replace('{particle}', $particle, $this->config['notBought']);
			$list .= str_replace(['{particle}', '{price}'], [$particle, $price . "\n"], $this->config['helpList']);
		}
		return $this->config['help'] . "\n" . $list;
	}
}

class aParticlesGeneration extends PluginTask {

	public function __construct(aParticles $plugin) {
		parent::__construct($plugin);
		$this->p = $plugin;
	}

	public function onRun($tick) {
		foreach ($this->p->getServer()->getOnlinePlayers() as $player) {
			if (isset($this->p->users[strtolower($player->getName())])) {
				$particle = $this->p->users[strtolower($player->getName())];
				if ($particle['enabled'] !== false) {
					$particle = $this->p->users[strtolower($player->getName())];
					$particle = $this->getParticle($particle['enabled'], $player);
					$player->getLevel()->addParticle($particle);
				}
			}
		}
	}

	/**
	 * @param string $particle
	 * @param Player $player
	 * @return bool / Particle $particle
	 */
	private function getParticle($particle, $player) {
		$vector3 = new Vector3($player->getX() + $this->randomFloat(), $player->getY() + $this->randomFloat(0.25, 1.5), $player->getZ() + $this->randomFloat());
		switch ($particle) {
			case 'flame':
				$particle = new FlameParticle($vector3);
				break;
			case 'lava':
				$particle = new LavaParticle($vector3);
				break;
			case 'heart':
				$particle = new HeartParticle($vector3, $this->randomFloat(1, 3));
				break;
			case 'water':
				$particle = new WaterParticle($vector3);
				break;
			case 'happy':
				$particle = new HappyVillagerParticle($vector3);
				break;
			case 'angry':
				$particle = new AngryVillagerParticle($vector3);
				break;
			case 'bubble':
				$particle = new BubbleParticle($vector3);
				break;
			case 'portal':
				$particle = new PortalParticle($vector3);
				break;
			case 'enchant':
				$particle = new EnchantParticle($vector3);
				break;
			default:
				$particle = false;
				break;
		}
		print_r($particle);
		return $particle;
	}

	private function randomFloat($min = -1.2, $max = 1.2) {
		return $min + mt_rand() / mt_getrandmax() * ($max - $min);
	}
}

class aParticlesEconomyManager extends aParticles {

	public function __construct(aParticles $plugin) {
		$this->p = $plugin;
		$pManager = $plugin->getServer()->getPluginManager();
		$this->eco = $pManager->getPlugin("EconomyAPI") ?? $pManager->getPlugin("PocketMoney") ?? $pManager->getPlugin("MassiveEconomy") ?? null;
		unset($pManager);
		if ($this->eco === null)
			$plugin->getLogger()->warning('Không có Plugin kinh tế nào được tìm thấy.');
		else
			$plugin->getLogger()->info('§aĐã tìm thấy Plugin kinh tế : §d' . $this->eco->getName());
	}

	/**
	 * @param string $player
	 * @param integer $amount
	 */
	public function buyParticle($player, $amount) {
		if ($this->eco === null)
			return "§ceconomy incorrecto!";
		$this->eco->setMoney($player, $this->getMoney($player) - $amount);
	}

	/**
	 * @param  string $player
	 * @return integer $balance
	 */
	public function getMoney($player) {
		switch ($this->eco->getName()) {

			case 'EconomyAPI':
				$balance = $this->eco->myMoney($player);
				break;

			case 'PocketMoney':
				$balance = $this->eco->getMoney($player);
				break;

			case 'MassiveEconomy':
				$balance = $this->eco->getMoney($player);
				break;

			default:
				$balance = 0;
		}
		return $balance;
	}

	/**
	 * @var string $name
	 * @return mixed
	 */
	public function getEconomyPlugin($name = false) {
		if ($name)
			return $this->eco->getName();
		return $this->eco;
	}
}

class aParticlesAutosaver extends PluginTask {

	public function __construct(aParticles $plugin) {
		parent::__construct($plugin);
		$this->p = $plugin;
	}

	public function onRun($tick) {
		$this->p->save();
	}
}
