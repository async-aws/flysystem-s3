<?php

declare(strict_types=1);

namespace AsyncAws\Flysystem\S3;

use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\Core\Exception\InvalidArgument;
use AsyncAws\S3\Result\GetObjectOutput;
use AsyncAws\S3\Result\HeadObjectOutput;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client;
use AsyncAws\S3\ValueObject\AwsObject;
use AsyncAws\S3\ValueObject\CommonPrefix;
use AsyncAws\S3\ValueObject\ObjectIdentifier;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\CanOverwriteFiles;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;

class AsyncAwsS3Adapter extends AbstractAdapter implements CanOverwriteFiles
{
    public const PUBLIC_GRANT_URI = 'http://acs.amazonaws.com/groups/global/AllUsers';

    private const RESULT_MAP = [
        'Body' => 'contents',
        'ContentLength' => 'size',
        'ContentType' => 'mimetype',
        'Size' => 'size',
        'Metadata' => 'metadata',
        'StorageClass' => 'storageclass',
        'ETag' => 'etag',
        'VersionId' => 'versionid',
    ];

    private const META_OPTIONS = [
        'ACL',
        'CacheControl',
        'ContentDisposition',
        'ContentEncoding',
        'ContentLength',
        'ContentType',
        'Expires',
        'GrantFullControl',
        'GrantRead',
        'GrantReadACP',
        'GrantWriteACP',
        'Metadata',
        'RequestPayer',
        'SSECustomerAlgorithm',
        'SSECustomerKey',
        'SSECustomerKeyMD5',
        'SSEKMSKeyId',
        'ServerSideEncryption',
        'StorageClass',
        'Tagging',
        'WebsiteRedirectLocation',
    ];

    /**
     * @var S3Client
     */
    private $client;

    /**
     * @var string
     */
    private $bucket;

    /**
     * @var array
     */
    private $options = [];

    public function __construct(S3Client $client, string $bucket, string $prefix = '', array $options = [])
    {
        $this->client = $client;
        $this->bucket = $bucket;
        $this->setPathPrefix($prefix);
        $this->options = $options;
    }

    public function getClient(): S3Client
    {
        return $this->client;
    }

    /**
     * Get the S3Client bucket.
     */
    public function getBucket(): string
    {
        return $this->bucket;
    }

