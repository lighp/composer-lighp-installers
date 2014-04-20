<?php
namespace lighp\Composer;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;

class Installer extends LibraryInstaller {
	/**
	* {@inheritDoc}
	*/
	protected function getInstallPath(PackageInterface $package) {
		return '.';
	}

	/**
	* {@inheritDoc}
	*/
	/*protected function installCode(PackageInterface $package) {
		$downloadPath = $this->getInstallPath($package);
		$this->downloadManager->download($package, $downloadPath);
	}*/

	/**
	* {@inheritDoc}
	*/
	public function supports($packageType) {
		return ('lighp-module' === $packageType);
	}
}