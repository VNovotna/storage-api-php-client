<?php

namespace Keboola\StorageApi;

class DirectAccess
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function createCredentials($backend)
    {
        return $this->client->apiPost("storage/direct-access/{$backend}");
    }

    public function getCredentials($backend)
    {
        return $this->client->apiGet("storage/direct-access/{$backend}");
    }

    public function deleteCredentials($backend)
    {
        $this->client->apiDelete("storage/direct-access/{$backend}");
    }

    public function resetPassword($backend)
    {
        return $this->client->apiPost("storage/direct-access/{$backend}/reset-password");
    }

    public function enableForBucket($bucketId)
    {
        $url = "storage/buckets/" . $bucketId . "/direct-access";

        return $this->client->apiPost($url);
    }

    public function disableForBucket($bucketId)
    {
        $url = "storage/buckets/" . $bucketId . "/direct-access";

        return $this->client->apiDelete($url);
    }
}
