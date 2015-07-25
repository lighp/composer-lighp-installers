<?php
namespace lighp\Composer;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Json\JsonFile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class ModuleInstaller extends LibraryInstaller {
	/**
	* {@inheritDoc}
	*/
	public function getInstallPath(PackageInterface $package) {
		return '.';
	}

	protected function pkgFilesDb(PackageInterface $package) {
		$dbDir = 'var/lib/composer';
		
		return new JsonFile($dbDir.'/'.$package->getPrettyName().'.json');
	}

	protected function askDiscardChanges(array $modified, array $removed) {
		if (empty($modified) && empty($removed)) {
			return true;
		}

		$update = !empty($modified);

		//By default, keep changes and continue operation
		if (!$this->io->isInteractive()) {
			$discardChanges = $this->config->get('discard-changes');

			if (true === $discardChanges) {
				return true;
			} else {
				return false;
			}
		}

		$this->io->write('    <error>The package has modified files:</error>');

		$changes = array_map(function ($elem) {
			$flag = 'M';
			if (!file_exists($elem)) {
				$flag = 'A';
			}

			return '    '.$flag.' '.$elem;
		}, $modified);
		$changes += array_map(function ($elem) {
			return '    D '.$elem;
		}, $removed);
		$this->io->write(array_slice($changes, 0, 10));

		if (count($changes) > 10) {
			$this->io->write(' <info>'.count($changes) - 10 . ' more files modified, choose "v" to view the full list</info>');
		}

		while (true) {
			switch ($this->io->ask(' <info>Discard changes [y,n,a,v,?]?</info> ', '?')) {
				case 'y':
					return true;

				case 'n':
					return false;

				case 'a':
					throw new \RuntimeException('Update aborted');

				case 'v':
					$this->io->write($changes);
					break;

				case '?':
				default:
					help:
					$this->io->write(array(
						' y - discard changes and apply the update/uninstall',
						' n - keep changed files and apply the update/uninstall',
						' a - abort the update/uninstall and let you manually clean things up',
						' v - view modified files',
						' ? - print help'
					));
					break;
			}
		}
	}

	protected function downloadCode(PackageInterface $package, array $initialFiles = array()) {
		$installPath = $this->getInstallPath($package);

		// Download files
		$downloadPath = 'var/tmp/'.$package->getPrettyName();
		$this->downloadManager->download($package, $downloadPath);

		// Copy files
		$installPath = $this->getInstallPath($package);

		$sourcePath = $downloadPath . DIRECTORY_SEPARATOR . 'src';
		$copied = array();

		$it = new RecursiveDirectoryIterator($sourcePath, RecursiveDirectoryIterator::SKIP_DOTS);
		$ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST);

		foreach ($ri as $file) {
			$sourcePath = $file->getPathname();
			$sourceIndex = $ri->getSubPathName();
			$targetPath = $installPath . DIRECTORY_SEPARATOR . $ri->getSubPathName();

			if ($file->isDir()) {
				$this->filesystem->ensureDirectoryExists($targetPath);
			} else {
				$sourceSize = filesize($sourcePath);
				$sourceMd5sum = md5_file($sourcePath);

				$overwrite = true;

				// If the file already exists, do not overwrite it
				if (file_exists($targetPath)) {
					$initialFileData = array();
					if (isset($initialFiles[$sourceIndex])) {
						$initialFileData = $initialFiles[$sourceIndex];
					}

					$targetSize = filesize($targetPath);
					$targetMd5sum = null;

					// Check if the file has changed
					$changed = false;
					if ($targetSize !== $sourceSize) {
						$changed = true;
					} else {
						$targetMd5sum = md5_file($targetPath);

						if ($targetMd5sum !== $sourceMd5sum) {
							$changed = true;
						}
					}

					if ($changed && !empty($initialFileData)) {
						if (empty($targetMd5sum)) {
							$targetMd5sum = md5_file($targetPath);
						}

						// File not changed since last update
						if ($targetMd5sum === $initialFileData['md5sum']) {
							$changed = false;
						}
					}

					if ($changed) {
						$overwrite = false;
					}
				}
				
				if (!$overwrite) {
					$targetPath .= '.new';
					$this->io->write('<warning>'.$sourceIndex.' locally modified, new version installed as '.$targetPath.'</warning>');
				}

				$result = copy($sourcePath, $targetPath);
				if ($result === false) {
					throw new RuntimeException('cannot copy "'.$targetPath.'"');
				}

				$copied[$sourceIndex] = array(
					//'path' => $ri->getSubPathName(),
					'size' => $sourceSize,
					'md5sum' => $sourceMd5sum
				);
			}
		}

