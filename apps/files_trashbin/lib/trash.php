<?php
/**
 * ownCloud - trash bin
 *
 * @author Bjoern Schiessle
 * @copyright 2013 Bjoern Schiessle schiessle@owncloud.com
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Files_Trashbin;

class Trashbin {
	
	const DEFAULT_RETENTION_OBLIGATION=180; // how long do we keep files in the trash bin if no other value is defined in the config file (unit: days)
	const DEFAULTMAXSIZE=50; // unit: percentage; 50% of available disk space/quota
	
	/**
	 * move file to the trash bin
	 * 
	 * @param $file_path path to the deleted file/directory relative to the files root directory
	 */
	public static function move2trash($file_path) {
		$user = \OCP\User::getUser();
		$view = new \OC_FilesystemView('/'. $user);
		if (!$view->is_dir('files_trashbin')) {
			$view->mkdir('files_trashbin');
			$view->mkdir("versions_trashbin");
		}

		$path_parts = pathinfo($file_path);

		$deleted = $path_parts['basename'];
		$location = $path_parts['dirname'];
		$timestamp = time();
		$mime = $view->getMimeType('files'.$file_path);

		if ( $view->is_dir('files'.$file_path) ) {
			$type = 'dir';
		} else {
			$type = 'file';
		}
		
		if (  ($trashbinSize = \OCP\Config::getAppValue('files_trashbin', 'size')) === null ) {
			$trashbinSize = self::calculateSize(new \OC_FilesystemView('/'. $user.'/files_trashbin'));
			$trashbinSize += self::calculateSize(new \OC_FilesystemView('/'. $user.'/versions_trashbin'));
		}
		$trashbinSize += self::copy_recursive($file_path, 'files_trashbin/'.$deleted.'.d'.$timestamp, $view);

		if ( $view->file_exists('files_trashbin/'.$deleted.'.d'.$timestamp) ) {
			$query = \OC_DB::prepare("INSERT INTO *PREFIX*files_trash (id,timestamp,location,type,mime,user) VALUES (?,?,?,?,?,?)");
			$result = $query->execute(array($deleted, $timestamp, $location, $type, $mime, $user));
			if ( !$result ) { // if file couldn't be added to the database than also don't store it in the trash bin.
				$view->deleteAll('files_trashbin/'.$deleted.'.d'.$timestamp);
				\OC_Log::write('files_trashbin', 'trash bin database couldn\'t be updated', \OC_log::ERROR);
				return;
			}
	
			if ( \OCP\App::isEnabled('files_versions') ) {
				if ( $view->is_dir('files_versions'.$file_path) ) {
					$trashbinSize += self::calculateSize(new \OC_FilesystemView('/'. $user.'/files_versions/'.$file_path));
					$view->rename('files_versions'.$file_path, 'versions_trashbin/'. $deleted.'.d'.$timestamp);
				} else if ( $versions = \OCA\Files_Versions\Storage::getVersions($file_path) ) {
					foreach ($versions as $v) {
						$trashbinSize += $view->filesize('files_versions'.$v['path'].'.v'.$v['version']);
						$view->rename('files_versions'.$v['path'].'.v'.$v['version'], 'versions_trashbin/'. $deleted.'.v'.$v['version'].'.d'.$timestamp);
					}
				}
			}
		} else {
			\OC_Log::write('files_trashbin', 'Couldn\'t move '.$file_path.' to the trash bin', \OC_log::ERROR);
		}
		
		// get available disk space for user
		$quota = \OCP\Util::computerFileSize(\OC_Preferences::getValue($user, 'files', 'quota'));
		if ( $quota == null ) {
			$quota = \OCP\Util::computerFileSize(\OC_Appconfig::getValue('files', 'default_quota'));
		}
		if ( $quota == null ) {
			$quota = \OC\Files\Filesystem::free_space('/');
		}
		
		// calculate available space for trash bin
		$rootInfo = $view->getFileInfo('/files');
		$free = $quota-$rootInfo['size']; // remaining free space for user
		if ( $free > 0 ) {
			$availableSpace = ($free * self::DEFAULTMAXSIZE / 100) - $trashbinSize; // how much space can be used for versions
		} else {
			$availableSpace = $free-$trashbinSize;
		}
		
		$trashbinSize -= self::expire($availableSpace);
		\OCP\Config::setAppValue('files_trashbin', 'size', $trashbinSize);
	}
	
	
	/**
	 * restore files from trash bin
	 * @param $file path to the deleted file
	 * @param $filename name of the file
	 * @param $timestamp time when the file was deleted
	 */
	public static function restore($file, $filename, $timestamp) {
		$user = \OCP\User::getUser();
		$view = new \OC_FilesystemView('/'.$user);
		
		if (  ($trashbinSize = \OCP\Config::getAppValue('files_trashbin', 'size')) === null ) {
			$trashbinSize = self::calculateSize(new \OC_FilesystemView('/'. $user.'/files_trashbin'));
			$trashbinSize += self::calculateSize(new \OC_FilesystemView('/'. $user.'/versions_trashbin'));
		}
		if ( $timestamp ) {
			$query = \OC_DB::prepare('SELECT location,type FROM *PREFIX*files_trash WHERE user=? AND id=? AND timestamp=?');
			$result = $query->execute(array($user,$filename,$timestamp))->fetchAll();
			if ( count($result) != 1 ) {
				\OC_Log::write('files_trashbin', 'trash bin database inconsistent!', \OC_Log::ERROR);
				return false;
			}
			
			// if location no longer exists, restore file in the root directory
			$location = $result[0]['location'];
			if ( $result[0]['location'] != '/' && 
				 (!$view->is_dir('files'.$result[0]['location']) ||
				 !$view->isUpdatable('files'.$result[0]['location'])) ) {
				$location = '';
			}
		} else {
			$path_parts = pathinfo($filename);
			$result[] = array(
					'location' => $path_parts['dirname'],
					'type' => $view->is_dir('/files_trashbin/'.$file) ? 'dir' : 'files',
					);
			$location = '';
		}
		
		$source = \OC_Filesystem::normalizePath('files_trashbin/'.$file);
		$target = \OC_Filesystem::normalizePath('files/'.$location.'/'.$filename);
		
		// we need a  extension in case a file/dir with the same name already exists
		$ext = self::getUniqueExtension($location, $filename, $view);
		$mtime = $view->filemtime($source);
		if( $view->rename($source, $target.$ext) ) {
			$view->touch($target.$ext, $mtime);
			if ($view->is_dir($target.$ext)) {
				$trashbinSize -= self::calculateSize(new \OC_FilesystemView('/'.$user.'/'.$target.$ext));
			} else {
				$trashbinSize -= $view->filesize($target.$ext);
			}
			// if versioning app is enabled, copy versions from the trash bin back to the original location
			if ( \OCP\App::isEnabled('files_versions') ) {
				if ($timestamp ) {
					$versionedFile = $filename;
				} else {
					$versionedFile = $file;
				}
				if ( $result[0]['type'] == 'dir' ) {
					$trashbinSize -= self::calculateSize(new \OC_FilesystemView('/'.$user.'/'.'versions_trashbin/'. $file));
					$view->rename(\OC_Filesystem::normalizePath('versions_trashbin/'. $file), \OC_Filesystem::normalizePath('files_versions/'.$location.'/'.$filename.$ext));
				} else if ( $versions = self::getVersionsFromTrash($versionedFile, $timestamp) ) {
					foreach ($versions as $v) {
						if ($timestamp ) {
							$trashbinSize -= $view->filesize('versions_trashbin/'.$versionedFile.'.v'.$v.'.d'.$timestamp);
							$view->rename('versions_trashbin/'.$versionedFile.'.v'.$v.'.d'.$timestamp, 'files_versions/'.$location.'/'.$filename.$ext.'.v'.$v);
						} else {
							$trashbinSize -= $view->filesize('versions_trashbin/'.$versionedFile.'.v'.$v);
							$view->rename('versions_trashbin/'.$versionedFile.'.v'.$v, 'files_versions/'.$location.'/'.$filename.$ext.'.v'.$v);
						}
					}
				}
			}

			if ( $timestamp ) {
				$query = \OC_DB::prepare('DELETE FROM *PREFIX*files_trash WHERE user=? AND id=? AND timestamp=?');
				$query->execute(array($user,$filename,$timestamp));
			}

			\OCP\Config::setAppValue('files_trashbin', 'size', $trashbinSize);
			return true;
		} else {
			\OC_Log::write('files_trashbin', 'Couldn\'t restore file from trash bin, '.$filename, \OC_log::ERROR);
		}

		return false;
	}
	
	/**
	 * delete file from trash bin permanently
	 * @param $filename path to the file
	 * @param $timestamp of deletion time
	 * @return size of deleted files
	 */
	public static function delete($filename, $timestamp=null) {
		$user = \OCP\User::getUser();
		$view = new \OC_FilesystemView('/'.$user);
		$size = 0;
	
		if (  ($trashbinSize = \OCP\Config::getAppValue('files_trashbin', 'size')) === null ) {
			$trashbinSize = self::calculateSize(new \OC_FilesystemView('/'. $user.'/files_trashbin'));
			$trashbinSize += self::calculateSize(new \OC_FilesystemView('/'. $user.'/versions_trashbin'));
		}

		if ( $timestamp ) {
			$query = \OC_DB::prepare('DELETE FROM *PREFIX*files_trash WHERE user=? AND id=? AND timestamp=?');
			$query->execute(array($user,$filename,$timestamp));
			$file = $filename.'.d'.$timestamp;
		} else {
			$file = $filename;
		}
		
		if ( \OCP\App::isEnabled('files_versions') ) {
			if ($view->is_dir('versions_trashbin/'.$file)) {
				$size += self::calculateSize(new \OC_Filesystemview('/'.$user.'/versions_trashbin/'.$file));
				$view->unlink('versions_trashbin/'.$file);
			} else if ( $versions = self::getVersionsFromTrash($filename, $timestamp) ) {
				foreach ($versions as $v) {
					if ($timestamp ) {
						$size += $view->filesize('/versions_trashbin/'.$filename.'.v'.$v.'.d'.$timestamp);
						$view->unlink('/versions_trashbin/'.$filename.'.v'.$v.'.d'.$timestamp);
					} else {
						$size += $view->filesize('/versions_trashbin/'.$filename.'.v'.$v);
						$view->unlink('/versions_trashbin/'.$filename.'.v'.$v);
					}
				}
			}
		}
	
		if ($view->is_dir('/files_trashbin/'.$file)) {
			$size += self::calculateSize(new \OC_Filesystemview('/'.$user.'/files_trashbin/'.$file));
		} else {
			$size += $view->filesize('/files_trashbin/'.$file);
		}
		$view->unlink('/files_trashbin/'.$file);
		$trashbinSize -= $size;
		\OCP\Config::setAppValue('files_trashbin', 'size', $trashbinSize);
		
		return $size;
	}

	/**
	 * check to see whether a file exists in trashbin
	 * @param $filename path to the file
	 * @param $timestamp of deletion time
	 * @return true if file exists, otherwise false
	 */
	public static function file_exists($filename, $timestamp=null) {
		$user = \OCP\User::getUser();
		$view = new \OC_FilesystemView('/'.$user);

		if ($timestamp) {
			$filename = $filename.'.d'.$timestamp;
		} else {
			$filename = $filename;
		}

		$target = \OC_Filesystem::normalizePath('files_trashbin/'.$filename);
		return $view->file_exists($target);
	}

	/**
	 * clean up the trash bin
	 * @param max. available disk space for trashbin
	 */
	private static function expire($availableSpace) {
		
		$user = \OCP\User::getUser();
		$view = new \OC_FilesystemView('/'.$user);
		$size = 0;
		
		$query = \OC_DB::prepare('SELECT location,type,id,timestamp FROM *PREFIX*files_trash WHERE user=?');
		$result = $query->execute(array($user))->fetchAll();
		
		$retention_obligation = \OC_Config::getValue('trashbin_retention_obligation', self::DEFAULT_RETENTION_OBLIGATION);
		
		$limit = time() - ($retention_obligation * 86400);

		foreach ( $result as $r ) {
			$timestamp = $r['timestamp'];
			$filename = $r['id'];
			if ( $r['timestamp'] < $limit ) {
				if ($view->is_dir('files_trashbin/'.$filename.'.d'.$timestamp)) {
					$size += self::calculateSize(new \OC_FilesystemView('/'.$user.'/files_trashbin/'.$filename.'.d'.$timestamp));
				} else {
					$size += $view->filesize('files_trashbin/'.$filename.'.d'.$timestamp);
				}
				$view->unlink('files_trashbin/'.$filename.'.d'.$timestamp);
				if ($r['type'] == 'dir') {
					$size += self::calculateSize(new \OC_FilesystemView('/'.$user.'/versions_trashbin/'.$filename.'.d'.$timestamp));
					$view->unlink('versions_trashbin/'.$filename.'.d'.$timestamp);
				} else if ( $versions = self::getVersionsFromTrash($filename, $timestamp) ) {
					foreach ($versions as $v) {
						$size += $view->filesize('versions_trashbin/'.$filename.'.v'.$v.'.d'.$timestamp);
						$view->unlink('versions_trashbin/'.$filename.'.v'.$v.'.d'.$timestamp);
					}			
				}
			}
		}
		
		$query = \OC_DB::prepare('DELETE FROM *PREFIX*files_trash WHERE user=? AND timestamp<?');
		$query->execute(array($user,$limit));
		
		$availableSpace = $availableSpace + $size;
		// if size limit for trash bin reached, delete oldest files in trash bin
		if ($availableSpace < 0) {
			$query = \OC_DB::prepare('SELECT location,type,id,timestamp FROM *PREFIX*files_trash WHERE user=? ORDER BY timestamp ASC');
			$result = $query->execute(array($user))->fetchAll();
			$length = count($result);
			$i = 0;
			while ( $i < $length &&   $availableSpace < 0 ) {
				$tmp = self::delete($result[$i]['id'], $result[$i]['timestamp']);
				$availableSpace += $tmp;
				$size += $tmp;
				$i++;
			}
			
		}
		
		return $size;
	}
	
	/**
	 * recursive copy to copy a whole directory
	 * 
	 * @param $source source path, relative to the users files directory
	 * @param $destination destination path relative to the users root directoy
	 * @param $view file view for the users root directory
	 */
	private static function copy_recursive( $source, $destination, $view ) {
		$size = 0;
		if ( $view->is_dir( 'files'.$source ) ) {
			$view->mkdir( $destination );
			$view->touch($destination,  $view->filemtime('files'.$source));
			foreach ( \OC_Files::getDirectoryContent($source) as $i ) {
				$pathDir = $source.'/'.$i['name'];
				if ( $view->is_dir('files'.$pathDir) ) {
					$size += self::copy_recursive($pathDir, $destination.'/'.$i['name'], $view);
				} else {
					$size += $view->filesize('files'.$pathDir);
					$view->copy( 'files'.$pathDir, $destination . '/' . $i['name'] );
					$view->touch($destination . '/' . $i['name'], $view->filemtime('files'.$pathDir));
				}
			}
		} else {
			$size += $view->filesize('files'.$source);
			$view->copy( 'files'.$source, $destination );
			$view->touch($destination, $view->filemtime('files'.$source));
		}
		return $size;
	}
	
	/**
	 * find all versions which belong to the file we want to restore
	 * @param $filename name of the file which should be restored
	 * @param $timestamp timestamp when the file was deleted
	 */
	private static function getVersionsFromTrash($filename, $timestamp) {
		$view = new \OC_FilesystemView('/'.\OCP\User::getUser().'/versions_trashbin');
		$versionsName = \OCP\Config::getSystemValue('datadirectory').$view->getAbsolutePath($filename);
		$versions = array();
		if ($timestamp ) {
			// fetch for old versions
			$matches = glob( $versionsName.'.v*.d'.$timestamp );
			$offset = -strlen($timestamp)-2;
		} else {
			$matches = glob( $versionsName.'.v*' );
		}
		
		foreach( $matches as $ma ) {
			if ( $timestamp ) {
				$parts = explode( '.v', substr($ma, 0, $offset) );
				$versions[] = ( end( $parts ) );
			} else {
				$parts = explode( '.v', $ma );
				$versions[] = ( end( $parts ) );
			}
		}
		return $versions;
	}
	
	/**
	 * find unique extension for restored file if a file with the same name already exists
	 * @param $location where the file should be restored
	 * @param $filename name of the file
	 * @param $view filesystem view relative to users root directory
	 * @return string with unique extension
	 */
	private static function getUniqueExtension($location, $filename, $view) {
		$ext = '';
		if ( $view->file_exists('files'.$location.'/'.$filename) ) {
			$tmpext = '.restored';
			$ext = $tmpext;
			$i = 1;
			while ( $view->file_exists('files'.$location.'/'.$filename.$ext) ) {
				$ext = $tmpext.$i;
				$i++;
			}
		}
		return $ext;
	}

	/**
	 * @brief get the size from a given root folder
	 * @param $view file view on the root folder
	 * @return size of the folder
	 */
	private static function calculateSize($view) {
		$root = \OCP\Config::getSystemValue('datadirectory').$view->getAbsolutePath('');
		if (!file_exists($root)) {
			return 0;
		}
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root), \RecursiveIteratorIterator::CHILD_FIRST);
		$size = 0;
		
		foreach ($iterator as $path) {
			$relpath = substr($path, strlen($root)-1);
			if ( !$view->is_dir($relpath) ) {
				$size += $view->filesize($relpath);
			}
		}
		return $size;
	}
	
}