    /**
     * {@inheritdoc}
     *
     * @return array|false
     */
    public function write($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     *
     * @return array|false
     */
    public function update($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        if (!$this->copy($path, $newpath)) {
            return false;
        }

        return $this->delete($path);
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function delete($path)
    {
        $location = $this->applyPathPrefix($path);

        $result = $this->client->deleteObject(
            [
                'Bucket' => $this->bucket,
                'Key' => $location,
            ]
        );

        $result->resolve();

        return !$this->has($path);
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $prefix = $this->applyPathPrefix($dirname) . '/';

        $objects = [];
        $params = ['Bucket' => $this->bucket, 'Prefix' => $prefix];
        $result = $this->client->listObjectsV2($params);
        /** @var AwsObject $item */
        foreach ($result->getContents() as $item) {
            $key = $item->getKey();
            if (null !== $key) {
                $objects[] = new ObjectIdentifier(['Key' => $key]);
            }
        }

        if (empty($objects)) {
            return true;
        }

        $this->client->deleteObjects(['Bucket' => $this->bucket, 'Delete' => ['Objects' => $objects]])->resolve();

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        return $this->upload($dirname . '/', '', $config);
    }

    /**
     * {@inheritdoc}
     *
     * @return array|bool|null
     */
    public function has($path)
    {
        $location = $this->applyPathPrefix($path);

        $result = $this->client->objectExists(array_merge($this->options, [
            'Bucket' => $this->bucket,
            'Key' => $location,
        ]));

        return $result->isSuccess();
    }

    /**
     * {@inheritdoc}
     *
     * @return array|false
     */
    public function read($path)
    {
        $response = $this->readObject($path);

        if (false !== $response) {
            $response['contents'] = $response['contents']->getContentAsString();
        }

        return $response;
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $prefix = $this->applyPathPrefix(rtrim($directory, '/') . '/');
        $options = ['Bucket' => $this->bucket, 'Prefix' => ltrim($prefix, '/')];

        if (false === $recursive) {
            $options['Delimiter'] = '/';
        }

        $listing = $this->retrievePaginatedListing($options);
        $normalizer = [$this, 'normalizeResponse'];
        $normalized = array_map($normalizer, $listing);

        return Util::emulateDirectories($normalized);
    }

    /**
     * {@inheritdoc}
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
        $result = $this->client->headObject([
            'Bucket' => $this->bucket,
            'Key' => $this->applyPathPrefix($path),
        ] + $this->options
        );

        try {
            $result->resolve();
        } catch (ClientException $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return false;
            }

            throw $exception;
        }

        return $this->normalizeResponse($result, $path);
    }

    /**
     * {@inheritdoc}
     *
     * @return array|false
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     *
     * @return array|false
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     *
     * @return array|false
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     *
     * @return array|false
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $result = $this->client->copyObject([
            'Bucket' => $this->bucket,
            'Key' => $this->applyPathPrefix($newpath),
            'CopySource' => $this->bucket . '/' . $this->applyPathPrefix($path),
            'ACL' => AdapterInterface::VISIBILITY_PUBLIC === $this->getRawVisibility($path) ? 'public-read' : 'private',
        ] + $this->options
        );

        try {
            $result->resolve();
        } catch (ClientException $e) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @return array|false
     */
    public function readStream($path)
    {
        $response = $this->readObject($path);

        if (\is_array($response) && isset($response['contents'])) {
            $response['stream'] = $response['contents']->getContentAsResource();
            unset($response['contents']);
        }

        return $response;
    }

    /**
     * {@inheritdoc}
     *
     * @return array|false
     */
    public function setVisibility($path, $visibility)
    {
        $result = $this->client->putObjectAcl([
            'Bucket' => $this->bucket,
            'Key' => $this->applyPathPrefix($path),
            'ACL' => AdapterInterface::VISIBILITY_PUBLIC === $visibility ? 'public-read' : 'private',
        ]);

        try {
            $result->resolve();
        } catch (ClientException $exception) {
            return false;
        }

        return compact('path', 'visibility');
    }

    /**
     * {@inheritdoc}
     *
     * @return array|false
     */
    public function getVisibility($path)
    {
        $rawVisibility = $this->getRawVisibility($path);

        return ['visibility' => $rawVisibility];
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function applyPathPrefix($path)
    {
        return ltrim(parent::applyPathPrefix($path), '/');
    }

    /**
     * {@inheritdoc}
     */
    public function setPathPrefix($prefix): void
    {
        $prefix = ltrim($prefix, '/');

        parent::setPathPrefix($prefix);
    }

    /**
     * Read an object and normalize the response.
     *
     * @return array|false
     */
    protected function readObject(string $path)
    {
        $options = [
            'Bucket' => $this->bucket,
            'Key' => $this->applyPathPrefix($path),
        ];

        if (isset($this->options['@http'])) {
            $options['@http'] = $this->options['@http'];
        }

        $result = $this->client->getObject($options + $this->options);

        try {
            $result->resolve();
        } catch (ClientException $e) {
            return false;
        }

        return $this->normalizeResponse($result, $path);
    }

    /**
     * Get the object acl presented as a visibility.
     */
    protected function getRawVisibility(string $path): string
    {
        $result = $this->client->getObjectAcl([
            'Bucket' => $this->bucket,
            'Key' => $this->applyPathPrefix($path),
        ]);

        $result->resolve();
        $visibility = AdapterInterface::VISIBILITY_PRIVATE;

        foreach ($result->getGrants() as $grant) {
            if (null === $grantee = $grant->getGrantee()) {
                continue;
            }

            if (
                null !== $grantee->getURI()
                && self::PUBLIC_GRANT_URI === $grantee->getURI()
                && 'READ' === $grant->getPermission()
            ) {
                $visibility = AdapterInterface::VISIBILITY_PUBLIC;

                break;
            }
        }

        return $visibility;
    }

    /**
     * Upload an object.
     *
     * @param string|resource $body
     *
     * @return array|false
     */
    protected function upload(string $path, $body, Config $config)
    {
        $key = $this->applyPathPrefix($path);
        $options = $this->getOptionsFromConfig($config);
        $acl = \array_key_exists('ACL', $options) ? $options['ACL'] : 'private';

        if (!$this->isOnlyDir($path)) {
            if (!isset($options['ContentType'])) {
                $options['ContentType'] = Util::guessMimeType($path, $body);
            }

            if (!isset($options['ContentLength'])) {
                $options['ContentLength'] = \is_resource($body) ? Util::getStreamSize($body) : Util::contentSize($body);
            }

            if (null === $options['ContentLength']) {
                unset($options['ContentLength']);
            }
        }

        $result = $this->client->putObject(array_merge($options, [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $body,
            'ACL' => $acl,
        ]));

        try {
            $result->resolve();
        } catch (ClientException $e) {
            return false;
        }

        return $this->normalizeResponse($result, $path);
    }

    /**
     * Get options from the config.
     */
    protected function getOptionsFromConfig(Config $config): array
    {
        $options = $this->options;

        if ($visibility = $config->get('visibility')) {
            // For local reference
            $options['visibility'] = $visibility;
            // For external reference
            $options['ACL'] = AdapterInterface::VISIBILITY_PUBLIC === $visibility ? 'public-read' : 'private';
        }

        if ($mimetype = $config->get('mimetype')) {
            // For local reference
            $options['mimetype'] = $mimetype;
            // For external reference
            $options['ContentType'] = $mimetype;
        }

        foreach (self::META_OPTIONS as $option) {
            if (!$config->has($option)) {
                continue;
            }
            $options[$option] = $config->get($option);
        }

        return $options;
    }

    /**
     * Normalize the object result array.
     *
     * @param AwsObject|CommonPrefix|HeadObjectOutput|GetObjectOutput|PutObjectOutput $output
     */
    protected function normalizeResponse(object $output, ?string $path = null): array
    {
        if (empty($path)) {
            if ($output instanceof AwsObject) {
                $path = $output->getKey();
            } elseif ($output instanceof CommonPrefix) {
                $path = $output->getPrefix();
            }
            if (empty($path)) {
                throw new InvalidArgument('The provided path should not be null');
            }

            $path = $this->removePathPrefix($path);
        }
        $result = [
            'path' => $path,
        ];
        $result = array_merge($result, Util::pathinfo($result['path']));

        if (method_exists($output, 'getLastModified')) {
            $dateTime = $output->getLastModified();
            if (null !== $dateTime) {
                $result['timestamp'] = $dateTime->getTimestamp();
            }
        }

        if ($this->isOnlyDir($path)) {
            $result['type'] = 'dir';
            $result['path'] = rtrim($path, '/');

            return $result;
        }

        return array_merge($result, $this->resultMap($output), ['type' => 'file']);
    }

    private function retrievePaginatedListing(array $options): array
    {
        $result = $this->client->listObjectsV2($options);
        $listing = [];

        /** @var AwsObject|CommonPrefix $single */
        foreach ($result->getIterator() as $single) {
            $listing[] = $single;
        }

        return $listing;
    }

    /**
     * Check if the path contains only directories.
     */
    private function isOnlyDir(string $path): bool
    {
        return '/' === substr($path, -1);
    }

    private function resultMap(object $object): array
    {
        $result = [];
        foreach (self::RESULT_MAP as $from => $to) {
            $methodName = 'get' . $from;
            if (method_exists($object, $methodName)) {
                $result[$to] = \call_user_func([$object, $methodName]);
            }
        }

        return $result;
    }
}