		// Remove downloaded files
		try {
			$this->filesystem->removeDirectory($downloadPath);	
		} catch (\Exception $e) {
			$this->io->write('<warning>Could not delete temporary files for '.$package->getPrettyName().' (in '.$downloadPath.').</warning>');
		}

		return $copied;
	}

	protected function deleteCode(PackageInterface $package, array $files, array $newFiles = array()) {
		//Remove deleted files
		foreach ($files as $filepath => $fileData) {
			$deleteFile = true;
			$changed = false;

			if (!file_exists($filepath)) {
				continue;
			}

			if (isset($newFiles[$filepath])) { //File not deleted
				$deleteFile = false;
			} elseif (isset($files[$filepath])) {
				$fileData = $files[$filepath];
				$oldMd5sum = md5_file($filepath);

				//File changed, preserve changes
				if ($fileData['md5sum'] !== $oldMd5sum) {
					$changed = true;
				}
			}

			if ($changed) {
				$this->io->write('<warning>'.$filepath.' has been changed, skipped (not removed).</warning>');
				$deleteFile = false;
			}

			if ($deleteFile) {
				$this->removeWithEmptyParents($filepath);
			}
		}
	}

	protected function removeWithEmptyParents($filepath) {
		$this->filesystem->remove($filepath);

		//Remove parent directories while empty
		while($this->filesystem->isDirEmpty(dirname($filepath))) {
			$this->filesystem->removeDirectory(dirname($filepath));

			$filepath = dirname($filepath);
		}
	}

	/**
	* {@inheritDoc}
	*/
	protected function installCode(PackageInterface $package) {
		//Download and install
		$copied = $this->downloadCode($package);

		//Save copied files
		$pkgFilesDb = $this->pkgFilesDb($package);
		$pkgFilesDb->write($copied, 0);
	}

	/**
	* {@inheritDoc}
	*/
	protected function updateCode(PackageInterface $initial, PackageInterface $target) {
		$pkgFilesDb = $this->pkgFilesDb($initial);

		$initialFiles = array();
		if (!$pkgFilesDb->exists()) {
			$this->io->write('<warning>cannot find package files DB "'.$pkgFilesDb->getPath().'". Ignoring currently installed files, there might be residual files from current installation.</warning>');
		} else {
			$initialFiles = $pkgFilesDb->read();
		}

		//Download and install
		$copied = $this->downloadCode($target, $initialFiles);

		//Remove newly deleted files
		$this->deleteCode($initial, $initialFiles, $copied);

		//Save copied files
		$pkgFilesDb->write($copied, 0);
	}

	/**
	* {@inheritDoc}
	*/
	protected function removeCode(PackageInterface $package) {
		$pkgFilesDb = $this->pkgFilesDb($package);

		if (!$pkgFilesDb->exists()) {
			throw new RuntimeException('cannot find package files DB "'.$pkgFilesDb->getPath().'"');
		}

		//Remove files
		$pkgFiles = $pkgFilesDb->read();
		$this->deleteCode($package, $pkgFiles);

		//Remove package DB
		$this->removeWithEmptyParents($pkgFilesDb->getPath());
	}

	/**
	* {@inheritDoc}
	*/
	public function supports($packageType) {
		return ('lighp-module' === $packageType);
	}
}
