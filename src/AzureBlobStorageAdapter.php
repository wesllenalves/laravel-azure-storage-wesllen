<?php

declare(strict_types=1);

namespace Wesllenalves\LaravelAzureStorage;

use Illuminate\Support\Arr;
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter as BaseAzureBlobStorageAdapter;
use League\Flysystem\UnableToDeleteFile;
use Wesllenalves\LaravelAzureStorage\Exceptions\InvalidCustomUrl;
use Wesllenalves\LaravelAzureStorage\Exceptions\KeyNotSet;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\BlobSharedAccessSignatureHelper;
use MicrosoftAzure\Storage\Blob\Models\CreateContainerOptions;
use MicrosoftAzure\Storage\Blob\Models\PublicAccessType;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use Throwable;


/**
 * Blob storage adapter
 *
 * @internal
 */
final class AzureBlobStorageAdapter extends BaseAzureBlobStorageAdapter
{
    /**
     * The Azure Blob Client
     *
     * @var BlobRestProxy
     */
    private $client;

    /**
     * The container name
     *
     * @var string
     */
    private $container;

    /**
     * Custom url for getUrl()
     *
     * @var string|null
     */
    private $url;

    /**
     * The account key to access the storage
     *
     * @var string|null
     */
    private $key;

    /**
     * The prefix for the adapter
     *
     * @var string
     */
    private string $prefix;

    /**
     * Create a new AzureBlobStorageAdapter instance.
     *
     * @param \MicrosoftAzure\Storage\Blob\BlobRestProxy $client    Client.
     * @param string                                     $container Container.
     * @param string                                     $key
     * @param string|null                                $url       URL.
     * @param string                                     $prefix    Prefix.
     *
     * @throws InvalidCustomUrl URL is not valid.
     */
    public function __construct(
        BlobRestProxy $client,
        string $container,
        string $key = null,
        string $url = null,
        string $prefix = ''
    ) {
        parent::__construct($client, $container, $prefix);
        $this->client = $client;
        $this->container = $container;
        if ($url && !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidCustomUrl();
        }
        $this->url = $url;
        $this->key = $key;
        $this->prefix = $prefix;
    }

    /**
     * Get the file URL by given path.
     *
     * @param string $path Path.
     *
     * @return string
     */
    public function getUrl(string $path)
    {
        if ($this->url) {
            return rtrim($this->url, '/') . '/' . ($this->container === '$root' ? '' : $this->container . '/') . ($this->prefix ? $this->prefix . '/' : '') . ltrim($path, '/');
        }
        return $this->client->getBlobUrl($this->container, $path);
    }

    /**
     * Generate Temporary Url with SAS query
     *
     * @param string           $path
     * @param \DateTime|string $ttl
     * @param array            $options
     *
     * @return string
     */
    public function getTemporaryUrl(string $path, $ttl, array $options = [])
    {
        $path = $this->prefix ? $this->prefix . '/' . $path : $path;
        $resourceName = (empty($path) ? $this->container : $this->container  . '/' . $path);
        if (!$this->key) {
            throw new KeyNotSet();
        }
        $sas = new BlobSharedAccessSignatureHelper($this->client->getAccountName(), $this->key);
        $sasString = $sas->generateBlobServiceSharedAccessSignatureToken(
            (string)Arr::get($options, 'signed_resource', 'b'),
            $resourceName,
            (string)Arr::get($options, 'signed_permissions', 'r'),
            $ttl,
            (string)Arr::get($options, 'signed_start', ''),
            (string)Arr::get($options, 'signed_ip', ''),
            (string)Arr::get($options, 'signed_protocol', 'https'),
            (string)Arr::get($options, 'signed_identifier', ''),
            (string)Arr::get($options, 'cache_control', ''),
            (string)Arr::get($options, 'content_disposition', ''),
            (string)Arr::get($options, 'content_encoding', ''),
            (string)Arr::get($options, 'content_language', ''),
            (string)Arr::get($options, 'content_type', '')
        );

        return sprintf('%s?%s', $this->getUrl($path), $sasString);
    }

    /**
     * Generate Directory
     *
     * @param string     $path
     * @param array|null $options
     *
     * @return void
     */
    public function createDirectory(string $path, $options = null): void
    {
        $createContainerOptions = new CreateContainerOptions();
        $createContainerOptions->setPublicAccess(PublicAccessType::CONTAINER_AND_BLOBS);

        try {
            $this->client->createContainer($path, $createContainerOptions);
        } catch (Throwable $exception) {
            if ($exception instanceof ServiceException && $exception->getCode() === 404) {
                return;
            }

            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }
}
