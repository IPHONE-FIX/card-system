<?php
 namespace Jacobcyl\AliOSS; use Dingo\Api\Contract\Transformer\Adapter; use League\Flysystem\Adapter\AbstractAdapter; use League\Flysystem\AdapterInterface; use League\Flysystem\Config; use League\Flysystem\Util; use OSS\Core\OssException; use OSS\OssClient; use Log; use Symfony\Component\Filesystem\Exception\FileNotFoundException; class AliOssAdapter extends AbstractAdapter { protected $debug; protected static $resultMap = [ 'Body' => 'raw_contents', 'Content-Length' => 'size', 'ContentType' => 'mimetype', 'Size' => 'size', 'StorageClass' => 'storage_class', ]; protected static $metaOptions = [ 'CacheControl', 'Expires', 'ServerSideEncryption', 'Metadata', 'ACL', 'ContentType', 'ContentDisposition', 'ContentLanguage', 'ContentEncoding', ]; protected static $metaMap = [ 'CacheControl' => 'Cache-Control', 'Expires' => 'Expires', 'ServerSideEncryption' => 'x-oss-server-side-encryption', 'Metadata' => 'x-oss-metadata-directive', 'ACL' => 'x-oss-object-acl', 'ContentType' => 'Content-Type', 'ContentDisposition' => 'Content-Disposition', 'ContentLanguage' => 'response-content-language', 'ContentEncoding' => 'Content-Encoding', ]; protected $client; protected $bucket; protected $endPoint; protected $cdnDomain; protected $ssl; protected $isCname; protected $options = [ 'Multipart' => 128 ]; public function __construct( OssClient $client, $bucket, $endPoint, $ssl, $isCname = false, $debug = false, $cdnDomain, $prefix = null, array $options = [] ) { $this->debug = $debug; $this->client = $client; $this->bucket = $bucket; $this->setPathPrefix($prefix); $this->endPoint = $endPoint; $this->ssl = $ssl; $this->isCname = $isCname; $this->cdnDomain = $cdnDomain; $this->options = array_merge($this->options, $options); } public function getBucket() { return $this->bucket; } public function getClient() { return $this->client; } public function write($path, $contents, Config $config) { $object = $this->applyPathPrefix($path); $options = $this->getOptions($this->options, $config); if (! isset($options[OssClient::OSS_LENGTH])) { $options[OssClient::OSS_LENGTH] = Util::contentSize($contents); } if (! isset($options[OssClient::OSS_CONTENT_TYPE])) { $options[OssClient::OSS_CONTENT_TYPE] = Util::guessMimeType($path, $contents); } try { $this->client->putObject($this->bucket, $object, $contents, $options); } catch (OssException $e) { $this->logErr(__FUNCTION__, $e); return false; } return $this->normalizeResponse($options, $path); } public function writeStream($path, $resource, Config $config) { $options = $this->getOptions($this->options, $config); $contents = stream_get_contents($resource); return $this->write($path, $contents, $config); } public function writeFile($path, $filePath, Config $config){ $object = $this->applyPathPrefix($path); $options = $this->getOptions($this->options, $config); $options[OssClient::OSS_CHECK_MD5] = true; if (! isset($options[OssClient::OSS_CONTENT_TYPE])) { $options[OssClient::OSS_CONTENT_TYPE] = Util::guessMimeType($path, ''); } try { $this->client->uploadFile($this->bucket, $object, $filePath, $options); } catch (OssException $e) { $this->logErr(__FUNCTION__, $e); return false; } return $this->normalizeResponse($options, $path); } public function update($path, $contents, Config $config) { if (! $config->has('visibility') && ! $config->has('ACL')) { $config->set(static::$metaMap['ACL'], $this->getObjectACL($path)); } return $this->write($path, $contents, $config); } public function updateStream($path, $resource, Config $config) { $contents = stream_get_contents($resource); return $this->update($path, $contents, $config); } public function rename($path, $newpath) { if (! $this->copy($path, $newpath)){ return false; } return $this->delete($path); } public function copy($path, $newpath) { $object = $this->applyPathPrefix($path); $newObject = $this->applyPathPrefix($newpath); try{ $this->client->copyObject($this->bucket, $object, $this->bucket, $newObject); } catch (OssException $e) { $this->logErr(__FUNCTION__, $e); return false; } return true; } public function delete($path) { $bucket = $this->bucket; $object = $this->applyPathPrefix($path); try{ $this->client->deleteObject($bucket, $object); }catch (OssException $e) { $this->logErr(__FUNCTION__, $e); return false; } return ! $this->has($path); } public function deleteDir($dirname) { $dirname = rtrim($this->applyPathPrefix($dirname), '/').'/'; $dirObjects = $this->listDirObjects($dirname, true); if(count($dirObjects['objects']) > 0 ){ foreach($dirObjects['objects'] as $object) { $objects[] = $object['Key']; } try { $this->client->deleteObjects($this->bucket, $objects); } catch (OssException $e) { $this->logErr(__FUNCTION__, $e); return false; } } try { $this->client->deleteObject($this->bucket, $dirname); } catch (OssException $e) { $this->logErr(__FUNCTION__, $e); return false; } return true; } public function listDirObjects($dirname = '', $recursive = false) { $delimiter = '/'; $nextMarker = ''; $maxkeys = 1000; $result = []; while(true){ $options = [ 'delimiter' => $delimiter, 'prefix' => $dirname, 'max-keys' => $maxkeys, 'marker' => $nextMarker, ]; try { $listObjectInfo = $this->client->listObjects($this->bucket, $options); } catch (OssException $e) { $this->logErr(__FUNCTION__, $e); throw $e; } $nextMarker = $listObjectInfo->getNextMarker(); $objectList = $listObjectInfo->getObjectList(); $prefixList = $listObjectInfo->getPrefixList(); if (!empty($objectList)) { foreach ($objectList as $objectInfo) { $object['Prefix'] = $dirname; $object['Key'] = $objectInfo->getKey(); $object['LastModified'] = $objectInfo->getLastModified(); $object['eTag'] = $objectInfo->getETag(); $object['Type'] = $objectInfo->getType(); $object['Size'] = $objectInfo->getSize(); $object['StorageClass'] = $objectInfo->getStorageClass(); $result['objects'][] = $object; } }else{ $result["objects"] = []; } if (!empty($prefixList)) { foreach ($prefixList as $prefixInfo) { $result['prefix'][] = $prefixInfo->getPrefix(); } }else{ $result['prefix'] = []; } if($recursive){ foreach( $result['prefix'] as $pfix){ $next = $this->listDirObjects($pfix , $recursive); $result["objects"] = array_merge($result['objects'], $next["objects"]); } } if ($nextMarker === '') { break; } } return $result; } public function createDir($dirname, Config $config) { $object = $this->applyPathPrefix($dirname); $options = $this->getOptionsFromConfig($config); try { $this->client->createObjectDir($this->bucket, $object, $options); } catch (OssException $e) { $this->logErr(__FUNCTION__, $e); return false; } return ['path' => $dirname, 'type' => 'dir']; } public function setVisibility($path, $visibility) { $object = $this->applyPathPrefix($path); $acl = ( $visibility === AdapterInterface::VISIBILITY_PUBLIC ) ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE; $this->client->putObjectAcl($this->bucket, $object, $acl); return compact('visibility'); } public function has($path) { $object = $this->applyPathPrefix($path); return $this->client->doesObjectExist($this->bucket, $object); } public function read($path) { $result = $this->readObject($path); $result['contents'] = (string) $result['raw_contents']; unset($result['raw_contents']); return $result; } public function readStream($path) { $result = $this->readObject($path); $result['stream'] = $result['raw_contents']; rewind($result['stream']); $result['raw_contents']->detachStream(); unset($result['raw_contents']); return $result; } protected function readObject($path) { $object = $this->applyPathPrefix($path); $result['Body'] = $this->client->getObject($this->bucket, $object); $result = array_merge($result, ['type' => 'file']); return $this->normalizeResponse($result, $path); } public function listContents($directory = '', $recursive = false) { $dirObjects = $this->listDirObjects($directory, true); $contents = $dirObjects["objects"]; $result = array_map([$this, 'normalizeResponse'], $contents); $result = array_filter($result, function ($value) { return $value['path'] !== false; }); return Util::emulateDirectories($result); } public function getMetadata($path) { $object = $this->applyPathPrefix($path); try { $objectMeta = $this->client->getObjectMeta($this->bucket, $object); } catch (OssException $e) { $this->logErr(__FUNCTION__, $e); return false; } return $objectMeta; } public function getSize($path) { $object = $this->getMetadata($path); $object['size'] = $object['content-length']; return $object; } public function getMimetype($path) { if( $object = $this->getMetadata($path)) $object['mimetype'] = $object['content-type']; return $object; } public function getTimestamp($path) { if( $object = $this->getMetadata($path)) $object['timestamp'] = strtotime( $object['last-modified'] ); return $object; } public function getVisibility($path) { $object = $this->applyPathPrefix($path); try { $acl = $this->client->getObjectAcl($this->bucket, $object); } catch (OssException $e) { $this->logErr(__FUNCTION__, $e); return false; } if ($acl == OssClient::OSS_ACL_TYPE_PUBLIC_READ ){ $res['visibility'] = AdapterInterface::VISIBILITY_PUBLIC; }else{ $res['visibility'] = AdapterInterface::VISIBILITY_PRIVATE; } return $res; } public function getUrl( $path ) { if (!$this->has($path)) throw new FileNotFoundException($filePath.' not found'); return ( $this->ssl ? 'https://' : 'http://' ) . ( $this->isCname ? ( $this->cdnDomain == '' ? $this->endPoint : $this->cdnDomain ) : $this->bucket . '.' . $this->endPoint ) . '/' . ltrim($path, '/'); } protected function getObjectACL($path) { $metadata = $this->getVisibility($path); return $metadata['visibility'] === AdapterInterface::VISIBILITY_PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE; } protected function normalizeResponse(array $object, $path = null) { $result = ['path' => $path ?: $this->removePathPrefix(isset($object['Key']) ? $object['Key'] : $object['Prefix'])]; $result['dirname'] = Util::dirname($result['path']); if (isset($object['LastModified'])) { $result['timestamp'] = strtotime($object['LastModified']); } if (substr($result['path'], -1) === '/') { $result['type'] = 'dir'; $result['path'] = rtrim($result['path'], '/'); return $result; } $result = array_merge($result, Util::map($object, static::$resultMap), ['type' => 'file']); return $result; } protected function getOptions(array $options = [], Config $config = null) { $options = array_merge($this->options, $options); if ($config) { $options = array_merge($options, $this->getOptionsFromConfig($config)); } return array(OssClient::OSS_HEADERS => $options); } protected function getOptionsFromConfig(Config $config) { $options = []; foreach (static::$metaOptions as $option) { if (! $config->has($option)) { continue; } $options[static::$metaMap[$option]] = $config->get($option); } if ($visibility = $config->get('visibility')) { $options['x-oss-object-acl'] = $visibility === AdapterInterface::VISIBILITY_PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE; } if ($mimetype = $config->get('mimetype')) { $options['Content-Type'] = $mimetype; } return $options; } protected function logErr($fun, $e){ if( $this->debug ){ Log::error($fun . ": FAILED"); Log::error($e->getMessage()); } } } 