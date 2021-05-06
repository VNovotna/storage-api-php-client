<?php

namespace Keboola\StorageApi\ABSUploader;

use GuzzleHttp\Promise\PromiseInterface;
use Keboola\StorageApi\ClientException;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;

class PromiseHelper
{
    /**
     * @param PromiseInterface[] $promises
     */
    public static function all($promises)
    {
        $rejected = [];
        foreach ($promises as $promise) {
            try {
                $promise->wait();
            } catch (ServiceException $e) {
                $rejected = $e;
            }
        }
        self::throwException($rejected);
    }

    /**
     * @param ServiceException[] $exceptions
     */
    private static function throwException($exceptions)
    {
        $message = 'Uploading to Azure blob storage failed: ';
        foreach ($exceptions as $e) {
            $message .= $e->getMessage();
        }

        throw new ClientException(
            $message,
            $e[0]->getCode(),
            $e[0]
        );
    }
}
