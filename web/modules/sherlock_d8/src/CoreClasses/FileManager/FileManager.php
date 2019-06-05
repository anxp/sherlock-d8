<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-02-20
 * Time: 08:08
 */

namespace Drupal\sherlock_d8\CoreClasses\FileManager;

use Drupal\sherlock_d8\CoreClasses\TextUtilities\TextUtilities;
use Drupal\Core\File\FileSystemInterface;

class FileManager {
  //Just the most popular user agent, it can be overriden by setUserAgent() method:
  protected $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.110 Safari/537.36';

  //This value will be set to CURLOPT_CONNECTTIMEOUT. Can be overriden by setConnectionTimeout() method:
  //TODO: Read about connection timeout in cURL, and set something more correct (2 sec?)
  protected $connectionTimeout = 10;

  protected $remoteFileUrl = null;
  protected $fileContent = null;
  protected $isFileLoaded = FALSE;
  protected $isFileSaved = FALSE;
  protected $fileObject = null;
  protected $hashAlgo = 'md4'; //We use this algorithm to hash downloaded files URL while generating their local names.
  protected $destUri = 'public://'; //A string containing the URI that the file should be copied to. This must be a stream wrapper URI.

  /**
   * FileManager constructor.
   * @param string $destUri A string containing the URI, where file will be stored. This must be a stream wrapper URI.
   */
  public function __construct($destUri = 'public://') {
    //TODO: Add check for URI syntax (need to be like public:// or public://something_else)
    $destination = $destUri;
    if (!TextUtilities::endsWith($destination, '/')) {$destination .= '/';}

    $this->destUri = $destination;
  }

  /**
   * @param string $userAgent
   */
  public function setUserAgent(string $userAgent): void {
    $this->userAgent = $userAgent;
  }

  /**
   * @param int $connectionTimeout
   */
  public function setConnectionTimeout(int $connectionTimeout): void {
    $this->connectionTimeout = $connectionTimeout;
  }

  /**
   * @return string
   */
  public function getUserAgent(): string {
    return $this->userAgent;
  }

  /**
   * @return int
   */
  public function getConnectionTimeout(): int {
    return $this->connectionTimeout;
  }

  public function loadRemoteFile($url, $useCache = TRUE): self {
    $this->fileObject = null; //Reset fileObject property if it has contain something from previous use.
    $this->fileContent = null; //Reset fileContent property if it has contain something from previous use.

    if (empty($url)) {
      //Empty URL catched.
      $this->fileContent = null;
      //Here can be debug message "Empty URL catched, so file will not be downloaded."
    } elseif ($useCache === TRUE && ($fileCachedContent = $this->checkCache($url)) !== FALSE) {
      //If flag useCache set to TRUE and cache is available -> load file from cache.
      $this->fileContent = $fileCachedContent;
      //Here can be debug message "Cache for requested file exists and will be used."
    } else {
      //If cache not available or we don't want to use it -> download file from remote server:
      $ch = curl_init();
      curl_setopt_array($ch, [
        CURLOPT_USERAGENT => $this->getUserAgent(),
        CURLOPT_CONNECTTIMEOUT => $this->getConnectionTimeout(),
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_HEADER => FALSE,
        CURLOPT_FOLLOWLOCATION => TRUE, //Follow redirects
        CURLOPT_URL => $url,
      ]);

      $this->fileContent = curl_exec($ch);
      curl_close($ch);
      //Here can be debug message "File downloaded from remote server."
    }

    if ($this->fileContent) {
      $this->isFileLoaded = TRUE;
      $this->remoteFileUrl = $url;
      return $this;

    } else {
      $this->isFileLoaded = FALSE;
      $this->remoteFileUrl = null;
      return $this;
    }
  }

  protected function checkCache($url) {
    $fileName = $this->constructFileName($url);
    if ($fileName === FALSE) {return FALSE;}

    $fileUri = $this->destUri.$fileName;

    return file_exists($fileUri) ? file_get_contents($fileUri) : FALSE;
  }

  protected function constructFileName(string $url): string {
    if (empty($url)) {return FALSE;}

    $ext = $this->extractExtensionFromURL($url);
    $urlHash = hash($this->hashAlgo, $url);

    return $ext ? ($urlHash.'.'.$ext) : $urlHash; //If no extension determined we just leave filename without extension.
  }

  /**
   * This method tries to extract extension by splitting URL into parts with parse_url() function, and extracting extension from 'path' part.
   * If extension was not found - FALSE returned.
   * @param string $url A URL by which file is accessible for download. Ideally URL is like http://example.com/files/images/foto1.jpg
   * @return bool|mixed
   */
  protected function extractExtensionFromURL($url) {
    // 1. URL must be not empty, by obvious reasons:
    if (empty($url)) {return FALSE;}

    // 2. If path not found -> return FALSE (see http://php.net/manual/ru/function.parse-url.php):
    $pathUrlPart = parse_url($url, PHP_URL_PATH);
		if (empty($pathUrlPart)) {return FALSE;}

    // 3. Path must contain at least one '.' if no -> return FALSE:
    if (strpos($pathUrlPart, '.') === FALSE) {return FALSE;}

    // 4. Extension candidate at least must exists, if no -> return FALSE:
    $pathExploded = explode('.', $pathUrlPart);
    $lastPathPart = array_pop($pathExploded);
    if (empty($lastPathPart)) {return FALSE;}

    // 5. And finally - return extension, if something like extension has been found:
		return $lastPathPart;
	}

	public function saveFileManaged(bool $markPermanent = FALSE, int $replace = FileSystemInterface::EXISTS_REPLACE): self {
    if ($this->isFileLoaded === FALSE) {
      $this->isFileSaved = FALSE;
      return $this;
    }

    $newFileName = $this->constructFileName($this->remoteFileUrl);

    file_prepare_directory($this->destUri, FileSystemInterface::CREATE_DIRECTORY);

    $newFileUri = $this->destUri.$newFileName;

    $fileObj = file_save_data($this->fileContent, $newFileUri, $replace);

    //If file WAS NOT successfully saved, set flag that indicates file was not saved and return from method:
    if (!$fileObj) {
      $this->isFileSaved = FALSE;
      return $this;
    }

    //Mark file as permanent or temporary. Temporary is default.
    if ($markPermanent) {
      $fileObj->setPermanent();
    } else {
      $fileObj->setTemporary();
    }

    try {
      $fileObj->save(); //This method returns SAVED_NEW or SAVED_UPDATED, depending on the operation performed. Or throws exception in case of failure.
      $this->fileObject = $fileObj;
      $this->isFileSaved = TRUE;
      return $this;

    } catch (\Drupal\Core\Entity\EntityStorageException $e) {
      //File was not saved
      $this->isFileSaved = FALSE;
      return $this;
    }
  }

  public function getLocalFileUrl(): string {
    if ($this->isFileSaved === FALSE) {return FALSE;}

    $uri = $this->fileObject->getFileUri();
    $url = file_create_url($uri);

    return $url;
  }

  public function getLocalFileUri(): string {
    if ($this->isFileSaved === FALSE) {return FALSE;}
    return $this->fileObject->getFileUri();
  }

	//saveFileUnmanagedD7() {}
	//saveFileNativePHP() {}
}
