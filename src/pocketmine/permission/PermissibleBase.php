<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\permission;

use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginException;
use pocketmine\timings\Timings;

trait PermissibleBase{
	/** @var Permissible */
	private $this = null;

	/**
	 * @var PermissionAttachment[]
	 */
	private $attachments = [];

	/**
	 * @var PermissionAttachmentInfo[]
	 */
	private $permissions = [];

	public function initPermissible() : void{
		$this->this = $this;
		assert($this->this instanceof Permissible, "PermissibleBase can only be used by Permissibles");
	}

	/**
	 * @param Permission|string $name
	 *
	 * @return bool
	 */
	public function isPermissionSet($name) : bool{
		return isset($this->permissions[$name instanceof Permission ? $name->getName() : $name]);
	}

	/**
	 * @param Permission|string $name
	 *
	 * @return bool
	 */
	public function hasPermission($name) : bool{
		if($name instanceof Permission){
			$name = $name->getName();
		}

		if($this->isPermissionSet($name)){
			return $this->permissions[$name]->getValue();
		}

		if(($perm = PermissionManager::getInstance()->getPermission($name)) !== null){
			$perm = $perm->getDefault();

			return $perm === Permission::DEFAULT_TRUE or ($this->this->isOp() and $perm === Permission::DEFAULT_OP) or (!$this->this->isOp() and $perm === Permission::DEFAULT_NOT_OP);
		}else{
			return Permission::$DEFAULT_PERMISSION === Permission::DEFAULT_TRUE or ($this->this->isOp() and Permission::$DEFAULT_PERMISSION === Permission::DEFAULT_OP) or (!$this->this->isOp() and Permission::$DEFAULT_PERMISSION === Permission::DEFAULT_NOT_OP);
		}

	}

	/**
	 * //TODO: tick scheduled attachments
	 *
	 * @param Plugin $plugin
	 * @param string $name
	 * @param bool   $value
	 *
	 * @return PermissionAttachment
	 */
	public function addAttachment(Plugin $plugin, string $name = null, bool $value = null) : PermissionAttachment{
		if(!$plugin->isEnabled()){
			throw new PluginException("Plugin " . $plugin->getDescription()->getName() . " is disabled");
		}

		$result = new PermissionAttachment($plugin, $this->this);
		$this->attachments[spl_object_hash($result)] = $result;
		if($name !== null and $value !== null){
			$result->setPermission($name, $value);
		}

		$this->recalculatePermissions();

		return $result;
	}

	/**
	 * @param PermissionAttachment $attachment
	 */
	public function removeAttachment(PermissionAttachment $attachment){
		if(isset($this->attachments[spl_object_hash($attachment)])){
			unset($this->attachments[spl_object_hash($attachment)]);
			if(($ex = $attachment->getRemovalCallback()) !== null){
				$ex->attachmentRemoved($attachment);
			}

			$this->recalculatePermissions();

		}

	}

	public function recalculatePermissions(){
		Timings::$permissibleCalculationTimer->startTiming();

		$this->clearPermissions();
		$permManager = PermissionManager::getInstance();
		$defaults = $permManager->getDefaultPermissions($this->this->isOp());
		$permManager->subscribeToDefaultPerms($this->this->isOp(), $this->this);

		foreach($defaults as $perm){
			$name = $perm->getName();
			$this->permissions[$name] = new PermissionAttachmentInfo($this->this, $name, null, true);
			$permManager->subscribeToPermission($name, $this->this);
			$this->calculateChildPermissions($perm->getChildren(), false, null);
		}

		foreach($this->attachments as $attachment){
			$this->calculateChildPermissions($attachment->getPermissions(), false, $attachment);
		}

		Timings::$permissibleCalculationTimer->stopTiming();
	}

	public function clearPermissions(){
		$permManager = PermissionManager::getInstance();
		$permManager->unsubscribeFromAllPermissions($this->this);

		$permManager->unsubscribeFromDefaultPerms(false, $this->this);
		$permManager->unsubscribeFromDefaultPerms(true, $this->this);

		$this->permissions = [];

		$this->this = null;
	}

	/**
	 * @param bool[]                    $children
	 * @param bool                      $invert
	 * @param PermissionAttachment|null $attachment
	 */
	private function calculateChildPermissions(array $children, bool $invert, ?PermissionAttachment $attachment){
		$permManager = PermissionManager::getInstance();
		foreach($children as $name => $v){
			$perm = $permManager->getPermission($name);
			$value = ($v xor $invert);
			$this->permissions[$name] = new PermissionAttachmentInfo($this->this, $name, $attachment, $value);
			$permManager->subscribeToPermission($name, $this->this);

			if($perm instanceof Permission){
				$this->calculateChildPermissions($perm->getChildren(), !$value, $attachment);
			}
		}
	}

	/**
	 * @return PermissionAttachmentInfo[]
	 */
	public function getEffectivePermissions() : array{
		return $this->permissions;
	}
}
