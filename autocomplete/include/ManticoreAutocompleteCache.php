<?php

/**
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

class ManticoreAutocompleteCache {
	protected $temp_dir = '/tmp';
	const EXTENSION = '.dat';

	public function __construct() {

		$this->temp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manticore_cache';

		if ( ! file_exists( $this->temp_dir ) &&
		     ! mkdir( $concurrentDirectory = $this->temp_dir . DIRECTORY_SEPARATOR, 0777, true ) &&
		     ! is_dir( $concurrentDirectory ) ) {

			throw new RuntimeException( sprintf( 'Directory "%s" was not created', $concurrentDirectory ) );
		}
	}

	public function set_cache( $phrase, $cached_data ) {
		if ( ! empty( $cached_data ) && ! empty( trim( $phrase ) ) ) {

			$hash = md5( trim( $phrase ) );
			$path = $this->temp_dir . DIRECTORY_SEPARATOR .
			        $hash[0] . $hash[1] . DIRECTORY_SEPARATOR . $hash[2] . $hash[3];
			if ( ! file_exists( $path ) && ! mkdir( $path, 0777, true ) && ! is_dir( $path ) ) {
				throw new RuntimeException( sprintf( 'Directory "%s" was not created', $path ) );
			}

			$handle = fopen( $path . DIRECTORY_SEPARATOR . $hash . self::EXTENSION, "wb" );
			if ( flock( $handle, LOCK_EX | LOCK_NB ) ) {
				fwrite( $handle, json_encode( $cached_data, JSON_UNESCAPED_UNICODE ) );
				flock( $handle, LOCK_UN );
			}
			fclose( $handle );
		}
	}

	public function check_cache( $phrase ) {
		if ( ! empty( trim( $phrase ) ) ) {
			$hash = md5( trim( $phrase ) );

			$path = $this->temp_dir . DIRECTORY_SEPARATOR .
			        $hash[0] . $hash[1] . DIRECTORY_SEPARATOR . $hash[2] . $hash[3];

			$file = $path . DIRECTORY_SEPARATOR . $hash . self::EXTENSION;
			if ( file_exists( $file ) ) {
				return json_decode( file_get_contents( $file ) );
			}
		}

		return false;
	}

	public function clean_cache() {
		$this->clear_recursive( $this->temp_dir );
	}

	private function clear_recursive( $path ) {
		if ( file_exists( $path ) && is_dir( $path ) ) {
			$dirHandle = opendir( $path );
			while ( false !== ( $file = readdir( $dirHandle ) ) ) {
				if ( $file !== '.' && $file !== '..' ) {
					$tmpPath = $path . '/' . $file;
					if ( is_dir( $tmpPath ) ) {  // если папка
						$this->clear_recursive( $tmpPath );
					} elseif ( file_exists( $tmpPath ) ) {
						// удаляем файл
						unlink( $tmpPath );
					}
				}
			}
			closedir( $dirHandle );
			// удаляем текущую папку
			if ( file_exists( $path ) ) {
				rmdir( $path );
			}
		}
	}

	public function clear_obsolete_cache( $delay = 'day' ) {
		if ( $delay === 'day' ) {
			$delay = "1";
		} else {
			$delay = "7";
		}
		$command = 'find ' . $this->temp_dir . ' -type f -mtime +' . $delay . ' -name \'*' . self::EXTENSION . '\' -print';
		exec( $command, $output, $retval );
		if ( ! empty( $output ) ) {

			foreach ( $output as $file ) {
				if ( $file[0] === '/' ) {
					unlink( $file );
				}
			}
		}
	}


}
